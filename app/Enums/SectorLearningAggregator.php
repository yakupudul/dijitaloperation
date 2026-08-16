<?php

namespace App\Enums;

/**
 * Allowlisted aggregators only — no arbitrary SQL aggregates / AI formulas.
 */
enum SectorLearningAggregator: string
{
    case CategoryDistribution = 'category_distribution';
    case DirectionDistribution = 'direction_distribution';
    case Proportion = 'proportion';
    case CountDistinctContributorsInternal = 'count_distinct_contributors_internal';
}
