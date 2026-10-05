<?php

namespace App\Services\Ai;

use App\Ai\Contracts\RegistryPrompted;
use App\Models\AiLiveOperation;
use App\Services\AiJobs\AiJobTracker;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Canlı AI işlemleri / AI işleri: opens a row when any laravel/ai agent call starts (PromptingAgent / StreamingAgent) and
 * closes it when the call returns (AgentPrompted / AgentStreamed → done) or fails (AgentFailedOver, or the call's
 * exception — rows still open when the request / job / command ends are closed as failed with the last provider error
 * seen). The row keeps a capped copy of the input and the output, the prompt version, provider / model and tokens; a
 * call inside a tracked queued job points at the job's row (AiJobTracker).
 * Hooked on the framework events only; no call site changes. Never throws — except AiCancelledException when the
 * operator stopped the call or its job ("Durdur"): live status must not break an AI workflow.
 * Scoped: one instance per request / job.
 */
final class AiLiveOperations
{
    /** Hidden context: short subject a caller may set for the live list (e.g. the brand name). */
    public const string SUBJECT_CONTEXT = 'ai_live_subject';

    /** Hidden context: the operator who started the work (propagates into queued jobs). */
    public const string USER_CONTEXT = 'ai_user_id';

    /** A running call row older than this is closed as failed (the process died). */
    public const int STALE_MINUTES = 30;

    /** The header indicator stays visible this long after the last call finished. */
    public const int RECENT_MINUTES = 5;

    /** Longest stored input / output copy (bytes). */
    public const int TEXT_MAX_BYTES = 65536;

    /** running() sweeps stale rows at most this often (the header indicator polls it on every page). */
    public const int SWEEP_EVERY_SECONDS = 60;

    private const string SWEEP_CACHE_KEY = 'ai-live:sweep-stale';

    /** @var array<string, int> invocation id => row id */
    private array $byInvocation = [];

    /** @var array<int, int> agent object id => row id of its current attempt */
    private array $byAgent = [];

    private ?string $lastError = null;

    private ?bool $ready = null;

    public function __construct(private readonly AiPricing $pricing, private readonly AiJobTracker $jobs) {}

