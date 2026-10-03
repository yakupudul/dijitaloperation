<?php

namespace App\Services\AiTasks;

use App\Ai\Agents\Site\SiteAgent;
use App\Ai\Contracts\RegistryPrompted;
use App\Models\AiTask;
use App\Services\Prompts\PromptRegistry;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\ObjectSchema;

/**
 * AI iş kuyruğu (docs: MCP yol haritası, Faz 1). An operation whose current prompt version picks "Claude (MCP)" is
 * not sent to a provider: inside a resumable job (begin()) each agent call of the run becomes an ai_tasks row, the
 * run ends "queued", and when Claude has answered every open call of that run over MCP the job is dispatched again;
 * the re-run gets each answer back in call order and continues exactly like a provider response. Outside such a job
 * the call keeps the provider route. Scoped: the open run lives in this instance (one per request / job), so it never
 * leaks into jobs dispatched during the run.
 */
final class AiTaskQueue
{
    /** Model value of a prompt version whose operation is delegated to Claude over MCP. */
    public const string MODEL = 'claude_mcp:abonelik';

    public const string PROVIDER = 'claude_mcp';

    /** @var array{resume: string, key: string, brand_id: ?int, subject: ?string, sequence: int}|null the open run */
    private ?array $run = null;

    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly OutputSchemaValidator $validator,
    ) {}

    public static function enabled(): bool
    {
        return trim((string) config('moxdop-mcp.token')) !== '';
    }

    /** Whether the operation runs through a resumable path that can wait for Claude (Faz 1: website-screen agents). */
    public function supports(string $operation): bool
    {
        $agent = $this->registry->definitions()[$operation]['agent'] ?? null;

        return is_string($agent) && is_subclass_of($agent, SiteAgent::class);
    }

    /** Whether the operation's current prompt version delegates it to Claude (and the MCP server is configured). */
    public function delegated(string $operation): bool
    {
        return self::enabled() && $this->registry->current($operation)->model === self::MODEL && $this->supports($operation);
    }

    /** Starts a resumable run: the job is dispatched again once Claude has answered its delegated calls. */
    public function begin(object $job, ?int $brandId = null, ?string $subject = null): void
    {
        $resume = serialize($job);
        $this->run = ['resume' => $resume, 'key' => self::key($resume), 'brand_id' => $brandId,
            'subject' => $subject !== null ? mb_substr($subject, 0, 200) : null, 'sequence' => 0];
    }

    /**
     * Ends the run: when nothing of it waits for Claude any more, its answers are used up (a later run of the same
     * job asks again).
     */
    public function settle(): void
    {
        $run = $this->run;
        $this->run = null;
        if ($run === null) {
            return;
        }
        $key = $run['key'];
        if (! AiTask::query()->where('resume_key', $key)->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->exists()) {
            AiTask::query()->where('resume_key', $key)->whereIn('status', [AiTask::DONE, AiTask::FAILED])
                ->update(['status' => AiTask::CONSUMED, 'consumed_at' => now()]);
        }
    }

    /**
     * The delegated call's answer: ready (Claude answered this call of the run), queued (waiting for Claude), error
     * (Claude could not do it), or null when no resumable run is open (the caller keeps the provider route).
     *
     * @param  array<string, mixed>  $data  the DATA_JSON pack
     * @return array{status: string, data: array<string, mixed>, prompt_version_id: ?int}|null
     */
    public function answer(Agent&RegistryPrompted&HasStructuredOutput $agent, array $data): ?array
    {
        if ($this->run === null) {
            return null;
        }
        $run = $this->run;
        $key = $run['key'];
        $sequence = $this->run['sequence'] = $run['sequence'] + 1;
        $operation = $agent->promptOperation();
        $versionId = $agent->promptVersionId();
        $task = AiTask::query()->where('resume_key', $key)->where('sequence', $sequence)->where('operation', $operation)
            ->where('status', '!=', AiTask::CONSUMED)->latest('id')->first();
        if ($task === null) {
            AiTask::query()->create([
                'operation' => $operation, 'brand_id' => $run['brand_id'] ?? null, 'subject' => $run['subject'] ?? null,
                'resume_key' => $key, 'sequence' => $sequence, 'status' => AiTask::PENDING, 'prompt_version_id' => $versionId,
                'instructions' => (string) $agent->instructions(),
                'input' => 'DATA_JSON'."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                'output_schema' => self::schemaOf($agent), 'resume' => $run['resume'],
            ]);

            return ['status' => 'queued', 'data' => [], 'prompt_version_id' => null];
        }

        return match ($task->status) {
            AiTask::DONE => ['status' => 'ready', 'data' => (array) $task->output, 'prompt_version_id' => $task->prompt_version_id],
            AiTask::FAILED => ['status' => 'error', 'data' => [], 'prompt_version_id' => null],
            default => ['status' => 'queued', 'data' => [], 'prompt_version_id' => null],
        };
    }

    /**
     * Claude's answer: checked against the task's schema; stored when valid, and the asking job is dispatched again
     * once nothing else of its run waits.
     *
     * @return list<string> validation errors (empty = stored)
     */
    public function submit(AiTask $task, mixed $output): array
    {
        if (! $task->isOpen()) {
            return ['Bu iş artık açık değil (durum: '.$task->status.').'];
        }
        $errors = $this->validator->errors($output, (array) $task->output_schema);
        if ($errors !== []) {
            $task->forceFill(['attempts' => $task->attempts + 1, 'error' => implode("\n", $errors)])->save();

            return $errors;
        }
        $task->forceFill(['status' => AiTask::DONE, 'output' => $output, 'error' => null, 'completed_at' => now()])->save();
        $this->resumeWhenAnswered($task);

        return [];
    }

    /** Claude could not do the task: the run continues with an error for this call (the operator sees it). */
    public function fail(AiTask $task, string $reason): bool
    {
        if (! $task->isOpen()) {
            return false;
        }
        $task->forceFill(['status' => AiTask::FAILED, 'error' => mb_substr($reason, 0, 2000), 'completed_at' => now()])->save();
        $this->resumeWhenAnswered($task);

        return true;
    }

    public function claim(AiTask $task): void
    {
        if ($task->status === AiTask::PENDING) {
            $task->forceFill(['status' => AiTask::CLAIMED, 'claimed_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    public static function schemaOf(HasStructuredOutput $agent): array
    {
        return (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();
    }

    private function resumeWhenAnswered(AiTask $task): void
    {
        $dispatch = DB::transaction(function () use ($task): bool {
            $open = AiTask::query()->where('resume_key', $task->resume_key)->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->lockForUpdate()->exists();

            return ! $open;
        });
        if (! $dispatch) {
            return;
        }
        $job = unserialize($task->resume);
        if (is_object($job)) {
            dispatch($job);
        }
    }

    private static function key(string $resume): string
    {
        return hash('sha256', $resume);
    }
}
