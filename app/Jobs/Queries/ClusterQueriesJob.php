<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryClusterer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Weekly / on demand: AI clustering of changed services, then SERP page-type research of changed clusters — as a
 * chain of short jobs on the heavy queue (one AI call per ClusterServiceJob, a capped batch of paid checks per
 * ResearchQueryClustersJob) instead of one 3 500 s job that outlived the queue's retry_after.
 */
class ClusterQueriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $serviceId = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'queries-clusters:'.($this->serviceId ?? 'all');
    }

    public function handle(QueryClusterer $clusterer): void
    {
        $stateKey = self::stateKey($this->serviceId);
        try {
            $services = $this->serviceId !== null ? [$this->serviceId] : $clusterer->dueServices();
            $jobs = array_map(fn (int $id): ClusterServiceJob => new ClusterServiceJob($id, force: $this->serviceId !== null), $services);
            $jobs[] = new ResearchQueryClustersJob(max(0, (int) config('moxdop-queries.serp.per_run', 40)), $stateKey);
            Bus::chain($jobs)
                ->onQueue((string) config('queue.heavy_queue', 'default'))
                ->catch(fn (Throwable $exception) => Cache::forget($stateKey))
                ->dispatch();
        } catch (Throwable $exception) {
            Cache::forget($stateKey);

            throw $exception;
        }
    }

    public static function stateKey(?int $serviceId): string
    {
        return 'queries:clustering-queued:'.($serviceId ?? 'all');
    }
}
