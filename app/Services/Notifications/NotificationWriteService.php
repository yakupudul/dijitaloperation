<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Carbon;

/**
 * UserNotification write boundary (read/archive only). Does not mutate DomainEvent.
 */
final class NotificationWriteService
{
    /**
     * Idempotent mark-read for a notification owned by the user.
     */
    public function markRead(User $user, int $id): ?UserNotification
    {
        $row = UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereKey($id)
            ->first();

        if (! $row instanceof UserNotification) {
            return null;
        }

        if ($row->read_at === null) {
            $row->forceFill(['read_at' => Carbon::now()])->save();
        }

        return $row->fresh() ?? $row;
    }

    /**
     * Mark all of the user's unread notifications as read where created_at <= $before.
     * Concurrency-safe: scoped by created_at upper bound (default now at call time).
     */
    public function markAllRead(User $user, ?Carbon $before = null): int
    {
        $before ??= Carbon::now();

        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->where('created_at', '<=', $before)
            ->update(['read_at' => $before]);
    }

    /**
     * Soft-archive. Does not mutate domain facts.
     */
    public function archive(User $user, int $id): ?UserNotification
    {
        $row = UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereKey($id)
            ->first();

        if (! $row instanceof UserNotification) {
            return null;
        }

        if ($row->archived_at === null) {
            $row->forceFill(['archived_at' => Carbon::now()])->save();
        }

        return $row->fresh() ?? $row;
    }
}
