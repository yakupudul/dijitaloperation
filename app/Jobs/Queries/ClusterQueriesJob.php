<?php

namespace App\Jobs\Queries;

use App\Models\ServiceCatalogItem;
use App\Services\Queries\QueryClusterer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "AI ile kümele": one AI call clusters one service's queries; locked clusters stay as they are. */
final class ClusterQueriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $serviceId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->serviceId;
    }

    public function handle(QueryClusterer $clusterer): void
    {
        $service = ServiceCatalogItem::query()->find($this->serviceId);
        $result = $service !== null ? $clusterer->cluster($service) : ['status' => 'no_service', 'clusters' => 0, 'suggested' => 0];
        Cache::put(QueryClusterer::cacheKey($this->serviceId), $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(QueryClusterer::cacheKey($this->serviceId), ['status' => 'error', 'clusters' => 0, 'suggested' => 0], now()->addDay());
    }
}
