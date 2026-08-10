<?php

namespace App\Listeners;

use App\Events\FindingEvaluationCompleted;
use App\Services\Tasks\TaskOutcomeMonitor;

/**
 * After-commit listener: refresh Outcome signals for eligible completed Tasks.
 */
final class EvaluateTaskOutcomesAfterFindingEvaluation
{
    public function __construct(
        private readonly TaskOutcomeMonitor $monitor,
    ) {}

    public function handle(FindingEvaluationCompleted $event): void
    {
        $this->monitor->handle($event);
    }
}
