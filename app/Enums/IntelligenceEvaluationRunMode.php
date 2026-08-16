<?php

namespace App\Enums;

/**
 * Evaluation Run modes (Prompt 55).
 *
 * CI uses DeterministicOnly / MockedAi only — never uncontrolled paid live AI.
 */
enum IntelligenceEvaluationRunMode: string
{
    case DeterministicOnly = 'deterministic_only';
    case MockedAi = 'mocked_ai';
    case LiveControlled = 'live_controlled';
    case HumanReview = 'human_review';
    case Comparison = 'comparison';

    public function allowsLiveProviderInference(): bool
    {
        return $this === self::LiveControlled;
    }

    public function isCiSafe(): bool
    {
        return in_array($this, [self::DeterministicOnly, self::MockedAi, self::Comparison], true);
    }
}
