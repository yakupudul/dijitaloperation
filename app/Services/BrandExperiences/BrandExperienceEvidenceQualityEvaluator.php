<?php

namespace App\Services\BrandExperiences;

use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceEvidenceRole;
use App\Enums\BrandExperienceSupportStatus;
use App\Support\BrandExperiences\BrandExperienceQualityReasonCode;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Deterministic Evidence Quality evaluator — no AI, no numeric scores, no causality.
 */
final class BrandExperienceEvidenceQualityEvaluator
{
    /**
     * @param  list<array{role: string, evidence_id: int, evidence_fingerprint: string}>  $evidenceLinks
     * @param  array{
     *     action_kind: BrandExperienceActionKind|string,
     *     action_occurred_at: CarbonInterface|string,
     *     outcome_observed_at: CarbonInterface|string,
     *     outcome_period_start?: CarbonInterface|string|null,
     *     outcome_period_end?: CarbonInterface|string|null,
     *     situation_period_start?: CarbonInterface|string|null,
     *     situation_period_end?: CarbonInterface|string|null,
     *     has_action_task?: bool,
     *     action_task_completed?: bool,
     *     has_situation_evidence?: bool,
     *     has_outcome_evidence?: bool,
     *     has_baseline_evidence?: bool,
     *     has_follow_up_evidence?: bool,
     *     operator_observation_only?: bool,
     *     conflicting?: bool,
     *     period_mismatch?: bool,
     *     currency_mismatch?: bool,
     *     attribution_mismatch?: bool,
     *     provider_limited?: bool,
     *     follow_up_incomplete?: bool
     * }  $input
     */
    public function evaluate(array $input, array $evidenceLinks = []): BrandExperienceEvidenceQualityAssessment
    {
        $reasons = [BrandExperienceQualityReasonCode::CAUSALITY_NOT_ESTABLISHED];
        $dimensions = [
            'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished->value,
            'conflict_status' => 'none',
        ];

        $actionKind = $input['action_kind'] instanceof BrandExperienceActionKind
            ? $input['action_kind']
            : BrandExperienceActionKind::from((string) $input['action_kind']);

        $actionAt = $this->toCarbon($input['action_occurred_at']);
        $outcomeAt = $this->toCarbon($input['outcome_observed_at']);

        if ($outcomeAt->lessThanOrEqualTo($actionAt)) {
            $reasons[] = BrandExperienceQualityReasonCode::PERIOD_MISMATCH;
            $dimensions['temporal_compatibility'] = 'invalid';
        } else {
            $reasons[] = BrandExperienceQualityReasonCode::TEMPORAL_ORDER_VALID;
            $dimensions['temporal_compatibility'] = 'valid';
        }

        $actionConfirmed = false;
        if ($actionKind === BrandExperienceActionKind::TaskCompleted) {
            if (($input['has_action_task'] ?? false) && ($input['action_task_completed'] ?? false)) {
                $actionConfirmed = true;
                $reasons[] = BrandExperienceQualityReasonCode::ACTION_TASK_CONFIRMED;
                $dimensions['action_confirmation_status'] = 'confirmed';
            } else {
                $reasons[] = BrandExperienceQualityReasonCode::ACTION_NOT_CANONICALLY_CONFIRMED;
                $dimensions['action_confirmation_status'] = 'unconfirmed';
            }
        } elseif ($actionKind === BrandExperienceActionKind::ExternalOperatorConfirmed) {
            $actionConfirmed = true;
            $reasons[] = BrandExperienceQualityReasonCode::ACTION_EXTERNAL_CONFIRMED;
            $dimensions['action_confirmation_status'] = 'operator_confirmed';
        }

        $roles = array_map(static fn (array $link): string => (string) $link['role'], $evidenceLinks);
        $hasSituation = in_array(BrandExperienceEvidenceRole::Situation->value, $roles, true)
            || ($input['has_situation_evidence'] ?? false);
        $hasOutcome = in_array(BrandExperienceEvidenceRole::Outcome->value, $roles, true)
            || in_array(BrandExperienceEvidenceRole::FollowUp->value, $roles, true)
            || ($input['has_outcome_evidence'] ?? false)
            || ($input['has_follow_up_evidence'] ?? false);
        $hasBaseline = in_array(BrandExperienceEvidenceRole::Baseline->value, $roles, true)
            || ($input['has_baseline_evidence'] ?? false);
        $hasFollowUp = in_array(BrandExperienceEvidenceRole::FollowUp->value, $roles, true)
            || ($input['has_follow_up_evidence'] ?? false);

        if ($hasSituation) {
            $reasons[] = BrandExperienceQualityReasonCode::SITUATION_EVIDENCE_PRESENT;
        }
        if ($hasOutcome) {
            $reasons[] = BrandExperienceQualityReasonCode::OUTCOME_EVIDENCE_PRESENT;
            $dimensions['outcome_support_status'] = 'present';
        } else {
            $reasons[] = BrandExperienceQualityReasonCode::MISSING_FOLLOW_UP;
            $dimensions['outcome_support_status'] = 'missing';
        }
        if (! $hasBaseline && $hasFollowUp) {
            $reasons[] = BrandExperienceQualityReasonCode::MISSING_BASELINE;
        }

        if ($input['operator_observation_only'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::OPERATOR_ONLY_OBSERVATION;
        }
        if ($input['conflicting'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::CONFLICTING_EVIDENCE;
            $dimensions['conflict_status'] = 'conflicting';
        }
        if ($input['period_mismatch'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::PERIOD_MISMATCH;
        }
        if ($input['currency_mismatch'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::CURRENCY_MISMATCH;
        }
        if ($input['attribution_mismatch'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::ATTRIBUTION_MISMATCH;
        }
        if ($input['provider_limited'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::PROVIDER_LIMITED;
        }
        if ($input['follow_up_incomplete'] ?? false) {
            $reasons[] = BrandExperienceQualityReasonCode::FOLLOW_UP_WINDOW_INCOMPLETE;
        }

        $reasons = array_values(array_unique($reasons));

        $support = $this->resolveSupportStatus(
            actionConfirmed: $actionConfirmed,
            temporalValid: $outcomeAt->greaterThan($actionAt),
            hasSituation: $hasSituation || ($input['operator_observation_only'] ?? false),
            hasOutcome: $hasOutcome || ($input['operator_observation_only'] ?? false),
            conflicting: (bool) ($input['conflicting'] ?? false),
            limited: (bool) (($input['provider_limited'] ?? false)
                || ($input['period_mismatch'] ?? false)
                || ($input['follow_up_incomplete'] ?? false)
                || ($input['currency_mismatch'] ?? false)
                || ($input['attribution_mismatch'] ?? false)
                || (! $hasBaseline && $hasFollowUp)),
        );

        $dimensions['support_status'] = $support->value;

        return new BrandExperienceEvidenceQualityAssessment(
            supportStatus: $support,
            reasonCodes: $reasons,
            policyVersion: BrandExperienceEvidenceQualityAssessment::POLICY_VERSION,
            assessedAt: now()->toIso8601String(),
            causalityStatus: BrandExperienceCausalityStatus::CausalityNotEstablished,
            dimensions: $dimensions,
        );
    }

    private function resolveSupportStatus(
        bool $actionConfirmed,
        bool $temporalValid,
        bool $hasSituation,
        bool $hasOutcome,
        bool $conflicting,
        bool $limited,
    ): BrandExperienceSupportStatus {
        if ($conflicting) {
            return BrandExperienceSupportStatus::Conflicting;
        }

        if (! $actionConfirmed || ! $temporalValid || ! $hasSituation || ! $hasOutcome) {
            return BrandExperienceSupportStatus::Insufficient;
        }

        if ($limited) {
            return BrandExperienceSupportStatus::Partial;
        }

        return BrandExperienceSupportStatus::Sufficient;
    }

    private function toCarbon(CarbonInterface|string $value): CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value : Carbon::parse($value);
    }
}
