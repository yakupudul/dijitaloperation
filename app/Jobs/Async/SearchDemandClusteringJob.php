<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandClusteringRun;
use App\Services\SearchDemand\SearchDemandClusteringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchDemandClusteringJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(SearchDemandClusteringService $clustering): void
    {
        try {
            $clustering->execute($this->runId);
        } catch (Throwable $exception) {
            $clustering->markFailed($this->runId, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $run = SearchDemandClusteringRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        app(SearchDemandClusteringService::class)->markFailed($this->runId, $exception);
    }
}
