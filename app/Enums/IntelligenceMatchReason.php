<?php

namespace App\Enums;

/**
 * Explicit match / selection reason codes — not relevance scores.
 */
enum IntelligenceMatchReason: string
{
    case ExactGoalMatch = 'EXACT_GOAL_MATCH';
    case ExactOfferingMatch = 'EXACT_OFFERING_MATCH';
    case ExactMarketMatch = 'EXACT_MARKET_MATCH';
    case ExactChannelMatch = 'EXACT_CHANNEL_MATCH';
    case ExactAssetTypeMatch = 'EXACT_ASSET_TYPE_MATCH';
    case ExactActionCategoryMatch = 'EXACT_ACTION_CATEGORY_MATCH';
    case ExactMetricFamilyMatch = 'EXACT_METRIC_FAMILY_MATCH';
    case CurrentSectorMatch = 'CURRENT_SECTOR_MATCH';
    case RecentApplicableExperience = 'RECENT_APPLICABLE_EXPERIENCE';
    case SkillExplicitReference = 'SKILL_EXPLICIT_REFERENCE';
    case ConfirmedEligible = 'CONFIRMED_ELIGIBLE';
    case PrivacyReleased = 'PRIVACY_RELEASED';
    case StableIdTieBreak = 'STABLE_ID_TIE_BREAK';
}
