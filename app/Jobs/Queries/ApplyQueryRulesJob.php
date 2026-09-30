<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryRuleEngine;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * "Kuralları uygula" and library changes outside the pipeline (review approved, pending imported): recomputes the
 * variant / topic keys of every query (QueryRuleEngine). Queued requests collapse into one; never overlaps the pipeline.
 */
final class ApplyQueryRulesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('queue.heavy_queue', 'heavy'));
    }

    public function uniqueId(): string
    {
        return 'all';
    }

    public function handle(QueryRuleEngine $engine): void
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            $engine->apply();
        } finally {
            $lock->release();
        }
    }
}
