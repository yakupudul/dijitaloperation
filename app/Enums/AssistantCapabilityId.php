<?php

namespace App\Enums;

/**
 * Bounded Assistant Capability IDs (Prompt 56).
 * No DATABASE_QUERY / ALL_MEMORY_SEARCH / CROSS_CUSTOMER_SEARCH.
 */
enum AssistantCapabilityId: string
{
    case ProviderMetricLookup = 'provider_metric_lookup';
    case EvidenceLookup = 'evidence_lookup';
    case FindingLookup = 'finding_lookup';
    case OpportunityLookup = 'opportunity_lookup';
    case WorkLookup = 'work_lookup';
    case BrandExperienceLookup = 'brand_experience_lookup';
    case SectorPatternLookup = 'sector_pattern_lookup';
    case SkillGuidance = 'skill_guidance';
    case SpecialistAnalysis = 'specialist_analysis';
}
