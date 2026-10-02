<?php

namespace App\Enums\DataPool;

/**
 * Deterministic Dataset freshness evaluation outcome (per Resource × Dataset).
 * Not a numeric quality score.
 */
enum FreshnessState: string
{
    case Fresh = 'FRESH';
    case Due = 'DUE';
    case Stale = 'STALE';
    case Partial = 'PARTIAL';
    case ActionRequired = 'ACTION_REQUIRED';
    case ProviderLimited = 'PROVIDER_LIMITED';
    case IntegrityBlocked = 'INTEGRITY_BLOCKED';
    case Unknown = 'UNKNOWN';
    case FreshWithLimitation = 'FRESH_WITH_LIMITATION';

    public function collectionDue(): bool
    {
        return in_array($this, [
            self::Due,
            self::Stale,
            self::Partial,
        ], true);
    }

    public function trustedFresh(): bool
    {
        return in_array($this, [self::Fresh, self::FreshWithLimitation], true);
    }
}
