<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sorgu hattı step: rule matching of pending core queries to services (no AI, at most 5 000 per run). */
class MatchQueriesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public string $runId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $stats = $pipeline->matchRules();
        QueryPipeline::addRunStats($this->runId, 'match', (array) $stats['match']);
    }
}
