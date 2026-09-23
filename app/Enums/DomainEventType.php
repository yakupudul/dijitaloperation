<?php

namespace App\Enums;

/**
 * Bounded meaningful Domain Event types (facts, not commands).
 */
enum DomainEventType: string
{
    case FindingCreated = 'FINDING_CREATED';
    case RecommendationAccepted = 'RECOMMENDATION_ACCEPTED';
    case TaskCompleted = 'TASK_COMPLETED';
    case TaskAssigned = 'TASK_ASSIGNED';
    /** @deprecated Faz 1: producer removed */
    case QaPassed = 'QA_PASSED';
    /** @deprecated Faz 1: producer removed */
    case QaFailed = 'QA_FAILED';
    /** @deprecated Faz 1: producer removed */
    case QaNeedsChanges = 'QA_NEEDS_CHANGES';
    /** @deprecated Faz 1: producer removed */
    case ApprovalApproved = 'APPROVAL_APPROVED';
    /** @deprecated Faz 1: producer removed */
    case ApprovalRejected = 'APPROVAL_REJECTED';
    /** @deprecated Faz 1: producer removed */
    case ApprovalChangesRequested = 'APPROVAL_CHANGES_REQUESTED';
    /** @deprecated Faz 1: producer removed */
    case RecurringReviewCompleted = 'RECURRING_REVIEW_COMPLETED';
    /** @deprecated Faz 1: producer removed */
    case ClientRequestCreated = 'CLIENT_REQUEST_CREATED';
    case OpportunityCreated = 'OPPORTUNITY_CREATED';
    case ScheduledInternalNotification = 'SCHEDULED_INTERNAL_NOTIFICATION';
    /** @deprecated Faz 1: producer removed */
    case BusinessOutcomeRecheckAttention = 'BUSINESS_OUTCOME_RECHECK_ATTENTION';
    case OperationalAlertOpened = 'OPERATIONAL_ALERT_OPENED';

    public function category(): string
    {
        return match ($this) {
            self::FindingCreated, self::OpportunityCreated => 'intelligence',
            self::RecommendationAccepted => 'commercial',
            self::ClientRequestCreated => 'client_request',
            self::TaskCompleted, self::TaskAssigned => 'execution',
            self::QaPassed, self::QaFailed, self::QaNeedsChanges => 'quality',
            self::ApprovalApproved, self::ApprovalRejected, self::ApprovalChangesRequested => 'approval',
            self::RecurringReviewCompleted => 'review',
            self::ScheduledInternalNotification, self::BusinessOutcomeRecheckAttention => 'automation',
            self::OperationalAlertOpened => 'operations',
        };
    }

    public function preferenceKey(): string
    {
        return match ($this) {
            self::FindingCreated => 'critical_finding',
            self::TaskAssigned, self::TaskCompleted => 'task_assigned',
            self::ClientRequestCreated => 'client_request_received',
            self::ApprovalApproved, self::ApprovalRejected, self::ApprovalChangesRequested => 'approval_waiting',
            self::QaPassed, self::QaFailed, self::QaNeedsChanges => 'qa_review_required',
            self::RecurringReviewCompleted => 'recurring_review_due',
            self::RecommendationAccepted, self::OpportunityCreated => 'critical_finding',
            self::ScheduledInternalNotification => 'scheduled_internal_notification',
            self::BusinessOutcomeRecheckAttention => 'business_outcome_recheck',
            self::OperationalAlertOpened => 'operation_failed',
        };
    }
}
