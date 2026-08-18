<?php

namespace App\Services\Activity;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\BrandContextActivity;
use App\Models\DomainEvent;
use App\Services\Notifications\NotificationPolicyRegistry;
use App\Support\DomainEvents\SubjectKindModelMap;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Projects a DomainEvent into BrandContextActivity.
 *
 * brand_context_activities.brand_id is NOT NULL (FK). When DomainEvent.brand_id is null,
 * Activity write is skipped and null is returned — customer-scoped events without brand
 * are valid with zero BrandContextActivity rows. Never invent a "first brand" fallback.
 */
final class ActivityProjector
{
    public function __construct(
        private readonly NotificationPolicyRegistry $policy,
    ) {}

    public function project(DomainEvent $event): ?BrandContextActivity
    {
        $existing = BrandContextActivity::query()
            ->where('domain_event_id', $event->id)
            ->first();
        if ($existing instanceof BrandContextActivity) {
            return $existing;
        }

        $type = $event->event_type instanceof DomainEventType
            ? $event->event_type
            : DomainEventType::from((string) $event->event_type);

        if (! $this->policy->shouldCreateActivity($type)) {
            return null;
        }

        if ($event->brand_id === null) {
            return null;
        }

        $subjectKind = $event->subject_kind instanceof DomainEventSubjectKind
            ? $event->subject_kind
            : DomainEventSubjectKind::from((string) $event->subject_kind);

        $actorKind = $event->actor_kind instanceof DomainEventActorKind
            ? $event->actor_kind->value
            : (string) $event->actor_kind;

        $occurredAt = $event->occurred_at instanceof Carbon
            ? $event->occurred_at
            : Carbon::parse((string) $event->occurred_at);

        try {
            return BrandContextActivity::query()->create([
                'domain_event_id' => $event->id,
                'brand_id' => $event->brand_id,
                'customer_id' => $event->customer_id,
                'digital_asset_id' => $event->digital_asset_id,
                'actor_user_id' => $event->actor_user_id,
                'actor_kind' => $actorKind,
                'event' => $type->value,
                'subject_type' => SubjectKindModelMap::modelClass($subjectKind),
                'subject_id' => $event->subject_id,
                'payload' => $this->safePresentationPayload($event, $type),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return BrandContextActivity::query()
                ->where('domain_event_id', $event->id)
                ->first();
        }
    }

    /**
     * Safe presentation snapshot only — title / counts / ids. Never full notes or secrets.
     *
     * @return array<string, mixed>
     */
    private function safePresentationPayload(DomainEvent $event, DomainEventType $type): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];

        $safe = [
            'event_type' => $type->value,
            'subject_kind' => $event->subject_kind instanceof DomainEventSubjectKind
                ? $event->subject_kind->value
                : (string) $event->subject_kind,
            'subject_id' => (int) $event->subject_id,
        ];

        foreach (['title', 'title_snapshot', 'subject_title', 'subject_title_snapshot', 'summary'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '') {
                $safe['title'] = mb_substr($payload[$key], 0, 240);
                break;
            }
        }

        foreach (['finding_count', 'task_count', 'opportunity_count', 'check_count', 'item_count'] as $countKey) {
            if (isset($payload[$countKey]) && is_numeric($payload[$countKey])) {
                $safe[$countKey] = (int) $payload[$countKey];
            }
        }

        if (isset($payload['severity']) && is_string($payload['severity'])) {
            $safe['severity'] = $payload['severity'];
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            $safe['status'] = $payload['status'];
        }
        if (isset($payload['priority']) && is_string($payload['priority'])) {
            $safe['priority'] = $payload['priority'];
        }

        return $safe;
    }
}
