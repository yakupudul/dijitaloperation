<?php

namespace App\Enums;

/**
 * Safe Sector Learning artifact kinds (Prompt 53).
 *
 * No BEST_STRATEGY / WINNING_CREATIVE / TOP_KEYWORD / TOP_BRAND.
 */
enum SectorLearningArtifactKind: string
{
    case ActionOutcomeAssociation = 'action_outcome_association';
    case OutcomeDistribution = 'outcome_distribution';
    case FrequencyPattern = 'frequency_pattern';
}
