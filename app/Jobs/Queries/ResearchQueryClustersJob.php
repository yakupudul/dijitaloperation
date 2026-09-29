<?php

namespace App\Jobs\Queries;

use App\Services\Queries\ClusterPageResearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Clustering step: SERP page-type research of due clusters, at most moxdop-queries.serp.per_job paid checks per job;
 * continues itself while the run budget lasts. The last one clears the "clustering queued" screen flag.
 */
class ResearchQueryClustersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $budget, public ?string $stateKey = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(ClusterPageResearch $research): void
    {
        $limit = min($this->budget, max(1, (int) config('moxdop-queries.serp.per_job', 10)));
        $done = 0;
        try {
            if ($limit > 0) {
                $stats = $research->researchDue($limit);
                $done = (int) ($stats['checked'] ?? 0) + (int) ($stats['reused'] ?? 0) + (int) ($stats['failed'] ?? 0) + (int) ($stats['skipped'] ?? 0);
            }
        } finally {
            $continue = $limit > 0 && $done >= $limit && $this->budget - $limit > 0;
            if ($continue) {
                $this->prependToChain(new self($this->budget - $limit, $this->stateKey));
            } elseif ($this->stateKey !== null) {
                Cache::forget($this->stateKey);
            }
        }
    }
}
