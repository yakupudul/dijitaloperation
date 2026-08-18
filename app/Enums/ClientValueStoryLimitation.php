<?php

namespace App\Enums;

/**
 * Client Value Story limitation codes (Prompt 58).
 */
enum ClientValueStoryLimitation: string
{
    case NoBusinessOutcomeData = 'no_business_outcome_data';
    case PartialOutcomeCoverage = 'partial_outcome_coverage';
    case UnknownOutcomeCompleteness = 'unknown_outcome_completeness';
    case OutcomeDefinitionChanged = 'outcome_definition_changed';
    case NoCanonicalAttribution = 'no_canonical_attribution';
    case HistoricalFindingStateLimited = 'historical_finding_state_limited';
    case HistoricalOpportunityStateLimited = 'historical_opportunity_state_limited';
    case TaskHistoryLimited = 'task_history_limited';
    case ComparisonNotAvailable = 'comparison_not_available';
    case MixedCurrencyNotComparable = 'mixed_currency_not_comparable';
    case NoFindingsInPeriod = 'no_findings_in_period';
    case NoOpportunitiesInPeriod = 'no_opportunities_in_period';
    case NoCompletedWorkInPeriod = 'no_completed_work_in_period';
}
