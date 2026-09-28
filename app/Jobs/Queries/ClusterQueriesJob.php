<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Weekly / on demand: AI clustering of changed services, then SERP page-type research of changed clusters. */
class ClusterQueriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3500;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $serviceId = null) {}

    public function uniqueId(): string
    {
        return 'queries-clusters:'.($this->serviceId ?? 'all');
    }

    public function handle(QueryPipeline $pipeline): void
    {
        try {
            $pipeline->weekly($this->serviceId);
        } finally {
            Cache::forget(self::stateKey($this->serviceId));
        }
    }

    public static function stateKey(?int $serviceId): string
    {
        return 'queries:clustering-queued:'.($serviceId ?? 'all');
    }
}
