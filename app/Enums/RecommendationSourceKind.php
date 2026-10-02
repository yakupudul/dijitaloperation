<?php

namespace App\Enums;

/**
 * The one authoritative source a Recommendation is derived from.
 * Exactly one of Finding / Opportunity — never both, never neither.
 */
enum RecommendationSourceKind: string
{
    case Finding = 'finding';
    case Opportunity = 'opportunity';

    public function label(): string
    {
        return match ($this) {
            self::Finding => 'Finding',
            self::Opportunity => 'Opportunity',
        };
    }
}
