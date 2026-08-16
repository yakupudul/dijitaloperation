<?php

namespace App\Enums;

/**
 * Outcome clarity relative to an explicit Goal/desired direction when classified.
 * FactualState / Unclear do not imply business desirability.
 */
enum BrandExperienceOutcomeClarity: string
{
    case Favorable = 'favorable';
    case Unfavorable = 'unfavorable';
    case Mixed = 'mixed';
    case Unclear = 'unclear';
    case FactualState = 'factual_state';
}
