<?php

namespace App\Jobs\Async;

use App\Services\SearchDemand\LibraryImportWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class LibraryImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('library-import:'.$this->importId))->dontRelease()->expireAfter(350)];
    }

    public function handle(LibraryImportWorkflow $workflow): void
    {
        $workflow->execute($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LibraryImportWorkflow::class)->fail($this->importId, $exception);
    }
}
