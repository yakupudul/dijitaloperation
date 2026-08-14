<?php

namespace App\Support\Opportunities;

use App\Enums\OpportunityConditionState;
use App\Enums\OpportunityEligibilityDisposition;
use App\Enums\OpportunityLifecycleAction;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;

final class OpportunityRuleEvaluationResult
{
    /**
     * @param  list<int>  $evidenceIds
     * @param  list<int>  $findingIds
     * @param  array<string, mixed>  $operands
     * @param  array<string, mixed>  $thresholds
     */
    public function __construct(
        public readonly OpportunityLifecycleAction $action,
        public readonly OpportunityConditionState $condition,
        public readonly OpportunityEligibilityDisposition $eligibility,
        public readonly ?Opportunity $opportunity,
        public readonly ?OpportunityEvaluation $evaluation,
        public readonly array $evidenceIds = [],
        public readonly array $findingIds = [],
        public readonly array $operands = [],
        public readonly array $thresholds = [],
        public readonly bool $evaluationReused = false,
    ) {}
}
