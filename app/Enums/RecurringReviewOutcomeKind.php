<?php

namespace App\Enums;

enum RecurringReviewOutcomeKind: string
{
    case NoIssue = 'no_issue';
    case Finding = 'finding';
    case Opportunity = 'opportunity';
    case Task = 'task';
}
