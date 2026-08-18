<?php

namespace App\Enums;

/**
 * Deterministic Client Value Story claim types (Prompt 58).
 * No AI copywriting.
 */
enum ClientValueStoryClaimType: string
{
    case FindingsIdentified = 'findings_identified';
    case FindingsResolved = 'findings_resolved';
    case OpportunitiesIdentified = 'opportunities_identified';
    case WorkCompleted = 'work_completed';
    case WorkInProgress = 'work_in_progress';
    case OutcomeReported = 'outcome_reported';
    case OutcomeChanged = 'outcome_changed';
    case DataLimitation = 'data_limitation';
}
