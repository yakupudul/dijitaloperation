<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only UserNotification queries. No writes.
 */
final class NotificationReadService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, bool $unreadOnly = false, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $query = $this->baseQuery($user);
        if ($unreadOnly) {
            $query->whereNull('read_at')->whereNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UserNotification $row): array => $this->toPresentation($row))
            ->all();
    }

    public function unreadCount(User $user): int
    {
        return (int) UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(User $user, int $id): ?array
    {
        $row = UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->whereKey($id)
            ->first();

        return $row instanceof UserNotification ? $this->toPresentation($row) : null;
    }

    /**
     * @return Builder<UserNotification>
     */
    private function baseQuery(User $user): Builder
    {
        return UserNotification::query()
            ->where('recipient_user_id', $user->id)
            ->with(['domainEvent', 'brand:id,name', 'customer:id,name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPresentation(UserNotification $row): array
    {
        $presentation = is_array($row->presentation) ? $row->presentation : [];

        return [
            'id' => (int) $row->id,
            'domain_event_id' => (int) $row->domain_event_id,
            'notification_kind' => $row->notification_kind instanceof \BackedEnum
                ? $row->notification_kind->value
                : (string) $row->notification_kind,
            'subject_kind' => $row->subject_kind instanceof \BackedEnum
                ? $row->subject_kind->value
                : (string) $row->subject_kind,
            'subject_id' => (int) $row->subject_id,
            'customer_id' => $row->customer_id,
            'brand_id' => $row->brand_id,
            'brand' => $row->brand?->name,
            'customer' => $row->customer?->name,
            'title' => $presentation['title'] ?? null,
            'title_key' => $presentation['title_key'] ?? null,
            'body_key' => $presentation['body_key'] ?? null,
            'body_params' => $presentation['body_params'] ?? [],
            'subject_label' => $presentation['subject_label'] ?? null,
            'presentation' => $presentation,
            'read_at' => $row->read_at?->toIso8601String(),
            'archived_at' => $row->archived_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'is_unread' => $row->isUnread(),
        ];
    }
}
