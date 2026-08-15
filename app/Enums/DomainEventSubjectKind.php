<?php

namespace App\Enums;

enum DomainEventSubjectKind: string
{
    case Finding = 'finding';
    case Opportunity = 'opportunity';
    case Recommendation = 'recommendation';
    case ClientRequest = 'client_request';
    case Task = 'task';
    case QaReview = 'qa_review';
    case Approval = 'approval';
    case Playbook = 'playbook';
    case RecurringReviewRun = 'recurring_review_run';
}
