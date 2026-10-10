<?php

namespace App\Services\Ai;

use App\Models\AiTask;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Claude API dönemi (yakup, 2026-10-10: "claude api 25 ekime yani 15 günlüğüne 100 dolar verdi bedava … claude apiye
 * neleri devredebilirsen devret"). Until the end date every AI operation that may move runs on the Claude API with the
 * recommended plan (Sonnet 5.5 for writing, Haiku 5.5 for bulk work; the query autopilot, WhatsApp and embeddings
 * stay where they are), the daily ceiling is raised and the monthly budget gets the free credit on top. Work already
 * waiting in the Claude subscription queue is started again on the API. At the end every operation goes back to the
 * model it had before (kept in a snapshot file), and the raised limits end by themselves.
 */
final class ClaudeApiWindow
{
    private const string SNAPSHOT = 'ai/claude-api-window.json';

    public function __construct(
        private readonly PromptRegistry $registry,
        private readonly AiAssignments $assignments,
    ) {}

    public static function until(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('moxdop-ai-pricing.claude_api_window.until', '2026-10-25'), 'Europe/Istanbul')->startOfDay();
    }

    public static function active(): bool
    {
        return now('Europe/Istanbul')->lt(self::until());
    }

    /** The raised daily ceiling while the window is open, else null. */
    public static function dailyBudget(): ?float
    {
        return self::active() ? (float) config('moxdop-ai-pricing.claude_api_window.daily_usd', 8) : null;
    }

    /** What the window adds to the monthly budget while it is open. */
    public static function monthlyExtra(): float
    {
        return self::active() ? (float) config('moxdop-ai-pricing.claude_api_window.extra_usd', 100) : 0.0;
    }

    /**
     * Moves every operation that may move to the recommended Claude API plan (models before are kept for end()), and
     * starts again the runs waiting for Claude over MCP.
     *
     * @return array{changed: int, unchanged: int, kept: int, restarted: int}
     */
    public function start(User $by): array
    {
        if (! Storage::disk('local')->exists(self::SNAPSHOT)) {
            $models = [];
            foreach (array_keys($this->registry->definitions()) as $operation) {
                $models[$operation] = $this->registry->current($operation)->model;
            }
            Storage::disk('local')->put(self::SNAPSHOT, json_encode(['saved_at' => now()->toIso8601String(), 'models' => $models], JSON_PRETTY_PRINT));
        }
        $counts = $this->assignments->apply(AiAssignments::PLAN_RECOMMENDED, $by);

        return $counts + ['restarted' => $this->restartWaiting()];
    }

    /**
     * Every operation back on the model it had before start() (operations changed by hand since then keep their
     * change only if it differs from the plan's model).
     *
     * @return array{restored: int}
     */
    public function end(User $by): array
    {
        if (! Storage::disk('local')->exists(self::SNAPSHOT)) {
            return ['restored' => 0];
        }
        $models = (array) (json_decode((string) Storage::disk('local')->get(self::SNAPSHOT), true)['models'] ?? []);
        $restored = 0;
        foreach ($models as $operation => $model) {
            if (! $this->registry->has((string) $operation)) {
                continue;
            }
            $current = $this->registry->current((string) $operation);
            $planned = $this->assignments->target(AiAssignments::PLAN_RECOMMENDED, (string) $operation);
            if ($planned === null || (string) ($current->model ?? '') !== $planned || (string) ($current->model ?? '') === (string) ($model ?? '')) {
                continue;
            }
            try {
                $this->registry->publish((string) $operation, ['template' => (string) $current->template, 'model' => $model, 'purpose' => $current->purpose], $by);
                $restored++;
            } catch (Throwable $exception) {
                Log::warning('Claude API window: model could not be restored.', ['operation' => $operation, 'error' => $exception->getMessage()]);
            }
        }
        Storage::disk('local')->move(self::SNAPSHOT, 'ai/claude-api-window-ended-'.now()->format('Ymd-His').'.json');

        return ['restored' => $restored];
    }

    /** Runs waiting for Claude over MCP whose operations no longer wait: their open calls are closed and the job runs again. */
    private function restartWaiting(): int
    {
        $queue = app(AiTaskQueue::class);
        $open = AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->get(['id', 'resume_key', 'operation', 'resume']);
        $restarted = 0;
        foreach ($open->groupBy('resume_key') as $tasks) {
            if ($tasks->contains(fn (AiTask $t): bool => $queue->delegated((string) $t->operation))) {
                continue;
            }
            $closed = AiTask::query()->whereIn('id', $tasks->pluck('id')->all())->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])
                ->update(['status' => AiTask::CONSUMED, 'consumed_at' => now(), 'error' => 'Claude API dönemi: iş Claude API ile yeniden başlatıldı.']);
            if ($closed === 0) {
                continue;
            }
            try {
                $job = unserialize((string) $tasks->first()->resume);
                if (is_object($job)) {
                    dispatch($job);
                    $restarted++;
                }
            } catch (Throwable $exception) {
                Log::warning('Claude API window: a waiting run could not be started again.', ['error' => $exception->getMessage()]);
            }
        }

        return $restarted;
    }
}
