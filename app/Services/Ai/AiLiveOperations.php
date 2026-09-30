<?php

namespace App\Services\Ai;

use App\Models\AiLiveOperation;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Throwable;

/**
 * Canlı AI işlemleri: opens a row when any laravel/ai agent call starts (PromptingAgent / StreamingAgent) and closes it
 * when the call returns (AgentPrompted / AgentStreamed → done) or fails (AgentFailedOver, or the call's exception —
 * rows still open when the request / job / command ends are closed as failed with the last provider error seen).
 * Hooked on the framework events only; no call site changes. Never throws: live status must not break an AI workflow.
 * Scoped: one instance per request / job.
 */
final class AiLiveOperations
{
    /** Hidden context: short subject a caller may set for the live list (e.g. the brand name). */
    public const string SUBJECT_CONTEXT = 'ai_live_subject';

    /** Hidden context: the operator who started the work (propagates into queued jobs). */
    public const string USER_CONTEXT = 'ai_user_id';

    /** A running row older than this is closed as failed (the process died). */
    public const int STALE_MINUTES = 30;

    /** The header indicator stays visible this long after the last call finished. */
    public const int RECENT_MINUTES = 5;

    /** @var array<string, int> invocation id => row id */
    private array $byInvocation = [];

    /** @var array<int, int> agent object id => row id of its current attempt */
    private array $byAgent = [];

    private ?string $lastError = null;

    private ?bool $ready = null;

    public function __construct(private readonly AiPricing $pricing) {}

    public function started(PromptingAgent $event): void
    {
        try {
            if (! $this->ready()) {
                return;
            }
            $agent = $event->prompt->agent;
            $trial = Context::getHidden(AiUsageRecorder::TRIAL_CONTEXT) === true;
            $operation = $trial ? AiUsageRecorder::TRIAL_ROUTE : AiUsageRecorder::routeKeyFor($agent);
            $user = auth()->id() ?? Context::getHidden(self::USER_CONTEXT);
            $row = AiLiveOperation::query()->create([
                'invocation_id' => mb_substr($event->invocationId, 0, 64),
                'operation' => $operation !== null ? mb_substr($operation, 0, 120) : null,
                'label' => mb_substr(AiOperationLabels::for($operation, class_basename($agent)), 0, 190),
                'agent' => mb_substr(class_basename($agent), 0, 190),
                'status' => AiLiveOperation::RUNNING,
                'user_id' => is_numeric($user) ? (int) $user : null,
                'subject' => $this->subject($event->prompt->prompt),
                'started_at' => now(),
            ]);
            $this->byInvocation[$event->invocationId] = (int) $row->id;
            $this->byAgent[spl_object_id($agent)] = (int) $row->id;
            $this->lastError = null;
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be opened.', ['error' => $exception->getMessage()]);
        }
    }

    public function finished(AgentPrompted $event): void
    {
        $id = $this->byInvocation[$event->invocationId] ?? null;
        if ($id === null) {
            return;
        }
        unset($this->byInvocation[$event->invocationId], $this->byAgent[spl_object_id($event->prompt->agent)]);
        try {
            $usage = $event->response->usage;
            $cost = $this->pricing->cost(
                (string) ($event->response->meta->provider ?? 'unknown'),
                (string) ($event->response->meta->model ?? 'unknown'),
                $usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens, $usage->cacheWriteInputTokens,
            );
            $this->close($id, AiLiveOperation::DONE, null, $cost);
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be closed.', ['error' => $exception->getMessage()]);
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

    /** Running calls (oldest first). */
    public function running(): Collection
    {
        $this->sweepStale();

        return AiLiveOperation::query()->where('status', AiLiveOperation::RUNNING)->orderBy('started_at')->orderBy('id')->limit(50)->get();
    }

    /** Last finished calls (newest first). */
    public function finishedRecently(int $limit = 10): Collection
    {
        return AiLiveOperation::query()->where('status', '!=', AiLiveOperation::RUNNING)->orderByDesc('finished_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** Whether a call finished within the recent window (the header indicator stays visible). */
    public function hasRecent(): bool
    {
        return AiLiveOperation::query()->where('status', '!=', AiLiveOperation::RUNNING)
            ->where('finished_at', '>=', now()->subMinutes(self::RECENT_MINUTES))->exists();
    }

    /** Closes rows whose process died long ago. */
    public function sweepStale(): void
    {
        try {
            AiLiveOperation::query()->where('status', AiLiveOperation::RUNNING)->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))
                ->whereNotIn('id', array_values($this->byInvocation))
                ->update(['status' => AiLiveOperation::FAILED, 'error' => 'Süreç yarıda kaldı (zaman aşımı).', 'finished_at' => now()]);
        } catch (Throwable) {
            // Display only.
        }
    }

    private function close(int $id, string $status, ?string $error, ?float $cost = null): void
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
            ])->save();
        } catch (Throwable $exception) {
            Log::warning('AI live operation could not be closed.', ['error' => $exception->getMessage()]);
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
        return $this->ready ??= Schema::hasTable('ai_live_operations');
    }
}
