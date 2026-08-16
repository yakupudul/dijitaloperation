<?php

namespace App\Enums;

/**
 * Semantic authority classes for Assistant claims (Prompt 56).
 * No numeric authority score.
 */
enum AssistantSourceClass: string
{
    case ProviderData = 'provider_data';
    case Evidence = 'evidence';
    case Finding = 'finding';
    case Opportunity = 'opportunity';
    case Recommendation = 'recommendation';
    case Work = 'work';
    case BrandExperience = 'brand_experience';
    case SectorPattern = 'sector_pattern';
    case SkillKnowledge = 'skill_knowledge';
    case BusinessOutcome = 'business_outcome';
}
