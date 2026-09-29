<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgular pipeline (query_sources → queries → brand_queries): normalize, filter basket, service assignment, totals.
 * Idempotent full pass on the heavy queue; queued requests collapse into one, runs never overlap.
 */
final class ProcessQueriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string LOCK = 'queries:process';

    public int $tries = 10;

    public int $timeout = 1700;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('queue.heavy_queue', 'heavy'));
    }

    public function uniqueId(): string
    {
        return 'all';
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(120);

            return;
        }
        try {
            $pipeline->run();
        } finally {
            $lock->release();
        }
    }
}
