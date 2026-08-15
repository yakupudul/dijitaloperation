<?php

namespace App\Services\Notifications;

use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\NotificationKind;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Projects DomainEvent → UserNotification rows (in-app only).
 * No Mail::, no Notification::route, no HTTP.
 */
final class NotificationProjector
{
    public function __construct(
        private readonly NotificationPolicyRegistry $policy,
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    /**
     * @return list<UserNotification>
     */
    public function project(DomainEvent $event): array
    {
        $type = $event->event_type instanceof DomainEventType
            ? $event->event_type
            : DomainEventType::from((string) $event->event_type);

        $kind = $this->policy->notificationKind($type);
        if ($kind === null) {
            return [];
        }

        $preferenceKey = $this->policy->preferenceKey($type);
        $recipientIds = $this->recipients->resolve($event);
        if ($recipientIds === []) {
            return [];
        }

        $created = [];
        $presentation = $this->presentation($event, $type, $kind);

        foreach ($recipientIds as $userId) {
            $user = User::query()->find($userId);
            if ($user === null) {
                continue;
            }

            if (! $this->preferences->isInAppEnabled($user, $preferenceKey)) {
                continue;
            }

            $existing = UserNotification::query()
                ->where('domain_event_id', $event->id)
                ->where('recipient_user_id', $userId)
                ->where('notification_kind', $kind->value)
                ->first();

            if ($existing instanceof UserNotification) {
                $created[] = $existing;

                continue;
            }

            try {
                $created[] = UserNotification::query()->create([
                    'domain_event_id' => $event->id,
                    'recipient_user_id' => $userId,
                    'notification_kind' => $kind->value,
                    'subject_kind' => $event->subject_kind instanceof DomainEventSubjectKind
                        ? $event->subject_kind->value
                        : (string) $event->subject_kind,
                    'subject_id' => $event->subject_id,
                    'customer_id' => $event->customer_id,
                    'brand_id' => $event->brand_id,
                    'presentation' => $presentation,
                ]);
            } catch (UniqueConstraintViolationException) {
                $row = UserNotification::query()
                    ->where('domain_event_id', $event->id)
                    ->where('recipient_user_id', $userId)
                    ->where('notification_kind', $kind->value)
                    ->first();
                if ($row instanceof UserNotification) {
                    $created[] = $row;
                }
            }
        }

        return $created;
    }

    /**
     * @return array{title_key: string, title: string, body_key: string, body_params: array<string, mixed>, subject_label: string}
     */
    private function presentation(DomainEvent $event, DomainEventType $type, NotificationKind $kind): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $subjectLabel = $this->subjectLabel($event, $payload);
        $title = $this->titleFor($type, $subjectLabel);
        $bodyParams = [
            'subject_label' => $subjectLabel,
            'subject_id' => (int) $event->subject_id,
        ];

        return [
            'title_key' => 'notifications.'.$kind->value.'.title',
            'title' => $title,
            'body_key' => 'notifications.'.$kind->value.'.body',
            'body_params' => $bodyParams,
            'subject_label' => $subjectLabel,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function subjectLabel(DomainEvent $event, array $payload): string
    {
        foreach (['title', 'title_snapshot', 'subject_title', 'subject_title_snapshot'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return mb_substr(trim($payload[$key]), 0, 160);
            }
        }

        $kind = $event->subject_kind instanceof DomainEventSubjectKind
            ? $event->subject_kind->value
            : (string) $event->subject_kind;

        return ucwords(str_replace('_', ' ', $kind)).' #'.$event->subject_id;
    }

    private function titleFor(DomainEventType $type, string $subjectLabel): string
    {
        return match ($type) {
            DomainEventType::FindingCreated => 'New finding: '.$subjectLabel,
            DomainEventType::RecommendationAccepted => 'Recommendation accepted: '.$subjectLabel,
            DomainEventType::TaskCompleted => 'Task completed: '.$subjectLabel,
            DomainEventType::TaskAssigned => 'Task assigned: '.$subjectLabel,
            DomainEventType::QaPassed => 'QA passed: '.$subjectLabel,
            DomainEventType::QaFailed => 'QA failed: '.$subjectLabel,
            DomainEventType::QaNeedsChanges => 'QA needs changes: '.$subjectLabel,
            DomainEventType::ApprovalApproved => 'Approval approved: '.$subjectLabel,
            DomainEventType::ApprovalRejected => 'Approval rejected: '.$subjectLabel,
            DomainEventType::ApprovalChangesRequested => 'Approval changes requested: '.$subjectLabel,
            DomainEventType::RecurringReviewCompleted => 'Recurring review completed: '.$subjectLabel,
            DomainEventType::ClientRequestCreated => 'Client request received: '.$subjectLabel,
            DomainEventType::OpportunityCreated => 'New opportunity: '.$subjectLabel,
        };
    }
}
