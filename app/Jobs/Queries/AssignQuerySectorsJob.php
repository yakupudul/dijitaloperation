<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgu hattı step: sector of the accounts without one (brand sector first, AI for at most
 * moxdop-queries.sectors_per_job accounts), then accounts whose sector changed are filed again. The AI part is
 * skipped while another pipeline holds the AI lock; the next run picks up what is still pending.
 */
class AssignQuerySectorsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public string $runId, public ?int $resourceId = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryPipeline $pipeline): void
    {
        $lock = Cache::lock(QueryPipeline::AI_LOCK, 900);
        if (! $lock->get()) {
            QueryPipeline::addRunStats($this->runId, 'sectors', ['skipped' => 1]);

            return;
        }
        try {
            $stats = $pipeline->assignSectors($this->resourceId, max(1, (int) config('moxdop-queries.sectors_per_job', 100)));
        } finally {
            $lock->release();
        }
        QueryPipeline::addRunStats($this->runId, 'sectors', array_filter((array) $stats['sectors'], 'is_numeric'));
        QueryPipeline::addRunStats($this->runId, 'refile', (array) $stats['refile']);
    }
}
