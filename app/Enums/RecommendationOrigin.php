<?php

namespace App\Enums;

/**
 * How a Recommendation row came to exist. Origin is not the source: a Recommendation
 * always has exactly one source (Finding or Opportunity) regardless of origin.
 */
enum RecommendationOrigin: string
{
    case Operator = 'operator';
    case DeterministicTemplate = 'deterministic_template';
    case Legacy = 'legacy';
    case AiFuture = 'ai_future';

    public function label(): string
    {
        return match ($this) {
            self::Operator => 'Operator',
            self::DeterministicTemplate => 'Deterministic template',
            self::Legacy => 'Legacy',
            self::AiFuture => 'AI (reserved)',
        };
    }
}
