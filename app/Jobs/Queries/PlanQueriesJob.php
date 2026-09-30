<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "AI ile planla": one AI step (sectors | services | filters); the proposal waits for the operator who asked. */
final class PlanQueriesJob implements ShouldQueue
{
    use Queueable;

    public const array LABELS = ['sectors' => 'Sektör ata', 'services' => 'Hizmet keşfet', 'filters' => 'Filtre oluştur'];

    public int $timeout = 900;

    public int $tries = 1;

    /** @param list<int> $sectorIds */
    public function __construct(public int $userId, public string $step, public array $sectorIds = [])
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPlanner $planner, QueryNotifier $notifier): void
    {
        $result = $planner->propose($this->step, $this->sectorIds);
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
