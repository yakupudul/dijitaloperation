<?php

namespace App\Support\Tasks;

/**
 * Observed post-action Outcome signals on Task (not causal attribution; ADR-036: no Result entity).
 */
final class TaskOutcomeStatus
{
    public const string AWAITING_FOLLOW_UP = 'awaiting_follow_up';

    public const string IMPROVEMENT_OBSERVED = 'improvement_observed';

    public const string STILL_OBSERVED = 'still_observed';

    public const string REGRESSION_OBSERVED = 'regression_observed';

    public const string INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const string NOT_EVALUABLE = 'not_evaluable';

    public const string TECHNICALLY_FIXED = 'technically_fixed';

    public const string CONTENT_CHANGE_VERIFIED = 'content_change_verified';

    public const string VISIBILITY_INCREASED = 'visibility_increased';

    public const string VISIBILITY_DECREASED = 'visibility_decreased';

    public const string NO_CHANGE_OBSERVED = 'no_change_observed';

    public const string TOO_EARLY = 'too_early';

    public const string INSUFFICIENT_DATA = 'insufficient_data';

    public const string EVALUATOR_VERSION = 'finding-lifecycle-outcome-v1';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AWAITING_FOLLOW_UP,
            self::IMPROVEMENT_OBSERVED,
            self::STILL_OBSERVED,
            self::REGRESSION_OBSERVED,
            self::INSUFFICIENT_EVIDENCE,
            self::NOT_EVALUABLE,
            self::TECHNICALLY_FIXED,
            self::CONTENT_CHANGE_VERIFIED,
            self::VISIBILITY_INCREASED,
            self::VISIBILITY_DECREASED,
            self::NO_CHANGE_OBSERVED,
            self::TOO_EARLY,
            self::INSUFFICIENT_DATA,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::AWAITING_FOLLOW_UP => 'Awaiting follow-up',
            self::IMPROVEMENT_OBSERVED => 'Improvement observed',
            self::STILL_OBSERVED => 'Still observed',
            self::REGRESSION_OBSERVED => 'Regression observed',
            self::INSUFFICIENT_EVIDENCE => 'Insufficient evidence',
            self::NOT_EVALUABLE => 'Not evaluable',
            self::TECHNICALLY_FIXED => 'Technically fixed',
            self::CONTENT_CHANGE_VERIFIED => 'Content change verified',
            self::VISIBILITY_INCREASED => 'Visibility increased',
            self::VISIBILITY_DECREASED => 'Visibility decreased',
            self::NO_CHANGE_OBSERVED => 'No change observed',
            self::TOO_EARLY => 'Too early to evaluate',
            self::INSUFFICIENT_DATA => 'Insufficient data',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public static function explanation(string $status): string
    {
        return match ($status) {
            self::AWAITING_FOLLOW_UP => 'Task is completed, but no eligible comparable Finding evaluation has occurred yet after completion (or after the review-after date).',
            self::IMPROVEMENT_OBSERVED => 'The linked Finding was resolved in a successful follow-up evaluation after Task completion. This does not by itself prove the Task caused the change.',
            self::STILL_OBSERVED => 'The linked Finding remains present in the latest comparable successful evaluation. This is not a verdict that the Task “failed.”',
            self::REGRESSION_OBSERVED => 'Improvement had been observed earlier; the same stable Finding has been observed again in a later successful evaluation.',
            self::INSUFFICIENT_EVIDENCE => 'A relevant follow-up attempt exists, but the evaluation was not successful/complete enough to judge Outcome safely.',
            self::NOT_EVALUABLE => 'This Task does not have enough auditable Finding provenance to evaluate Outcome safely.',
            self::TECHNICALLY_FIXED => 'The original technical condition is absent from the accepted post-change stored HTML observation.',
            self::CONTENT_CHANGE_VERIFIED => 'A human accepted the semantic comparison showing that the intended content change is present.',
            self::VISIBILITY_INCREASED => 'Stored GSC or SERP observations increased after the recorded change; this is observational, not causal attribution.',
            self::VISIBILITY_DECREASED => 'Stored GSC or SERP observations decreased after the recorded change; this is observational, not causal attribution.',
            self::NO_CHANGE_OBSERVED => 'Comparable stored observations do not show a consistent visibility change.',
            self::TOO_EARLY => 'The configured review-after date has not been reached.',
            self::INSUFFICIENT_DATA => 'Comparable stored post-change evidence is not sufficient for a safe Outcome judgment.',
            default => 'Outcome state is recorded without causal attribution.',
        };
    }
}
