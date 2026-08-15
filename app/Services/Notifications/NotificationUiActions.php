<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Thin Livewire/UI adapter for in-app notification actions.
 */
final class NotificationUiActions
{
    public function __construct(
        private readonly NotificationWriteService $writes,
        private readonly NotificationReadService $reads,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function markRead(User $user, int|string $id): array
    {
        if (! is_numeric($id)) {
            return ['ok' => false, 'message' => 'Notification not found.'];
        }

        $row = $this->writes->markRead($user, (int) $id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Notification not found.'];
        }

        return ['ok' => true, 'message' => 'Notification marked as read.'];
    }

    /**
     * @return array{ok: bool, message: string, marked?: int}
     */
    public function markAllRead(User $user, ?Carbon $before = null): array
    {
        $marked = $this->writes->markAllRead($user, $before);

        return [
            'ok' => true,
            'message' => $marked === 0
                ? 'No unread notifications.'
                : "Marked {$marked} notification(s) as read.",
            'marked' => $marked,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function archive(User $user, int|string $id): array
    {
        if (! is_numeric($id)) {
            return ['ok' => false, 'message' => 'Notification not found.'];
        }

        $row = $this->writes->archive($user, (int) $id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Notification not found.'];
        }

        return ['ok' => true, 'message' => 'Notification archived.'];
    }

    public function unreadCount(User $user): int
    {
        return $this->reads->unreadCount($user);
    }
}
