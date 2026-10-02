<?php

namespace App\Enums\DataPool;

/**
 * Per-field data provenance for real-data migration UI (Prompt 28+).
 * Never a numeric quality score — a discrete, explicit provenance label.
 */
enum DataSourceState: string
{
    case Real = 'REAL';
    case RealDerived = 'REAL_DERIVED';
    case PartialReal = 'PARTIAL_REAL';
    case Demo = 'DEMO';
    case Unavailable = 'UNAVAILABLE';
    case ProviderLimited = 'PROVIDER_LIMITED';
    case StaleReal = 'STALE_REAL';

    public function isDirectReal(): bool
    {
        return $this === self::Real;
    }

    public function isRealDerived(): bool
    {
        return $this === self::RealDerived;
    }

    public function isPartialReal(): bool
    {
        return $this === self::PartialReal;
    }

    public function isDemo(): bool
    {
        return $this === self::Demo;
    }

    public function isUnavailable(): bool
    {
        return $this === self::Unavailable;
    }

    public function isProviderLimited(): bool
    {
        return $this === self::ProviderLimited;
    }

    public function isStaleReal(): bool
    {
        return $this === self::StaleReal;
    }

    /**
     * Pool-backed provenance (non-Demo) including partial coverage and stale-but-real slices.
     */
    public function isReal(): bool
    {
        return in_array($this, [
            self::Real,
            self::RealDerived,
            self::PartialReal,
            self::StaleReal,
        ], true);
    }

    /**
     * Labels that may be presented without an explicit Demo provenance badge.
     * Unavailable and Demo are excluded; provider-limited real data is still trusted presentation.
     */
    public function isTrustedPresentation(): bool
    {
        return in_array($this, [
            self::Real,
            self::RealDerived,
            self::PartialReal,
            self::ProviderLimited,
            self::StaleReal,
        ], true);
    }
}
