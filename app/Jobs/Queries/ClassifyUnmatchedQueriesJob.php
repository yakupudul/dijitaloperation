<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgu hattı step: AI fallback for rule-unmatched queries, at most moxdop-queries.classify_per_job per job (a few AI
 * calls). While a full batch was answered and the run budget (classify_per_run) is not spent, the next batch is put
 * in front of the rest of the chain — many short jobs instead of one long one.
 */
class ClassifyUnmatchedQueriesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId, public int $budget)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPipeline $pipeline): void
    {
        if ($this->budget <= 0) {
            return;
        }
        $lock = Cache::lock(QueryPipeline::AI_LOCK, 900);
        if (! $lock->get()) {
            QueryPipeline::addRunStats($this->runId, 'ai', ['skipped' => 1]);

            return;
        }
        $limit = min($this->budget, max(10, (int) config('moxdop-queries.classify_per_job', 120)));
        try {
            $stats = $pipeline->classifyBatch($limit);
        } finally {
            $lock->release();
        }
        QueryPipeline::addRunStats($this->runId, 'ai', array_filter($stats, 'is_numeric'));
        $answered = (int) ($stats['matched'] ?? 0) + (int) ($stats['irrelevant'] ?? 0);
        if ($answered >= $limit && $this->budget - $limit > 0) {
            $this->prependToChain(new self($this->runId, $this->budget - $limit));
        }
    }
}
