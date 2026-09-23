<?php

namespace App\Jobs;

use App\Services\SeoTasks\SeoPlanRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued SEO plan (see SeoPlanRunner). One attempt; failure is recorded on the plan row.
 */
final class RunSeoPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $planId) {}

    public function handle(SeoPlanRunner $runner): void
    {
        $runner->run($this->planId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        app(SeoPlanRunner::class)->markFailed($this->planId, $exception);
    }
}
