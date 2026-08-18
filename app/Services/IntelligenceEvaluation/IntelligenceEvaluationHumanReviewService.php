<?php

namespace App\Services\IntelligenceEvaluation;

use App\Models\IntelligenceEvaluationCaseRun;
use App\Models\IntelligenceEvaluationHumanReview;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationHumanRubric;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;

/**
 * Human usefulness review persistence (Prompt 55).
 *
 * Categorical outcomes only. Cannot override hard safety failures.
 * Prior reviews are never overwritten.
 */
final class IntelligenceEvaluationHumanReviewService
{
    /**
     * @param  array<string, string>  $dimensionOutcomes
     */
    public function recordReview(
        IntelligenceEvaluationCaseRun $caseRun,
        int $reviewerId,
        array $dimensionOutcomes,
        ?string $notes = null,
        bool $attemptedPrivacyOverride = false,
    ): IntelligenceEvaluationHumanReview {
        $validation = IntelligenceEvaluationHumanRubric::validateOutcomes($dimensionOutcomes);
        if (! $validation['ok']) {
            throw new \InvalidArgumentException('Invalid human rubric outcomes: '.implode(',', $validation['errors']));
        }

        $privacyOverrideAccepted = false;
        if ($attemptedPrivacyOverride) {
            // Hard rule: humans cannot pass a real tenant/privacy leak.
            $privacyOverrideAccepted = false;
            if ($caseRun->safety_gate_status->value === 'fail') {
                $notes = trim(($notes ?? '')."\nPRIVACY_OVERRIDE_REJECTED");
            }
        }

        return IntelligenceEvaluationHumanReview::query()->create([
            'evaluation_case_run_id' => $caseRun->id,
            'rubric_version' => IntelligenceEvaluationPolicy::HUMAN_RUBRIC_VERSION,
            'reviewer_id' => $reviewerId,
            'dimension_outcomes' => $dimensionOutcomes,
            'notes' => $notes,
            'attempted_privacy_override' => $attemptedPrivacyOverride,
            'privacy_override_accepted' => $privacyOverrideAccepted,
            'reviewed_at' => now(),
        ]);
    }
}
