<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandEnrichmentRun;
use App\Services\SearchDemand\SearchDemandSerpEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchDemandSerpEnrichmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId) {}

    public function handle(SearchDemandSerpEnrichmentService $service): void
    {
        try {
            $service->execute($this->runId);
        } catch (Throwable $exception) {
            $service->markFailed($this->runId, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $run = SearchDemandEnrichmentRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed', 'charge_unknown'], true)) {
            return;
        }

        app(SearchDemandSerpEnrichmentService::class)->markFailed($this->runId, $exception);
    }
}
