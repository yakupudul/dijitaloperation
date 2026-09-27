<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandPageRelevanceRun;
use App\Services\SearchDemand\SearchDemandPageOwnershipService;
use App\Support\ServiceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchDemandPageRelevanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(SearchDemandPageOwnershipService $ownership): void
    {
        // Re-checked at handle time: no paid / AI call once the website or brand left the service scope.
        $subject = SearchDemandPageRelevanceRun::query()->find($this->runId);
        if ($subject !== null && ! app(ServiceScope::class)->serves($subject->getAttribute('digital_asset_id'), $subject->getAttribute('brand_id'))) {
            $ownership->markFailed($this->runId, ServiceScope::notServed());

            return;
        }
        try {
            $ownership->execute($this->runId);
        } catch (Throwable $exception) {
            $ownership->markFailed($this->runId, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $run = SearchDemandPageRelevanceRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        app(SearchDemandPageOwnershipService::class)->markFailed($this->runId, $exception);
    }
}
