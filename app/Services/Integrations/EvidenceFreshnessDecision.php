<?php

namespace App\Services\Integrations;

/**
 * Cost-guard decision for a paid provider request fingerprint.
 */
enum EvidenceFreshnessDecision: string
{
    case Miss = 'MISS';
    case HitFresh = 'HIT_FRESH';
    case BypassAllowed = 'BYPASS_ALLOWED';

    public function allowsProviderCall(): bool
    {
        return $this !== self::HitFresh;
    }

    public function isCacheHit(): bool
    {
        return $this === self::HitFresh;
    }
}
