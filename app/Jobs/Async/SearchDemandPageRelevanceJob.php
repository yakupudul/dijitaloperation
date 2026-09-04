<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandPageRelevanceRun;
use App\Services\SearchDemand\SearchDemandPageOwnershipService;
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
