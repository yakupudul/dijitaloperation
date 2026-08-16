<?php

namespace App\Enums;

/**
 * Explicit privacy gate reason codes (no privacy score).
 */
enum SectorLearningPrivacyReasonCode: string
{
    case InsufficientDistinctBrands = 'INSUFFICIENT_DISTINCT_BRANDS';
    case InsufficientDistinctCustomers = 'INSUFFICIENT_DISTINCT_CUSTOMERS';
    case DominantBrandContribution = 'DOMINANT_BRAND_CONTRIBUTION';
    case DominantCustomerContribution = 'DOMINANT_CUSTOMER_CONTRIBUTION';
    case UnsafeDimension = 'UNSAFE_DIMENSION';
    case IdentifyingDimension = 'IDENTIFYING_DIMENSION';
    case RareDimensionCombination = 'RARE_DIMENSION_COMBINATION';
    case FreeTextPresent = 'FREE_TEXT_PRESENT';
    case RawIdentifierPresent = 'RAW_IDENTIFIER_PRESENT';
    case RawKeywordPresent = 'RAW_KEYWORD_PRESENT';
    case RawCreativePresent = 'RAW_CREATIVE_PRESENT';
    case RawUrlPresent = 'RAW_URL_PRESENT';
    case UnsafeExactValue = 'UNSAFE_EXACT_VALUE';
    case InsufficientNumericCohort = 'INSUFFICIENT_NUMERIC_COHORT';
    case SmallCategoricalCell = 'SMALL_CATEGORICAL_CELL';
    case ComplementaryDisclosureRisk = 'COMPLEMENTARY_DISCLOSURE_RISK';
    case IncompatibleMetric = 'INCOMPATIBLE_METRIC';
    case IncompatibleCurrency = 'INCOMPATIBLE_CURRENCY';
    case IncompatibleAttribution = 'INCOMPATIBLE_ATTRIBUTION';
    case IncompatiblePeriod = 'INCOMPATIBLE_PERIOD';
    case UnsafeAggregator = 'UNSAFE_AGGREGATOR';
    case ContributionNotQualified = 'CONTRIBUTION_NOT_QUALIFIED';
    case SectorUnknown = 'SECTOR_UNKNOWN';
    case OneBrandInsufficient = 'ONE_BRAND_INSUFFICIENT';
    case IncompleteCandidate = 'INCOMPLETE_CANDIDATE';
    case PostAggregationDisclosure = 'POST_AGGREGATION_DISCLOSURE';
    case ForbiddenConsumerField = 'FORBIDDEN_CONSUMER_FIELD';
}
