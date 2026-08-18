<?php

namespace App\Enums;

/**
 * Temporal / applicability state for future memory artifacts.
 */
enum MemoryValidityState: string
{
    case Active = 'active';
    case Historical = 'historical';
    case Superseded = 'superseded';
    case NeedsReview = 'needs_review';
    case PrivacyBlocked = 'privacy_blocked';
    case Expired = 'expired';

    public function isEligibleForCurrentAgentContext(): bool
    {
        return $this === self::Active;
    }
}
