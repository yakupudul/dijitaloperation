<?php

namespace App\Services\Notifications;

use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Observability\OperationalAlertExplainer;
use App\Support\ServiceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only UserNotification queries. No writes.
 */
final class NotificationReadService
{
    private const string ALERT = 'operational_alert';

    private const array ACTIVE_ALERT_STATES = ['OPEN', 'ACKNOWLEDGED'];

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

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get();

        return $this->oneRowPerAlert($rows)
            ->take($limit)
            ->map(fn (UserNotification $row): array => $this->toPresentation($row))
            ->values()
            ->all();
    }

    /**
     * Unread rows; a system alert counts once however many rows it has, and not at all once it is resolved.
     */
    public function unreadCount(User $user): int
    {
        $unread = fn () => $this->inServiceScope(UserNotification::query())
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('archived_at');

        $others = (int) $unread()->where('subject_kind', '!=', self::ALERT)->count();
        $alerts = (int) $unread()->where('subject_kind', self::ALERT)
            ->whereIn('subject_id', OperationalAlert::query()->whereIn('state', self::ACTIVE_ALERT_STATES)->select('id'))
            ->distinct()->count('subject_id');

        return $others + $alerts;
    }

    /**
     * System alerts: one row per alert condition (the newest), and none for a condition that is resolved. The live
     * alert is attached, so the bell reads its current wording and counters.
     *
     * @param  Collection<int, UserNotification>  $rows
     * @return Collection<int, UserNotification>
     */
    private function oneRowPerAlert(Collection $rows): Collection
    {
        $alertIds = $rows->filter(fn (UserNotification $row): bool => self::isAlert($row))->pluck('subject_id')->unique()->values();
        if ($alertIds->isEmpty()) {
            return $rows;
        }
        $alerts = OperationalAlert::query()->whereIn('id', $alertIds)->whereIn('state', self::ACTIVE_ALERT_STATES)->get()->keyBy('id');
        $seen = [];

        return $rows->filter(function (UserNotification $row) use ($alerts, &$seen): bool {
            if (! self::isAlert($row)) {
                return true;
            }
            $id = (int) $row->subject_id;
            if (isset($seen[$id]) || ! $alerts->has($id)) {
                return false;
            }
            $seen[$id] = true;
            $row->setRelation('operationalAlert', $alerts->get($id));

            return true;
        })->values();
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
        return $this->inServiceScope(UserNotification::query())
            ->where('recipient_user_id', $user->id)
            ->with(['domainEvent', 'brand:id,name', 'customer:id,name']);
    }

    /**
     * Notifications about a passive customer's brand are hidden (not deleted); they come back on reactivation.
     *
     * @param  Builder<UserNotification>  $query
     * @return Builder<UserNotification>
     */
    private function inServiceScope(Builder $query): Builder
    {
        $scope = app(ServiceScope::class);

        return $scope->constrainCustomer($scope->constrain($query, null));
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
            'operator_message' => $this->operatorMessage($row),
        ];
    }

    private static function isAlert(UserNotification $row): bool
    {
        $kind = $row->subject_kind instanceof \BackedEnum ? $row->subject_kind->value : (string) $row->subject_kind;

        return $kind === self::ALERT;
    }

    /** @return array<string, mixed>|null the live alert's Ne oldu / Neden önemli / Ne yapmalısın / Nereden */
    private function operatorMessage(UserNotification $row): ?array
    {
        if (! self::isAlert($row)) {
            return null;
        }
        $alert = $row->relationLoaded('operationalAlert') ? $row->getRelation('operationalAlert') : OperationalAlert::query()->find($row->subject_id);

        return $alert instanceof OperationalAlert ? app(OperationalAlertExplainer::class)->explain($alert)->toArray() : null;
    }
}
