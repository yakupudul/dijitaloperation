<?php

namespace App\Jobs\Async;

use App\Models\SearchDemandAiRun;
use App\Services\SearchDemand\SearchDemandLibrarianService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SearchDemandAiLibrarianJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(SearchDemandLibrarianService $librarian): void
    {
        try {
            $librarian->execute($this->runId);
        } catch (Throwable $exception) {
            $librarian->markFailed($this->runId, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $run = SearchDemandAiRun::query()->find($this->runId);
        if ($run === null || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        app(SearchDemandLibrarianService::class)->markFailed($this->runId, $exception);
    }
}
