<?php

namespace App\Jobs\Queries;

use App\Models\ServiceCatalogItem;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryClusterQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "AI ile kümele": ONE step of a service's run (one AI call: skeleton, a part of the topics, or the review) and the
 * next step queued after it. A failed call is repeated (same step, the run state only moves after a stored answer).
 * Part of "Hepsini kümele" ($all): when the service is done, the next service of the queue starts.
 */
final class ClusterQueriesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $serviceId, public bool $all = false)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryClusterer $clusterer): void
    {
        $service = ServiceCatalogItem::query()->find($this->serviceId);
        if ($service === null) {
            Cache::put(QueryClusterer::cacheKey($this->serviceId), ['status' => 'no_service'], now()->addDay());
            $this->next();

            return;
        }
        $state = $clusterer->step($service);
        if (($state['status'] ?? null) === 'running') {
            self::dispatch($this->serviceId, $this->all);

            return;
        }
        $this->next();
    }

    public function failed(?Throwable $exception): void
    {
        $state = QueryClusterer::state($this->serviceId) ?? [];
        if (($state['status'] ?? null) === 'running') {
            Cache::put(QueryClusterer::cacheKey($this->serviceId), ['status' => 'error', 'error' => mb_substr((string) $exception?->getMessage(), 0, 300)] + $state, now()->addDays(2));
        }
        $this->next();
    }

    private function next(): void
    {
        if ($this->all) {
            app(QueryClusterQueue::class)->next($this->serviceId);
        }
    }
}
