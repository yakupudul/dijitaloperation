<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryClusterer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Clustering step: one service's queries into page-sized clusters (one AI call, rule fallback). */
class ClusterServiceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param  bool  $force  operator asked for this service: re-cluster even when its membership is unchanged */
    public function __construct(public int $serviceId, public bool $force = false)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryClusterer $clusterer): void
    {
        $this->force ? $clusterer->clusterService($this->serviceId) : $clusterer->clusterDueService($this->serviceId);
    }
}
