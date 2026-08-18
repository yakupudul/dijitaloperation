<?php

namespace Database\Factories;

use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FindingEvaluation>
 */
class FindingEvaluationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'finding_id' => Finding::factory(),
            'rule_id' => 'website:gsc:clicks-decline',
            'rule_version' => 1,
            'evaluation_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'condition_result' => FindingConditionState::True->value,
            'eligibility_disposition' => FindingEligibilityDisposition::Eligible->value,
            'block_reason' => null,
            'evaluated_at' => now(),
            'operand_snapshot' => [],
            'threshold_snapshot' => [],
            'freshness_state' => 'FRESH',
            'integrity_state' => 'pass',
            'completeness_state' => 'complete',
            'lifecycle_action' => FindingLifecycleAction::Created->value,
            'run_id' => null,
        ];
    }
}
