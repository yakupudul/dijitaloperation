<?php

namespace App\Services\Notifications;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\InternalNotificationSchedule;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use Illuminate\Validation\ValidationException;

/**
 * Creates Prompt47 in-app notifications for a scheduled internal notification occurrence.
 */
final class ScheduledInternalNotificationService
{
    /** @var list<string> */
    private const SAFE_ROUTES = [
        'operator.work',
        'operator.work.show',
        'operator.findings',
        'operator.tasks',
        'operator.notifications',
        'operator.portfolio.brand',
        'operator.portfolio.customer',
    ];

    public function __construct(
        private readonly DomainEventEmitter $events,
    ) {}

    /**
     * @return list<int> domain event ids created (one batch event with multi recipients)
     */
    public function deliver(InternalNotificationSchedule $schedule, RecurringOccurrence $occurrence): array
    {
        $title = trim(strip_tags((string) $schedule->title));
        $message = trim(strip_tags((string) $schedule->message));
        if ($title === '' || $message === '') {
            throw ValidationException::withMessages(['message' => 'NOTIFICATION_CONTENT_REQUIRED']);
        }

        $route = $schedule->safe_route_name;
        if ($route !== null && $route !== '' && ! in_array($route, self::SAFE_ROUTES, true)) {
            throw ValidationException::withMessages(['safe_route_name' => 'UNSAFE_ROUTE']);
        }

        $recipientIds = [];
        foreach ($schedule->recipients as $row) {
            $user = User::query()->find($row->user_id);
            if ($user === null) {
                continue;
            }
            $recipientIds[] = (int) $user->id;
        }
        $recipientIds = array_values(array_unique($recipientIds));
        if ($recipientIds === []) {
            return [];
        }

        $event = $this->events->emit([
            'event_type' => DomainEventType::ScheduledInternalNotification,
            'actor_kind' => DomainEventActorKind::System,
            'customer_id' => $schedule->customer_id,
            'brand_id' => $schedule->brand_id,
            'subject_kind' => DomainEventSubjectKind::InternalNotificationSchedule,
            'subject_id' => (int) $schedule->id,
            'payload' => [
                'title' => mb_substr($title, 0, 160),
                'body' => mb_substr($message, 0, 2000),
                'safe_route_name' => $route,
                'recipient_user_ids' => $recipientIds,
                'recurring_occurrence_id' => (int) $occurrence->id,
            ],
        ], 'internal-notification:'.$occurrence->occurrence_key);

        return [(int) $event->id];
    }
}
