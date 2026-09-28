<?php

namespace App\Services\Observability;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\Roles;
use Throwable;

/**
 * One bell row per alert condition. The first opening emits one Prompt47 Notification; a condition that comes back
 * (the same alert row reopened) updates that row instead of adding a new one: it becomes unread again only when the
 * last ping is older than `reopen_quiet_hours`, so a flapping condition does not ring the bell every few hours.
 * Zero recipients: Alert remains OPEN — no notify-all.
 */
final class OperationalAlertNotifier
{
    public function __construct(
        private readonly DomainEventEmitter $events,
        private readonly OperationalAlertExplainer $explainer,
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
            $message = $this->explainer->explain($alert);
            if ($this->reviveExisting($alert, $message->title, $message->plainText())) {
                $alert->notification_emitted = true;
                $alert->save();

                return;
            }

            $this->events->emit([
                'event_type' => DomainEventType::OperationalAlertOpened,
                'actor_kind' => DomainEventActorKind::System,
                'subject_kind' => DomainEventSubjectKind::OperationalAlert,
                'subject_id' => (int) $alert->id,
                'payload' => [
                    'recipient_user_ids' => $recipients,
                    'rule_key' => $alert->rule_key,
                    'severity' => $alert->severity->value,
                    'title' => $message->title,
                    'summary' => $message->plainText(),
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

    /**
     * Reopened alert: refresh each recipient's newest bell row for it and archive older duplicates.
     * Returns false when the alert never had a bell row (first opening → a new notification is emitted).
     */
    private function reviveExisting(OperationalAlert $alert, string $title, string $summary): bool
    {
        $rows = UserNotification::query()
            ->where('subject_kind', DomainEventSubjectKind::OperationalAlert->value)
            ->where('subject_id', $alert->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();
        if ($rows->isEmpty()) {
            return false;
        }

        $quietHours = max(0, (int) config('moxdop-observability.alert.reopen_quiet_hours', 24));
        foreach ($rows->groupBy('recipient_user_id') as $recipientRows) {
            /** @var UserNotification $newest */
            $newest = $recipientRows->first();
            $presentation = is_array($newest->presentation) ? $newest->presentation : [];
            $presentation['title'] = $title;
            $presentation['subject_label'] = $title;
            $presentation['summary'] = mb_substr($summary, 0, 300);
            $changes = ['presentation' => $presentation, 'archived_at' => null];
            if ($newest->created_at === null || $newest->created_at->lt(now()->subHours($quietHours))) {
                // Back after a quiet period: ping again (unread, on top) — still the same one row.
                $changes['created_at'] = now();
                $changes['read_at'] = null;
            }
            $newest->forceFill($changes)->save();

            $recipientRows->slice(1)->filter(fn (UserNotification $row): bool => $row->archived_at === null)
                ->each(fn (UserNotification $row) => $row->forceFill(['archived_at' => now()])->save());
        }

        return true;
    }
}
