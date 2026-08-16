<?php

namespace App\Enums;

/**
 * Source authority classes — no numeric authority score.
 */
enum IntelligenceSourceAuthority: string
{
    case CurrentCanonicalContext = 'CURRENT_CANONICAL_CONTEXT';
    case CurrentCanonicalEvidence = 'CURRENT_CANONICAL_EVIDENCE';
    case HistoricalBrandExperience = 'HISTORICAL_BRAND_EXPERIENCE';
    case PrivacyAggregatedSectorContext = 'PRIVACY_AGGREGATED_SECTOR_CONTEXT';
    case GeneralSkillKnowledge = 'GENERAL_SKILL_KNOWLEDGE';
}
