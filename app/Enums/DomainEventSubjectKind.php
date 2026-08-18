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
    case BusinessOutcomeRecheckRun = 'business_outcome_recheck_run';
    case InternalNotificationSchedule = 'internal_notification_schedule';
    case OperationalAlert = 'operational_alert';
}
