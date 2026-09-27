<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandClusteringRun;
use App\Services\SearchDemand\SearchDemandClusteringService;
use App\Support\ServiceScope;
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
        // Re-checked at handle time: no paid / AI call once the website or brand left the service scope.
        $subject = SearchDemandClusteringRun::query()->find($this->runId);
        if ($subject !== null && ! app(ServiceScope::class)->serves($subject->getAttribute('digital_asset_id'), $subject->getAttribute('brand_id'))) {
            $clustering->markFailed($this->runId, ServiceScope::notServed());

            return;
        }
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
