<?php

namespace App\Enums;

enum NotificationKind: string
{
    case FindingCreated = 'finding_created';
    case RecommendationAccepted = 'recommendation_accepted';
    case TaskCompleted = 'task_completed';
    case TaskAssigned = 'task_assigned';
    /** @deprecated Faz 1: producer removed */
    case QaPassed = 'qa_passed';
    /** @deprecated Faz 1: producer removed */
    case QaFailed = 'qa_failed';
    /** @deprecated Faz 1: producer removed */
    case QaNeedsChanges = 'qa_needs_changes';
    /** @deprecated Faz 1: producer removed */
    case ApprovalApproved = 'approval_approved';
    /** @deprecated Faz 1: producer removed */
    case ApprovalRejected = 'approval_rejected';
    /** @deprecated Faz 1: producer removed */
    case ApprovalChangesRequested = 'approval_changes_requested';
    /** @deprecated Faz 1: producer removed */
    case RecurringReviewCompleted = 'recurring_review_completed';
    /** @deprecated Faz 1: producer removed */
    case ClientRequestCreated = 'client_request_created';
    case OpportunityCreated = 'opportunity_created';
    case ScheduledInternalNotification = 'scheduled_internal_notification';
    case BusinessOutcomeRecheckAttention = 'business_outcome_recheck_attention';
    case OperationalAlertOpened = 'operational_alert_opened';
}
