<?php

namespace App\Services\Tasks;

use App\Events\FindingEvaluationCompleted;

/**
 * Thin monitor that updates Task Outcome signals after Finding evaluation commits.
 */
final class TaskOutcomeMonitor
{
    public function __construct(
        private readonly TaskOutcomeEvaluator $evaluator,
    ) {}

    public function handle(FindingEvaluationCompleted $event): int
    {
        return $this->evaluator->evaluateAfterFindingEvaluation($event);
    }
}
