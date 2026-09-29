<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sorgu hattı step: ingest a bounded chunk of source accounts (config moxdop-queries.sources_per_job) from stored
 * provider facts. Unchanged accounts are skipped by their facts / context fingerprint, so a retry is harmless.
 */
class IngestQuerySourcesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public int $backoff = 60;

    /** @param  list<string>  $sourceKeys */
    public function __construct(public string $runId, public array $sourceKeys, public bool $force = false)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPipeline $pipeline): void
    {
        QueryPipeline::addRunStats($this->runId, 'ingest', $pipeline->ingestKeys($this->sourceKeys, $this->force));
    }
}
