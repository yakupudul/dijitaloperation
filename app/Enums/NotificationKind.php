<?php

namespace App\Enums;

enum NotificationKind: string
{
    case FindingCreated = 'finding_created';
    case RecommendationAccepted = 'recommendation_accepted';
    case TaskCompleted = 'task_completed';
    case TaskAssigned = 'task_assigned';
    case QaPassed = 'qa_passed';
    case QaFailed = 'qa_failed';
    case QaNeedsChanges = 'qa_needs_changes';
    case ApprovalApproved = 'approval_approved';
    case ApprovalRejected = 'approval_rejected';
    case ApprovalChangesRequested = 'approval_changes_requested';
    case RecurringReviewCompleted = 'recurring_review_completed';
    case ClientRequestCreated = 'client_request_created';
    case OpportunityCreated = 'opportunity_created';
}
