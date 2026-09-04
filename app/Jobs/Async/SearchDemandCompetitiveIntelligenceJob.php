<?php

namespace App\Jobs\Async;

use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\SearchDemand\SearchDemandCompetitiveIntelligenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SearchDemandCompetitiveIntelligenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(
        SearchDemandCompetitiveIntelligenceService $intelligence,
        AsyncOperationService $async,
    ): void {
        try {
            $intelligence->execute($this->runId, $async);
        } catch (Throwable $exception) {
            $intelligence->markFailed($this->runId, $exception);
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
        app(SearchDemandCompetitiveIntelligenceService::class)->markFailed($this->runId, $exception);
        $run = Run::query()->find($this->runId);
        if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
