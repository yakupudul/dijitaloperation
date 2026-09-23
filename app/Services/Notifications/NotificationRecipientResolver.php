<?php

namespace App\Services\Notifications;

use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use App\Models\Task;

/**
 * Resolve in-app notification recipients from canonical relations only (no text inference).
 *
 * Zero recipients is a valid outcome. Actor is never notified for their own action (self-suppression).
 */
final class NotificationRecipientResolver
{
    /**
     * @return list<int> unique user ids
     */
    public function resolve(DomainEvent $event): array
    {
        $type = $event->event_type instanceof DomainEventType
            ? $event->event_type
            : DomainEventType::from((string) $event->event_type);

        $actorId = $event->actor_user_id !== null ? (int) $event->actor_user_id : null;
        $subjectId = (int) $event->subject_id;

        $recipients = match ($type) {
            DomainEventType::FindingCreated => [],
            DomainEventType::OpportunityCreated => [],
            DomainEventType::RecommendationAccepted => [],
            DomainEventType::TaskCompleted => $this->taskAssigneeRecipients($subjectId),
            DomainEventType::TaskAssigned => $this->taskAssigneeRecipients($subjectId),
            DomainEventType::QaPassed,
            DomainEventType::QaFailed,
            DomainEventType::QaNeedsChanges,
            DomainEventType::ApprovalApproved,
            DomainEventType::ApprovalRejected,
            DomainEventType::ApprovalChangesRequested,
            DomainEventType::RecurringReviewCompleted,
            DomainEventType::ClientRequestCreated => [],
            DomainEventType::ScheduledInternalNotification,
            DomainEventType::BusinessOutcomeRecheckAttention,
            DomainEventType::OperationalAlertOpened => $this->payloadRecipientIds($event),
        };

        return $this->uniqueExcludingActor($recipients, $actorId);
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    private function uniqueExcludingActor(array $ids, ?int $actorId): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ($id === null || $id <= 0) {
                continue;
            }
            if ($actorId !== null && $id === $actorId) {
                continue;
            }
            $out[$id] = $id;
        }

        return array_values($out);
    }

    /**
     * @return list<int|null>
     */
    private function taskAssigneeRecipients(int $taskId): array
    {
        $task = Task::query()->find($taskId);
        if ($task === null || $task->assignee_id === null) {
            return [];
        }

        return [(int) $task->assignee_id];
    }

    /**
     * Explicit recipient list from schedule payload — never invents notify-all.
     *
     * @return list<int|null>
     */
    private function payloadRecipientIds(DomainEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $ids = $payload['recipient_user_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_map(static fn ($id): ?int => is_numeric($id) ? (int) $id : null, $ids);
    }
}
