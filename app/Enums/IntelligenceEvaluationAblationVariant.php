<?php

namespace App\Enums;

/**
 * Controlled eval-only ablation variants (Prompt 55).
 * Never mutates production retrieval policy.
 */
enum IntelligenceEvaluationAblationVariant: string
{
    case EvidenceOnly = 'evidence_only';
    case PlusBrandMemory = 'plus_brand_memory';
    case PlusSector = 'plus_sector';
    case PlusSkillKnowledge = 'plus_skill_knowledge';
    case FullRetrieval = 'full_retrieval';
}
