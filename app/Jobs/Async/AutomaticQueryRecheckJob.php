<?php

namespace App\Jobs\Async;

use App\Models\SearchQueryLibraryImport;
use App\Services\SearchDemand\AutomaticQueryImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class AutomaticQueryRecheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('query-recheck:'.$this->importId))->releaseAfter(10)->expireAfter(150)];
    }

    public function backoff(): array { return [20, 60]; }

    public function handle(AutomaticQueryImportService $service): void { $service->recheck($this->importId); }

    public function failed(?Throwable $e): void
    {
        SearchQueryLibraryImport::query()->whereKey($this->importId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_summary' => __('resource-auto.import_failed'), 'completed_at' => now()]);
    }
}
