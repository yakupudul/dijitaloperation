<?php

namespace App\Jobs\Async;

use App\Services\SearchDemand\AutomaticQueryImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class AutomaticQueryImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function __construct(public int $batchId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('auto-query-batch:'.$this->batchId))->releaseAfter(10)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [20, 60];
    }

    public function handle(AutomaticQueryImportService $service): void
    {
        $service->execute($this->batchId);
    }

    public function failed(?Throwable $e): void
    {
        app(AutomaticQueryImportService::class)->fail($this->batchId);
    }
}
