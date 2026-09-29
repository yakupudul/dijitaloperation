<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryClusterer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Clustering as a step INSIDE another chain (moxdop:pilot:refresh): decides the due services when it runs — after
 * the query pipeline steps before it — and puts one ClusterServiceJob per service plus the SERP research in front of
 * the rest of the chain. Not unique on purpose: a unique job that cannot take its lock would silently end a chain.
 */
class ClusterDueServicesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public ?int $serviceId = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryClusterer $clusterer): void
    {
        $services = $this->serviceId !== null ? [$this->serviceId] : $clusterer->dueServices();
        $jobs = array_map(fn (int $id): ClusterServiceJob => new ClusterServiceJob($id, force: $this->serviceId !== null), $services);
        $jobs[] = new ResearchQueryClustersJob(max(0, (int) config('moxdop-queries.serp.per_run', 40)));
        foreach (array_reverse($jobs) as $job) {
            $this->prependToChain($job);
        }
    }
}
