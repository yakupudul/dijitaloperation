<?php

namespace App\Services\Queries;

use App\Jobs\Queries\ClusterQueriesJob;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use Illuminate\Support\Facades\Cache;

/**
 * "Hepsini kümele": every active service with unclustered queries, biggest demand first, ONE service at a time. A
 * service without clusters gets a full run, one with clusters a place run (its new queries join the clusters).
 */
final class QueryClusterQueue
{
    public const string KEY = 'queries:cluster:all';

    /** @return array{status: string, queue: list<int>, current: ?int, done: int, total: int}|null */
    public static function state(): ?array
    {
        $state = Cache::get(self::KEY);

        return is_array($state) ? $state : null;
    }

    /** @return int services queued */
    public function start(): int
    {
        $queue = $this->servicesToCluster();
        Cache::put(self::KEY, ['status' => 'running', 'queue' => $queue, 'current' => null, 'done' => 0, 'total' => count($queue)], now()->addDays(3));
        $this->next();

        return count($queue);
    }

    /** Ends the queue; the service being clustered stops after its current step. */
    public function stop(): void
    {
        $state = self::state();
        if ($state === null) {
            return;
        }
        if (is_int($state['current'] ?? null)) {
            QueryClusterer::stop($state['current']);
        }
        Cache::put(self::KEY, ['status' => 'stopped', 'queue' => [], 'current' => null] + $state, now()->addDays(3));
    }

    /** Starts the next service of the queue (after $finished, when given). */
    public function next(?int $finished = null): void
    {
        $state = self::state();
        if (($state['status'] ?? null) !== 'running' || ($finished !== null && ($state['current'] ?? null) !== $finished)) {
            return;
        }
        $queue = array_values((array) $state['queue']);
        $done = (int) $state['done'] + ($finished !== null ? 1 : 0);
        $serviceId = array_shift($queue);
        if ($serviceId === null) {
            Cache::put(self::KEY, ['status' => 'ready', 'queue' => [], 'current' => null, 'done' => $done] + $state, now()->addDays(3));

            return;
        }
        Cache::put(self::KEY, ['queue' => $queue, 'current' => (int) $serviceId, 'done' => $done] + $state, now()->addDays(3));
        QueryClusterer::start((int) $serviceId, Cluster::query()->where('service_id', $serviceId)->exists() ? 'place' : 'full');
        ClusterQueriesJob::dispatch((int) $serviceId, true);
    }

    /**
     * Active services with at least one visible, real query outside their clusters, biggest demand first.
     *
     * @return list<int>
     */
    public function servicesToCluster(): array
    {
        $active = ServiceCatalogItem::query()->where('status', 'active')->pluck('id');
        $clustered = ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')
            ->whereColumn('cluster_queries.query_id', 'queries.id')->whereColumn('clusters.service_id', 'queries.service_id');

        return Query::query()->whereIn('service_id', $active)->where('hidden', false)->where('is_suggested', false)
            ->whereNotExists($clustered->select('cluster_queries.id'))
            ->groupBy('service_id')->selectRaw('service_id, sum(impressions) as demand')
            ->orderByDesc('demand')->orderBy('service_id')
            ->pluck('service_id')->map(fn ($id): int => (int) $id)->all();
    }
}
