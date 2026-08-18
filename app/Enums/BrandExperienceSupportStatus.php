<?php

namespace App\Enums;

/**
 * Explicit Evidence Quality support states — no numeric score.
 */
enum BrandExperienceSupportStatus: string
{
    case Sufficient = 'sufficient';
    case Partial = 'partial';
    case Insufficient = 'insufficient';
    case Conflicting = 'conflicting';
    case NotAssessed = 'not_assessed';

    public function mayConfirm(): bool
    {
        return in_array($this, [self::Sufficient, self::Partial], true);
    }
}
