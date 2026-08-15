<?php

namespace App\Services\Notifications;

use App\Enums\DomainEventType;
use App\Models\Approval;
use App\Models\ClientRequest;
use App\Models\DomainEvent;
use App\Models\QaReview;
use App\Models\RecurringReviewRun;
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
            DomainEventType::QaNeedsChanges => $this->qaTaskAssigneeRecipients($subjectId),
            DomainEventType::ApprovalApproved,
            DomainEventType::ApprovalRejected,
            DomainEventType::ApprovalChangesRequested => $this->approvalTaskAssigneeRecipients($subjectId),
            DomainEventType::RecurringReviewCompleted => $this->recurringReviewOwnerRecipients($subjectId),
            DomainEventType::ClientRequestCreated => $this->clientRequestOwnerRecipients($subjectId),
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
     * @return list<int|null>
     */
    private function qaTaskAssigneeRecipients(int $qaReviewId): array
    {
        $review = QaReview::query()->with('task:id,assignee_id')->find($qaReviewId);
        $assigneeId = $review?->task?->assignee_id;

        return $assigneeId !== null ? [(int) $assigneeId] : [];
    }

    /**
     * @return list<int|null>
     */
    private function approvalTaskAssigneeRecipients(int $approvalId): array
    {
        $approval = Approval::query()->with('task:id,assignee_id')->find($approvalId);
        $assigneeId = $approval?->task?->assignee_id;

        return $assigneeId !== null ? [(int) $assigneeId] : [];
    }

    /**
     * Schedule owner only (once), even when summary mentions findings/tasks.
     *
     * @return list<int|null>
     */
    private function recurringReviewOwnerRecipients(int $runId): array
    {
        $run = RecurringReviewRun::query()->with('schedule:id,owner_user_id')->find($runId);
        $ownerId = $run?->schedule?->owner_user_id;

        return $ownerId !== null ? [(int) $ownerId] : [];
    }

    /**
     * @return list<int|null>
     */
    private function clientRequestOwnerRecipients(int $requestId): array
    {
        $request = ClientRequest::query()->find($requestId);
        if ($request === null || $request->owner_user_id === null) {
            return [];
        }

        return [(int) $request->owner_user_id];
    }
}
