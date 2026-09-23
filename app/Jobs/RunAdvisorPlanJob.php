<?php

namespace App\Jobs;

use App\Services\Advisor\AdvisorPlanRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued advisor plan (see AdvisorPlanRunner). One attempt; failure is recorded on the plan row.
 */
final class RunAdvisorPlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $planId) {}

    public function handle(AdvisorPlanRunner $runner): void
    {
        $runner->run($this->planId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        app(AdvisorPlanRunner::class)->markFailed($this->planId, $exception);
    }
}
