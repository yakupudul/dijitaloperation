<?php

namespace App\Services\AiTasks;

use App\Ai\Contracts\RegistryPrompted;
use App\Models\AiTask;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use DateTimeInterface;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\ObjectSchema;

/**
 * AI iş kuyruğu (docs: MCP yol haritası, Faz 1). An operation whose current prompt version picks "Claude (MCP)" is
 * not sent to a provider: inside a resumable job (begin()) each agent call of the run becomes an ai_tasks row, the
 * run ends "queued", and when Claude has answered every open call of that run over MCP the job is dispatched again;
 * the re-run gets each answer back by its call (operation + input hash, so a re-run that skips calls it already
 * stored, like a multi-step Eşleştir, still finds the right answer) and continues exactly like a provider
 * response. Outside such a job (an inline call from a page or a command) a delegated operation never falls back to
 * the provider route (operator decision 2026-10-03: delegated work stays with Claude); the call fails instead. Scoped: the open run lives in this instance (one per request / job), so it never
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

    /**
     * Operations whose callers handle "queued" (wait, keep their progress, continue with Claude's answer). Any other
     * site operation keeps the provider route until its caller is taught to wait.
     */
    public const array SUPPORTED = [
        AiRouteKeys::SITE_WRITE_ARTICLE,
        AiRouteKeys::SITE_CONTENT_RECIPE,
        AiRouteKeys::SITE_CLUSTER_MATCH,
        AiRouteKeys::SITE_CLUSTER_GAPS,
        AiRouteKeys::QUERIES_AI_QUERIES,
        AiRouteKeys::SITE_PAGE_CATEGORIES,
        AiRouteKeys::SITE_SERVICE_PAGES,
        AiRouteKeys::SITE_CLUSTER_PAGES,
        AiRouteKeys::SITE_IMAGE_ALTS,
        AiRouteKeys::QUERIES_CLUSTER,
        AiRouteKeys::QUERIES_CLUSTER_REVIEW,
        AiRouteKeys::QUERIES_PLAN_SECTORS,
        AiRouteKeys::QUERIES_PLAN_SERVICES,
        AiRouteKeys::QUERIES_PLAN_FILTERS,
        AiRouteKeys::QUERIES_SCAN_FILTERS,
        AiRouteKeys::QUERIES_FILTER_RULES,
        AiRouteKeys::QUERIES_ASSIGN_SERVICES,
        AiRouteKeys::BRAND_SERVICES,
        AiRouteKeys::BRAND_CANDIDATES,
        AiRouteKeys::BRAND_SETUP,
        AiRouteKeys::GBP_PROFILE_PLAN,
        AiRouteKeys::GBP_POST_QUEUE,
        AiRouteKeys::GBP_BRANCH_PAGE,
    ];

    /** Whether the operation runs through a resumable path that can wait for Claude (structured registry agents in SUPPORTED). */
    public function supports(string $operation): bool
    {
        $agent = $this->registry->definitions()[$operation]['agent'] ?? null;

        return in_array($operation, self::SUPPORTED, true) && is_string($agent)
            && is_subclass_of($agent, RegistryPrompted::class) && is_subclass_of($agent, HasStructuredOutput::class);
    }

    /** Whether the operation's current prompt version delegates it to Claude (and the MCP server is configured). */
    public function delegated(string $operation): bool
    {
        return self::enabled() && $this->registry->current($operation)->model === self::MODEL && $this->supports($operation);
    }

    /**
     * A delegated operation called outside a resumable run (inline from a page or a command): it must not use the
     * provider route, and the caller reports it as failed.
     */
    public function blocksInline(string $operation): bool
    {
        if ($this->run !== null || ! $this->delegated($operation)) {
            return false;
        }
        Log::info('Delegated AI operation called outside a job; provider route not used.', ['operation' => $operation]);

        return true;
    }

    /** Starts a resumable run: the job is dispatched again once Claude has answered its delegated calls. */
    public function begin(object $job, ?int $brandId = null, ?string $subject = null): void
    {
        $resume = serialize($job);
        $this->run = ['resume' => $resume, 'key' => self::key($resume), 'brand_id' => $brandId,
            'subject' => $subject !== null ? mb_substr($subject, 0, 200) : null, 'sequence' => 0];
    }

    /**
     * Whether Claude has not answered a delegated call of one of these jobs' runs yet (a pending or claimed row under
     * the resume key begin() gives the job): the run is still under way however long Claude takes. With $answeredSince
     * an answer given after it that no run has taken yet counts too (the job dispatched again waits on the queue).
     *
     * @param  list<object>  $jobs
     */
    public static function waits(array $jobs, ?DateTimeInterface $answeredSince = null): bool
    {
        if ($jobs === []) {
            return false;
        }

        return AiTask::query()->whereIn('resume_key', array_map(fn (object $job): string => self::key(serialize($job)), $jobs))
            ->where(fn ($q) => $q->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
                ->when($answeredSince !== null, fn ($q) => $q->orWhere(fn ($answered) => $answered
                    ->whereIn('status', [AiTask::DONE, AiTask::FAILED])->where('completed_at', '>', $answeredSince))))
            ->exists();
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
     * A caller whose pack can drift while it waits (live metrics, a growing library) names the call with $slot (stable
     * within the run, e.g. "batch-3" of a stored list): the answer is then found by the slot, not by the input.
     *
     * @param  array<string, mixed>  $data  the DATA_JSON pack
     * @return array{status: string, data: array<string, mixed>, prompt_version_id: ?int}|null
     */
    public function answer(Agent&RegistryPrompted&HasStructuredOutput $agent, array $data, ?string $slot = null, string $label = 'DATA_JSON'): ?array
    {
        if ($this->run === null) {
            return null;
        }
        $run = $this->run;
        $key = $run['key'];
        $sequence = $this->run['sequence'] = $run['sequence'] + 1;
        $operation = $agent->promptOperation();
        $versionId = $agent->promptVersionId();
        $input = $label."\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $operation."\n".($slot !== null ? 'slot:'.$slot : $input));
        $task = AiTask::query()->where('resume_key', $key)->where('operation', $operation)->where('input_hash', $hash)
            ->where('status', '!=', AiTask::CONSUMED)->latest('id')->first();
        if ($task === null) {
            AiTask::query()->create([
                'operation' => $operation, 'brand_id' => $run['brand_id'] ?? null, 'subject' => $run['subject'] ?? null,
                'resume_key' => $key, 'sequence' => $sequence, 'input_hash' => $hash, 'status' => AiTask::PENDING, 'prompt_version_id' => $versionId,
                'instructions' => (string) $agent->instructions(),
                'input' => $input,
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
     * The delegated call for callers that report a failed call as a status string: the structured output, 'queued'
     * (waiting for Claude), 'error' (Claude could not do it, or an inline call outside a resumable run), or null when
     * the operation is not delegated (the caller keeps the provider route).
     *
     * @param  array<string, mixed>  $data
     * @param  string  $label  the pack's name the operation's prompt reads (DATA_JSON, CONTEXT_JSON)
     * @return array<string, mixed>|string|null
     */
    public function delegatedCall(Agent&RegistryPrompted&HasStructuredOutput $agent, array $data, ?string $slot = null, string $label = 'DATA_JSON'): array|string|null
    {
        if (! $this->delegated($agent->promptOperation())) {
            return null;
        }
        $answer = $this->answer($agent, $data, $slot, $label);
        if ($answer === null) {
            return $this->blocksInline($agent->promptOperation()) ? 'error' : null;
        }

        return match ($answer['status']) {
            'ready' => $answer['data'],
            'queued' => 'queued',
            default => 'error',
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
