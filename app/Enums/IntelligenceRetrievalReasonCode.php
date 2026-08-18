<?php

namespace App\Enums;

/**
 * Retrieval denial / omission reason codes.
 */
enum IntelligenceRetrievalReasonCode: string
{
    case SkillDoesNotRequest = 'SKILL_DOES_NOT_REQUEST';
    case AgentLayerNotAllowed = 'AGENT_LAYER_NOT_ALLOWED';
    case CurrentBrandContextMissing = 'CURRENT_BRAND_CONTEXT_MISSING';
    case GoalSelectionRequired = 'GOAL_SELECTION_REQUIRED';
    case GoalNotAvailable = 'GOAL_NOT_AVAILABLE';
    case NoCanonicalSector = 'NO_CANONICAL_SECTOR';
    case NoReleasedSectorPattern = 'NO_RELEASED_SECTOR_PATTERN';
    case SectorPatternNotCurrent = 'SECTOR_PATTERN_NOT_CURRENT';
    case SectorPrivacyNotReleased = 'SECTOR_PRIVACY_NOT_RELEASED';
    case NoRelevantBrandExperience = 'NO_RELEVANT_BRAND_EXPERIENCE';
    case ExperienceQualityNotAllowed = 'EXPERIENCE_QUALITY_NOT_ALLOWED';
    case ExperienceOutsideTimeScope = 'EXPERIENCE_OUTSIDE_TIME_SCOPE';
    case KnowledgeReferenceUnavailable = 'KNOWLEDGE_REFERENCE_UNAVAILABLE';
    case ContextBudgetExceeded = 'CONTEXT_BUDGET_EXCEEDED';
    case RetrievalContractInvalid = 'RETRIEVAL_CONTRACT_INVALID';
    case CrossBrandForbidden = 'CROSS_BRAND_FORBIDDEN';
    case InvalidatedExperience = 'INVALIDATED_EXPERIENCE';
    case OptionalEmpty = 'OPTIONAL_EMPTY';
}
