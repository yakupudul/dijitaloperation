<?php

namespace Database\Factories;

use App\Enums\OpportunityConditionState;
use App\Enums\OpportunityEligibilityDisposition;
use App\Enums\OpportunityLifecycleAction;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpportunityEvaluation>
 */
class OpportunityEvaluationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opportunity_id' => Opportunity::factory(),
            'rule_id' => 'website:gsc:organic-click-recovery',
            'rule_version' => 1,
            'evaluation_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'condition_result' => OpportunityConditionState::True->value,
            'eligibility_disposition' => OpportunityEligibilityDisposition::Eligible->value,
            'block_reason' => null,
            'evaluated_at' => now(),
            'operand_snapshot' => [],
            'threshold_snapshot' => [],
            'freshness_state' => 'FRESH',
            'integrity_state' => 'pass',
            'completeness_state' => 'complete',
            'lifecycle_action' => OpportunityLifecycleAction::Created->value,
            'run_id' => null,
            'service_context_snapshot' => [],
            'goal_ids_snapshot' => [],
            'offering_ids_snapshot' => [],
            'market_context_snapshot' => [],
            'commercial_scope_state' => null,
            'qualitative_priority' => 'high',
        ];
    }
}