    /**
     * A call is about to start. Inside a job the operator stopped, it never starts (AiCancelledException): the job's
     * next AI call is where a stop takes effect even when the job has no explicit check.
     */
    public function started(PromptingAgent $event): void
    {
        if ($this->jobs->cancelRequested()) {
            throw new AiCancelledException;
        }
        $this->guardBudget($event);
        try {
            if (! $this->ready()) {
                return;
            }
            $agent = $event->prompt->agent;
            $trial = Context::getHidden(AiUsageRecorder::TRIAL_CONTEXT) === true;
            $operation = $trial ? AiUsageRecorder::TRIAL_ROUTE : AiUsageRecorder::routeKeyFor($agent);
            $parentId = $this->jobs->currentRowId();
            $parent = $parentId !== null ? AiLiveOperation::query()->find($parentId, ['id', 'user_id', 'subject', 'link']) : null;
            $user = auth()->id() ?? Context::getHidden(self::USER_CONTEXT) ?? $parent?->user_id;
            $job = $this->jobs->current();
            $row = AiLiveOperation::query()->create([
                'kind' => AiLiveOperation::KIND_CALL,
                'parent_id' => $parent?->id,
                'job_uuid' => $job !== null && $job['uuid'] !== '' ? mb_substr($job['uuid'], 0, 64) : null,
                'job_class' => $job !== null ? mb_substr($job['class'], 0, 190) : null,
                'invocation_id' => mb_substr($event->invocationId, 0, 64),
                'operation' => $operation !== null ? mb_substr($operation, 0, 120) : null,
                'label' => mb_substr(AiOperationLabels::for($operation, class_basename($agent)), 0, 190),
                'agent' => mb_substr(class_basename($agent), 0, 190),
                'status' => AiLiveOperation::RUNNING,
                'user_id' => is_numeric($user) ? (int) $user : null,
                'subject' => $this->subject($event->prompt->prompt) ?? $parent?->subject,
                'link' => $parent?->link,
                'prompt_version_id' => $this->promptVersionId($agent),
                'provider' => $this->providerName($event),
                'model' => is_string($event->prompt->model) && $event->prompt->model !== '' ? mb_substr($event->prompt->model, 0, 190) : null,
                'input_text' => self::cap($event->prompt->prompt),
                'started_at' => now(),
            ]);
            $this->byInvocation[$event->invocationId] = (int) $row->id;
            $this->byAgent[spl_object_id($agent)] = (int) $row->id;
            $this->lastError = null;
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be opened.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * The last gate before money is spent: past the day's ceiling, or work nobody clicked outside Sorgular, the call
     * never starts (AiBudgetExceededException; the caller's error path runs, nothing is paid).
     */
    private function guardBudget(PromptingAgent $event): void
    {
        $operation = Context::getHidden(AiUsageRecorder::TRIAL_CONTEXT) === true ? AiUsageRecorder::TRIAL_ROUTE : AiUsageRecorder::routeKeyFor($event->prompt->agent);
        $model = is_string($event->prompt->model) && $event->prompt->model !== '' ? $event->prompt->model : null;
        try {
            $reason = app(AiBudget::class)->blockReason($this->providerName($event), $model, $operation);
        } catch (Throwable) {
            return;
        }
        if ($reason !== null) {
            Log::info('AI call blocked by the spend guard.', ['operation' => $operation, 'reason' => $reason]);

            throw new AiBudgetExceededException($reason);
        }
    }

    /**
     * A call returned: the row keeps the output, tokens and cost. When the operator stopped the call (or its job) while
     * it ran, the row becomes "Durduruldu" and the result is discarded: AiCancelledException reaches the caller instead
     * of the response.
     */
    public function finished(AgentPrompted $event): void
    {
        $id = $this->byInvocation[$event->invocationId] ?? null;
        if ($id === null) {
            return;
        }
        unset($this->byInvocation[$event->invocationId], $this->byAgent[spl_object_id($event->prompt->agent)]);
        $cancelled = false;
        try {
            $usage = $event->response->usage;
            $provider = (string) ($event->response->meta->provider ?? 'unknown');
            $model = (string) ($event->response->meta->model ?? 'unknown');
            $listCost = $this->pricing->cost($provider, $model, $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens);
            // OpenAI ücretsiz paylaşım kotası: the tokens inside today's quota are not billed.
            $quota = app(OpenAiFreeQuota::class);
            [$cost, $free] = $quota->bill($provider, $model, max(0, $usage->promptTokens) + max(0, $usage->completionTokens), $listCost, $id);
            $cancelled = AiLiveOperation::query()->whereKey($id)->whereNotNull('cancel_requested_at')->exists() || $this->jobs->cancelRequested();
            $this->close($id, $cancelled ? AiLiveOperation::CANCELLED : AiLiveOperation::DONE, $cancelled ? 'Durduruldu; bu çağrının sonucu kullanılmadı.' : null, $cost, [
                ...($quota->enabled() ? ['list_cost_usd' => $listCost, 'free_tokens' => $free] : []),
                'provider' => $provider !== 'unknown' ? mb_substr($provider, 0, 48) : null,
                'model' => $model !== 'unknown' ? mb_substr($model, 0, 190) : null,
                'input_tokens' => max(0, $usage->promptTokens),
                'output_tokens' => max(0, $usage->completionTokens),
                'output_text' => self::cap(self::output($event->response)),
            ]);
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be closed.', ['error' => $exception->getMessage()]);
        }
        if ($cancelled) {
            throw new AiCancelledException('AI çağrısı durduruldu; sonucu kullanılmadı.');
        }
    }

    /** One provider attempt failed over to the next provider (the next attempt opens its own row). */
    public function failedOver(AgentFailedOver $event): void
    {
        $id = $this->byAgent[spl_object_id($event->agent)] ?? null;
        if ($id === null) {
            return;
        }
        unset($this->byAgent[spl_object_id($event->agent)]);
        $this->byInvocation = array_filter($this->byInvocation, fn (int $row): bool => $row !== $id);
        $this->close($id, AiLiveOperation::FAILED, $event->provider->name().' yanıt vermedi: '.$event->exception->getMessage());
    }

    /** Remembers the last failed provider HTTP answer while a call is open (the error text of a failed call). */
    public function httpResponse(ResponseReceived|ConnectionFailed $event): void
    {
        if ($this->byInvocation === []) {
            return;
        }
        if ($event instanceof ConnectionFailed) {
            $this->lastError = 'Sağlayıcıya bağlanılamadı.';

            return;
        }
        if ($event->response->failed()) {
            $this->lastError = 'Sağlayıcı yanıtı: HTTP '.$event->response->status();
        }
    }

    /**
     * Closes every call this process opened and never finished (the call threw): request / job / command end.
     */
    public function closeOpen(?string $error = null): void
    {
        if ($this->byInvocation === []) {
            return;
        }
        $ids = array_values($this->byInvocation);
        $this->byInvocation = [];
        $this->byAgent = [];
        $message = $error !== null && trim($error) !== '' ? $error : ($this->lastError ?? 'AI yanıtı alınamadı.');
        $this->lastError = null;
        foreach ($ids as $id) {
            $this->close($id, AiLiveOperation::FAILED, $message);
        }
    }

    /** Running AI work (top level: jobs and calls outside a tracked job; oldest first). Stale rows are swept first, at most once a minute. */
    public function running(): Collection
    {
        if ($this->sweepDue()) {
            $this->sweepStale();
        }

        return AiLiveOperation::query()->whereNull('parent_id')->where('status', AiLiveOperation::RUNNING)
            ->orderBy('started_at')->orderBy('id')->limit(50)->get();
    }

    /** Jobs waiting in the queue (oldest first). */
    public function queued(int $limit = 20): Collection
    {
        return AiLiveOperation::query()->where('status', AiLiveOperation::QUEUED)->orderBy('queued_at')->orderBy('id')->limit($limit)->get();
    }

    /** Last finished AI work (top level, newest first). */
    public function finishedRecently(int $limit = 10): Collection
    {
        return AiLiveOperation::query()->whereNull('parent_id')->whereNotIn('status', [AiLiveOperation::RUNNING, AiLiveOperation::QUEUED])
            ->orderByDesc('finished_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** Whether AI work waits in the queue or finished within the recent window (the header indicator stays visible). */
    public function hasRecent(): bool
    {
        return AiLiveOperation::query()->whereNull('parent_id')->where(fn (Builder $query) => $query->where('status', AiLiveOperation::QUEUED)
            ->orWhere(fn (Builder $query) => $query->where('status', '!=', AiLiveOperation::RUNNING)->where('finished_at', '>=', now()->subMinutes(self::RECENT_MINUTES))))
            ->exists();
    }

    /** Closes rows whose process died long ago. */
    public function sweepStale(): void
    {
        try {
            AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_CALL)->where('status', AiLiveOperation::RUNNING)
                ->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))
                ->whereNotIn('id', array_values($this->byInvocation))
                ->update(['status' => AiLiveOperation::FAILED, 'error' => 'Süreç yarıda kaldı (zaman aşımı).', 'finished_at' => now()]);
        } catch (Throwable) {
            // Display only.
        }
        AiJobTracker::sweepStale();
    }

    /** Whether the stale sweep is due (once per SWEEP_EVERY_SECONDS across processes; due when the cache fails). */
    private function sweepDue(): bool
    {
        try {
            return Cache::add(self::SWEEP_CACHE_KEY, true, self::SWEEP_EVERY_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }

    /** The first TEXT_MAX_BYTES of a text (valid UTF-8), null when empty. */
    public static function cap(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        if (strlen($text) <= self::TEXT_MAX_BYTES) {
            return $text;
        }

        return mb_strcut($text, 0, self::TEXT_MAX_BYTES - 64, 'UTF-8')."\n… (kısaltıldı: ilk 64 KB)";
    }

    /** @param  array<string, mixed>  $extra */
    private function close(int $id, string $status, ?string $error, ?float $cost = null, array $extra = []): void
    {
        try {
            $row = AiLiveOperation::query()->find($id);
            if ($row === null || ! $row->isRunning()) {
                return;
            }
            $row->forceFill([
                'status' => $status,
                'error' => $error !== null ? Str::limit(trim(preg_replace('/\s+/u', ' ', $error) ?? ''), 290) : null,
                'cost_usd' => $cost,
                'duration_ms' => max(0, (int) abs($row->started_at?->diffInMilliseconds(now()) ?? 0)),
                'finished_at' => now(),
                ...$extra,
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be closed.', ['error' => $exception->getMessage()]);
        }
    }

    /** Structured output as pretty JSON, else the text answer. */
    private static function output(object $response): string
    {
        if ($response instanceof StructuredAgentResponse) {
            return (string) json_encode($response->structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return (string) ($response->text ?? '');
    }

    private function promptVersionId(object $agent): ?int
    {
        try {
            return $agent instanceof RegistryPrompted ? $agent->promptVersionId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function providerName(PromptingAgent $event): ?string
    {
        try {
            $provider = $event->prompt->provider;

            return is_object($provider) && method_exists($provider, 'name') ? mb_substr((string) $provider->name(), 0, 48) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Caller-set subject, else the start of a plain-text prompt (data payloads are not shown). */
    private function subject(string $prompt): ?string
    {
        $subject = Context::getHidden(self::SUBJECT_CONTEXT);
        if (! is_string($subject) || trim($subject) === '') {
            $subject = trim(preg_replace('/\s+/u', ' ', $prompt) ?? '');
            if ($subject === '' || preg_match('/^([A-Z_]+_JSON|[\[{])/', $subject) === 1) {
                return null;
            }
        }

        return Str::limit(trim($subject), 190);
    }

    private function ready(): bool
    {
        return $this->ready ??= Schema::hasColumn('ai_live_operations', 'input_text');
    }
}
