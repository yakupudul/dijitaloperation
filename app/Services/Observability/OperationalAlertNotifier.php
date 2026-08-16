<?php

namespace App\Services\Observability;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\Roles;
use Throwable;

/**
 * Opens at most one Prompt47 Notification per Alert open.
 * Zero recipients: Alert remains OPEN — no notify-all.
 */
final class OperationalAlertNotifier
{
    public function __construct(
        private readonly DomainEventEmitter $events,
    ) {}

    public function notifyOpened(OperationalAlert $alert): void
    {
        if (! config('moxdop-observability.alert.notify_on_open', true)) {
            return;
        }
        if ($alert->notification_emitted) {
            return;
        }

        $recipients = $this->recipientIds();
        if ($recipients === []) {
            // Persist alert without spam fallback.
            return;
        }

        try {
            $this->events->emit([
                'event_type' => DomainEventType::OperationalAlertOpened,
                'actor_kind' => DomainEventActorKind::System,
                'subject_kind' => DomainEventSubjectKind::OperationalAlert,
                'subject_id' => (int) $alert->id,
                'payload' => [
                    'recipient_user_ids' => $recipients,
                    'rule_key' => $alert->rule_key,
                    'severity' => $alert->severity->value,
                    'title' => $alert->title,
                    'scope_type' => $alert->scope_type,
                    'scope_key' => $alert->scope_key,
                ],
            ], 'ops-alert-open:'.$alert->semantic_key.':'.$alert->opened_at?->getTimestamp());

            $alert->notification_emitted = true;
            $alert->save();
        } catch (Throwable) {
            // Notification failure must not roll back alert persistence.
        }
    }

    public function notifyResolved(OperationalAlert $alert): void
    {
        if (! config('moxdop-observability.alert.notify_on_resolve', false)) {
            return;
        }
        // Optional recovery notification — default off to reduce noise.
    }

    /**
     * @return list<int>
     */
    public function recipientIds(): array
    {
        $configured = config('moxdop-observability.alert.recipient_user_ids', []);
        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_map('intval', $configured)));
        }

        return User::role(Roles::ADMIN)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
