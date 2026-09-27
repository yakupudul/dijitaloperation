<?php

namespace App\Jobs\Async;

use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\SearchDemand\SearchDemandWebsiteImprovementService;
use App\Support\ServiceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SearchDemandWebsiteImprovementJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(
        SearchDemandWebsiteImprovementService $improvements,
        AsyncOperationService $async,
    ): void {
        if ($async->skippedOutsideServiceScope(Run::query()->find($this->runId))) {
            $improvements->markFailed($this->runId, ServiceScope::notServed());

            return;
        }
        try {
            $improvements->execute($this->runId, $async);
        } catch (Throwable $exception) {
            $improvements->markFailed($this->runId, $exception);
            $run = Run::query()->find($this->runId);
            if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
                $async->markFailed($run, $exception);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        app(SearchDemandWebsiteImprovementService::class)->markFailed($this->runId, $exception);
        $run = Run::query()->find($this->runId);
        if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
