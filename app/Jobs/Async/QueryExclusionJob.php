<?php

namespace App\Jobs\Async;

use App\Services\SearchDemand\QueryExclusionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class QueryExclusionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('query-exclusions:'.$this->runId))->dontRelease()->expireAfter(350)];
    }

    public function handle(QueryExclusionService $service): void
    {
        $service->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(QueryExclusionService::class)->fail($this->runId, $exception);
    }
}
