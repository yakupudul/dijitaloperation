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
    /** @deprecated Faz 1: producer removed */
    case ClientRequest = 'client_request';
    case Direct = 'direct';
    /** @deprecated Faz 1: producer removed */
    case RecurringReviewCheck = 'recurring_review_check';
}
