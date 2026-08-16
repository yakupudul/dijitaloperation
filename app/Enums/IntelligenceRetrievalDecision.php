<?php

namespace App\Enums;

/**
 * Typed retrieval section decisions (Prompt 54).
 * No numeric relevance score.
 */
enum IntelligenceRetrievalDecision: string
{
    case Included = 'INCLUDED';
    case NotRequested = 'NOT_REQUESTED';
    case NotAllowed = 'NOT_ALLOWED';
    case NotApplicable = 'NOT_APPLICABLE';
    case Unavailable = 'UNAVAILABLE';
    case Blocked = 'BLOCKED';
    case SelectedWithLimit = 'SELECTED_WITH_LIMIT';
    case RequiredMissing = 'REQUIRED_MISSING';
}
