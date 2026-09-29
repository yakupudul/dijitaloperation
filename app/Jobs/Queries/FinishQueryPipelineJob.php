<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Last step of a queued sorgu hattı run: records the summary ("N yeni sorgu · …", also for an empty Search Console)
 * and releases the pipeline lock the orchestrator took.
 */
class FinishQueryPipelineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 2;

    /** @param  ?string  $lockKey  lock taken by the caller (defaults to the pipeline lock of $resourceId) */
    public function __construct(public string $runId, public ?int $resourceId, public string $lockOwner, public int $sources, public ?string $lockKey = null, public ?string $rememberAs = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $stats = QueryPipeline::runStats($this->runId);
        $stats['ingest'] = ($stats['ingest'] ?? []) + ['sources' => $this->sources, 'ingested' => 0, 'variants' => 0, 'failed' => 0, 'new_queries' => 0];
        $stats['new_queries'] = (int) ($stats['ingest']['new_queries'] ?? 0) + (int) ($stats['refile']['new_queries'] ?? 0);
        $stats['summary'] = QueryPipeline::summary($stats);
        $stats['queued'] = true;
        $this->rememberAs !== null ? QueryPipeline::rememberAs($this->rememberAs, $stats) : $pipeline->remember($this->resourceId, $stats);
        Cache::forget('queries:pipeline:run:'.$this->runId);
        Log::info('queries.pipeline.finished', ['resource_id' => $this->resourceId, 'summary' => $stats['summary']]);
        Cache::restoreLock($this->lockKey ?? QueryPipeline::lockKey($this->resourceId), $this->lockOwner)->release();
    }
}
