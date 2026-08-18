<?php

namespace App\Enums;

/**
 * Bounded primary operational source for a Task.
 * No unrestricted morphTo. Finding/Opportunity/Evidence are never Task sources.
 * Prompt 46 adds RecurringReviewCheck — exact Review Run Item provenance.
 */
enum TaskSourceKind: string
{
    case Recommendation = 'recommendation';
    case ClientRequest = 'client_request';
    case Direct = 'direct';
    case RecurringReviewCheck = 'recurring_review_check';
}
