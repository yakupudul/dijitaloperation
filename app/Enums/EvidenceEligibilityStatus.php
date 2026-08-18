<?php

namespace App\Enums;

enum EvidenceEligibilityStatus: string
{
    case Eligible = 'eligible';
    case IneligibleProvenance = 'ineligible_provenance';
    case IneligibleIntegrity = 'ineligible_integrity';
    case IneligibleCoverage = 'ineligible_coverage';
    case IneligibleFreshness = 'ineligible_freshness';
    case IneligibleScope = 'ineligible_scope';
    case IneligibleMeasurement = 'ineligible_measurement';
    case IneligiblePeriod = 'ineligible_period';

    public function isEligible(): bool
    {
        return $this === self::Eligible;
    }
}
