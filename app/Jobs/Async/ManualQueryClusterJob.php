<?php

namespace App\Jobs\Async;

use App\Services\SearchDemand\ManualQueryClusterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ManualQueryClusterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function __construct(public int $operationId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('manual-cluster:'.$this->operationId))->releaseAfter(5)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [5, 20];
    }

    public function handle(ManualQueryClusterService $service): void
    {
        $locale = app()->getLocale();
        try {
            $service->execute($this->operationId);
        } finally {
            app()->setLocale($locale);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ManualQueryClusterService::class)->fail($this->operationId);
    }
}

