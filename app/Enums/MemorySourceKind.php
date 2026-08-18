<?php

namespace App\Enums;

/**
 * Bounded source kinds for memory provenance (no unrestricted polymorphism).
 */
enum MemorySourceKind: string
{
    case BrandExperience = 'brand_experience';
    case SectorAggregation = 'sector_aggregation';
    case SkillDefinition = 'skill_definition';
    case PlaybookRevision = 'playbook_revision';
    case PrimaryReference = 'primary_reference';
    case OperatorCurated = 'operator_curated';
    case ValidatedIntelligence = 'validated_intelligence';
}
