<?php

namespace App\Enums;

/**
 * Explicit PASS/BLOCK dispositions for Sector Memory privacy gate.
 *
 * Exact cohort thresholds and qualification rules belong to Prompt 53.
 * Prompt 51 defines the contract surface only — no magic privacy score.
 */
enum SectorPrivacyDisposition: string
{
    case Eligible = 'eligible';
    case BlockedPrivacyNotQualified = 'blocked_privacy_not_qualified';
    case BlockedSmallCohort = 'blocked_small_cohort';
    case BlockedDominantContributor = 'blocked_dominant_contributor';
    case BlockedIdentifyingDimension = 'blocked_identifying_dimension';
    case BlockedFreeText = 'blocked_free_text';
    case BlockedUnsupportedAggregation = 'blocked_unsupported_aggregation';
    case BlockedSectorUnknown = 'blocked_sector_unknown';
    case BlockedOneBrandInsufficient = 'blocked_one_brand_insufficient';
    case BlockedRawCustomerData = 'blocked_raw_customer_data';
    case BlockedPipelineNotImplemented = 'blocked_pipeline_not_implemented';

    public function isEligible(): bool
    {
        return $this === self::Eligible;
    }
}
