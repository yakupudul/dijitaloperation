<?php

namespace App\Enums;

/**
 * Explicit memory quality / review states — no numeric confidence score.
 */
enum MemoryQualityState: string
{
    case Confirmed = 'confirmed';
    case Derived = 'derived';
    case Aggregated = 'aggregated';
    case Curated = 'curated';
    case Unverified = 'unverified';
    case Superseded = 'superseded';
    case NeedsReview = 'needs_review';
    case PrivacyBlocked = 'privacy_blocked';
}
