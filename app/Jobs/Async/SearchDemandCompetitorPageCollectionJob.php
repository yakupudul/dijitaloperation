<?php

namespace App\Jobs\Async;

use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\SearchDemand\SearchDemandCompetitorPageCollectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchDemandCompetitorPageCollectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(
        SearchDemandCompetitorPageCollectionService $collection,
        AsyncOperationService $async,
    ): void {
        try {
            $collection->execute($this->runId, $async);
        } catch (Throwable $exception) {
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
        $run = Run::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }
        app(AsyncOperationService::class)->markFailed($run, $exception);
    }
}
