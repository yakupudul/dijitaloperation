<?php

namespace App\Enums\IntelligenceCore;

enum IntelligenceValueState: string
{
    case Value = 'VALUE';
    case Zero = 'ZERO';
    case NotCollected = 'NOT_COLLECTED';
    case NotConfigured = 'NOT_CONFIGURED';
    case Unavailable = 'UNAVAILABLE';
    case ProviderOmitted = 'PROVIDER_OMITTED';
    case Partial = 'PARTIAL';
    case Stale = 'STALE';
    case Undefined = 'UNDEFINED';
    case NotComparable = 'NOT_COMPARABLE';

    public function carriesValue(): bool
    {
        return in_array($this, [self::Value, self::Zero, self::Partial, self::Stale], true);
    }
}
