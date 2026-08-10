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
            default => 'Outcome state is recorded without causal attribution.',
        };
    }
}
