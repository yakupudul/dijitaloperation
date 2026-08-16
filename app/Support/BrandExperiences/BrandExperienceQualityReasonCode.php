<?php

namespace App\Support\BrandExperiences;

/**
 * Deterministic Evidence Quality reason codes — no magic scores.
 */
final class BrandExperienceQualityReasonCode
{
    public const string MISSING_BASELINE = 'missing_baseline';

    public const string MISSING_FOLLOW_UP = 'missing_follow_up';

    public const string PARTIAL_COVERAGE = 'partial_coverage';

    public const string PROVIDER_LIMITED = 'provider_limited';

    public const string PERIOD_MISMATCH = 'period_mismatch';

    public const string ATTRIBUTION_MISMATCH = 'attribution_mismatch';

    public const string CURRENCY_MISMATCH = 'currency_mismatch';

    public const string ACTION_NOT_CANONICALLY_CONFIRMED = 'action_not_canonically_confirmed';

    public const string CONFLICTING_EVIDENCE = 'conflicting_evidence';

    public const string OPERATOR_ONLY_OBSERVATION = 'operator_only_observation';

    public const string FOLLOW_UP_WINDOW_INCOMPLETE = 'follow_up_window_incomplete';

    public const string SITUATION_EVIDENCE_PRESENT = 'situation_evidence_present';

    public const string OUTCOME_EVIDENCE_PRESENT = 'outcome_evidence_present';

    public const string ACTION_TASK_CONFIRMED = 'action_task_confirmed';

    public const string ACTION_EXTERNAL_CONFIRMED = 'action_external_confirmed';

    public const string TEMPORAL_ORDER_VALID = 'temporal_order_valid';

    public const string CAUSALITY_NOT_ESTABLISHED = 'causality_not_established';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MISSING_BASELINE,
            self::MISSING_FOLLOW_UP,
            self::PARTIAL_COVERAGE,
            self::PROVIDER_LIMITED,
            self::PERIOD_MISMATCH,
            self::ATTRIBUTION_MISMATCH,
            self::CURRENCY_MISMATCH,
            self::ACTION_NOT_CANONICALLY_CONFIRMED,
            self::CONFLICTING_EVIDENCE,
            self::OPERATOR_ONLY_OBSERVATION,
            self::FOLLOW_UP_WINDOW_INCOMPLETE,
            self::SITUATION_EVIDENCE_PRESENT,
            self::OUTCOME_EVIDENCE_PRESENT,
            self::ACTION_TASK_CONFIRMED,
            self::ACTION_EXTERNAL_CONFIRMED,
            self::TEMPORAL_ORDER_VALID,
            self::CAUSALITY_NOT_ESTABLISHED,
        ];
    }
}
