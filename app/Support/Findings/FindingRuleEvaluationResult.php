<?php

namespace App\Support\Findings;

use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Models\Finding;
use App\Models\FindingEvaluation;

final class FindingRuleEvaluationResult
{
    /**
     * @param  list<int>  $evidenceIds
     * @param  array<string, mixed>  $operands
     * @param  array<string, mixed>  $thresholds
     */
    public function __construct(
        public readonly FindingLifecycleAction $action,
        public readonly FindingConditionState $condition,
        public readonly FindingEligibilityDisposition $eligibility,
        public readonly ?Finding $finding,
        public readonly ?FindingEvaluation $evaluation,
        public readonly array $evidenceIds = [],
        public readonly array $operands = [],
        public readonly array $thresholds = [],
        public readonly bool $evaluationReused = false,
    ) {}
}
