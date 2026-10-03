<?php

namespace App\Jobs\Queries;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "AI ile planla": one AI step (sectors | services | filters); the proposal waits for the operator who asked. Delegated
 * to Claude: the step waits for the answers and this job runs again when they are in.
 */
final class PlanQueriesJob implements ShouldQueue
{
    use Queueable;

    public const array LABELS = ['sectors' => 'Sektör ata', 'services' => 'Hizmet keşfet', 'filters' => 'Filtre oluştur', 'scan' => 'Sorgularda filtre kelimesi tara'];

    public int $timeout = 900;

    public int $tries = 1;

    /** @param list<int> $sectorIds */
    public function __construct(public int $userId, public string $step, public array $sectorIds = [], public string $instruction = '')
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPlanner $planner, QueryNotifier $notifier, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self($this->userId, $this->step, $this->sectorIds, $this->instruction), null, 'Sorgu planı · '.(self::LABELS[$this->step] ?? $this->step));
        try {
            $result = $planner->propose($this->step, $this->sectorIds, $this->instruction);
        } finally {
            $tasks->settle();
        }
        if (($result['status'] ?? null) === 'queued') {
            // Waiting for Claude (MCP queue): this job runs again with the answers.
            QueryPlanner::markWaiting($this->userId, $this->step);

            return;
        }
        Cache::put(QueryPlanner::cacheKey($this->userId, $this->step), $result, now()->addDay());
        if (($result['status'] ?? null) === 'error') {
            $this->notifyFailed($notifier);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(QueryPlanner::cacheKey($this->userId, $this->step), ['status' => 'error'], now()->addDay());
        $this->notifyFailed(app(QueryNotifier::class));
    }

    private function notifyFailed(QueryNotifier $notifier): void
    {
        $notifier->send($this->userId, 'AI adımı başarısız: '.(self::LABELS[$this->step] ?? $this->step), route('operator.library.queries.plan', [], false));
    }
}
