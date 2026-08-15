<?php

namespace App\Services\Notifications;

use App\Enums\DomainEventType;
use App\Enums\NotificationKind;

/**
 * Policy for which Domain Events create Activity / Notifications and which preference key applies.
 */
final class NotificationPolicyRegistry
{
    /**
     * All registered DomainEventType values create Activity when brand_id is present.
     */
    public function shouldCreateActivity(DomainEventType $type): bool
    {
        return match ($type) {
            DomainEventType::FindingCreated,
            DomainEventType::RecommendationAccepted,
            DomainEventType::TaskCompleted,
            DomainEventType::TaskAssigned,
            DomainEventType::QaPassed,
            DomainEventType::QaFailed,
            DomainEventType::QaNeedsChanges,
            DomainEventType::ApprovalApproved,
            DomainEventType::ApprovalRejected,
            DomainEventType::ApprovalChangesRequested,
            DomainEventType::RecurringReviewCompleted,
            DomainEventType::ClientRequestCreated,
            DomainEventType::OpportunityCreated => true,
        };
    }

    /**
     * In-app notification kind for the event, or null when policy skips notification projection.
     *
     * Default policy: every registered type has a kind. Recipient resolver may still return []
     * (e.g. FINDING_CREATED / OPPORTUNITY_CREATED / RECOMMENDATION_ACCEPTED) — zero notifications is valid.
     */
    public function notificationKind(DomainEventType $type): ?NotificationKind
    {
        return match ($type) {
            DomainEventType::FindingCreated => NotificationKind::FindingCreated,
            DomainEventType::RecommendationAccepted => NotificationKind::RecommendationAccepted,
            DomainEventType::TaskCompleted => NotificationKind::TaskCompleted,
            DomainEventType::TaskAssigned => NotificationKind::TaskAssigned,
            DomainEventType::QaPassed => NotificationKind::QaPassed,
            DomainEventType::QaFailed => NotificationKind::QaFailed,
            DomainEventType::QaNeedsChanges => NotificationKind::QaNeedsChanges,
            DomainEventType::ApprovalApproved => NotificationKind::ApprovalApproved,
            DomainEventType::ApprovalRejected => NotificationKind::ApprovalRejected,
            DomainEventType::ApprovalChangesRequested => NotificationKind::ApprovalChangesRequested,
            DomainEventType::RecurringReviewCompleted => NotificationKind::RecurringReviewCompleted,
            DomainEventType::ClientRequestCreated => NotificationKind::ClientRequestCreated,
            DomainEventType::OpportunityCreated => NotificationKind::OpportunityCreated,
        };
    }

    public function preferenceKey(DomainEventType $type): string
    {
        return $type->preferenceKey();
    }
}
