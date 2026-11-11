<?php

namespace App\Services\Queries;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\NotificationKind;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DomainEvents\DomainEventEmitter;
use Illuminate\Support\Str;

/**
 * Important Sorgular notifications (rescan review ready, first import done, AI step failed): one in-app notification
 * for the operator who started the work, opening its target; also shown once as a toast (NotificationToast).
 */
final class QueryNotifier
{
    public function __construct(private readonly DomainEventEmitter $events) {}

    /**
     * $replaceEarlier: a recurring notice (the daily Hata merkezi digest, the weekly Şef plan) takes the place of its
     * earlier ones to the same target, so the bell holds one row instead of one per day (Günlük Düzen 1.1).
     */
    public function send(?int $userId, string $title, ?string $url, int $subjectId = 0, bool $replaceEarlier = false): void
    {
        $recipients = $userId !== null ? [$userId] : User::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        if ($recipients === []) {
            return;
        }
        if ($replaceEarlier && $url !== null) {
            UserNotification::query()->where('notification_kind', NotificationKind::QueriesNotice->value)->whereNull('archived_at')
                ->whereIn('recipient_user_id', $recipients)->where('presentation->url', $url)->update(['archived_at' => now()]);
        }
        $this->events->emit([
            'event_type' => DomainEventType::QueriesNotice,
            'actor_kind' => DomainEventActorKind::System,
            'subject_kind' => DomainEventSubjectKind::Queries,
            'subject_id' => $subjectId,
            'payload' => ['title' => mb_substr($title, 0, 160), 'url' => $url, 'recipient_user_ids' => array_map('intval', $recipients)],
        ], 'queries-notice:'.Str::uuid());
    }
}
