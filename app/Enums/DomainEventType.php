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
    case QaPassed = 'QA_PASSED';
    case QaFailed = 'QA_FAILED';
    case QaNeedsChanges = 'QA_NEEDS_CHANGES';
    case ApprovalApproved = 'APPROVAL_APPROVED';
    case ApprovalRejected = 'APPROVAL_REJECTED';
    case ApprovalChangesRequested = 'APPROVAL_CHANGES_REQUESTED';
    case RecurringReviewCompleted = 'RECURRING_REVIEW_COMPLETED';
    case ClientRequestCreated = 'CLIENT_REQUEST_CREATED';
    case OpportunityCreated = 'OPPORTUNITY_CREATED';
    case ScheduledInternalNotification = 'SCHEDULED_INTERNAL_NOTIFICATION';
    case BusinessOutcomeRecheckAttention = 'BUSINESS_OUTCOME_RECHECK_ATTENTION';

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
        };
    }
}
