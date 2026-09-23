<?php

namespace App\Enums;

enum DomainEventSubjectKind: string
{
    case Finding = 'finding';
    case Opportunity = 'opportunity';
    case Recommendation = 'recommendation';
    /** @deprecated Faz 1: producer removed */
    case ClientRequest = 'client_request';
    case Task = 'task';
    /** @deprecated Faz 1: producer removed */
    case QaReview = 'qa_review';
    /** @deprecated Faz 1: producer removed */
    case Approval = 'approval';
    /** @deprecated Faz 1: producer removed */
    case Playbook = 'playbook';
    /** @deprecated Faz 1: producer removed */
    case RecurringReviewRun = 'recurring_review_run';
    case BusinessOutcomeRecheckRun = 'business_outcome_recheck_run';
    case InternalNotificationSchedule = 'internal_notification_schedule';
    case OperationalAlert = 'operational_alert';
}
