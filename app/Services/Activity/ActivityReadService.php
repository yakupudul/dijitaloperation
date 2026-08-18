<?php

namespace App\Services\Activity;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\BrandContextActivity;
use App\Models\DomainEvent;
use App\Support\Work\WorkUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only Activity feed for Operations Activity Index.
 * Unifies domain-event-backed BrandContextActivity rows and legacy rows (domain_event_id null).
 * Also includes DomainEvents that skipped Activity (brand_id null) so customer-scoped facts appear.
 * NO writes.
 */
final class ActivityReadService
{
    /**
     * @param  array{
     *     brand_id?: int|null,
     *     customer_id?: int|null,
     *     digital_asset_id?: int|null,
     *     actor?: string|null,
     *     period?: string|null,
     *     limit?: int,
     *     offset?: int,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function forList(array $filters = []): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $since = $this->periodSince($filters['period'] ?? null);

        // Bound SQL reads before merge (Prompt 65). Over-fetch enough rows to satisfy
        // offset+limit after unifying two sources without loading unbounded history.
        $sqlLimit = min(500, $offset + $limit);

        $activities = $this->activityQuery($filters, $since)->limit($sqlLimit)->get();
        $orphanEvents = $this->orphanDomainEventQuery($filters, $since)->limit($sqlLimit)->get();

        $rows = collect()
            ->concat($activities->map(fn (BrandContextActivity $row): array => $this->fromActivity($row)))
            ->concat($orphanEvents->map(fn (DomainEvent $event): array => $this->fromDomainEvent($event)));

        $actorFilter = $filters['actor'] ?? null;
        if (is_string($actorFilter) && $actorFilter !== '' && $actorFilter !== 'all') {
            $rows = $rows->filter(function (array $row) use ($actorFilter): bool {
                $kind = (string) ($row['actor_kind'] ?? '');

                return match ($actorFilter) {
                    'system' => $kind === 'system',
                    'human' => in_array($kind, ['human', 'internal_user', 'client_contact'], true),
                    default => $kind === $actorFilter,
                };
            });
        }

        return $rows
            ->sort(function (array $a, array $b): int {
                $aAt = (string) ($a['occurred_at'] ?? $a['created_at'] ?? '');
                $bAt = (string) ($b['occurred_at'] ?? $b['created_at'] ?? '');
                $cmp = strcmp($bAt, $aAt);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((int) ($b['sort_id'] ?? 0)) <=> ((int) ($a['sort_id'] ?? 0));
            })
            ->values()
            ->slice($offset, $limit)
            ->map(function (array $row): array {
                unset($row['sort_id'], $row['occurred_at']);

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BrandContextActivity>
     */
    private function activityQuery(array $filters, ?Carbon $since): Builder
    {
        $query = BrandContextActivity::query()
            ->with([
                'brand:id,name',
                'actor:id,name',
                'customer:id,name',
                'domainEvent',
            ]);

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if ($since !== null) {
            $query->where(function (Builder $inner) use ($since): void {
                $inner->where('occurred_at', '>=', $since)
                    ->orWhere(function (Builder $legacy) use ($since): void {
                        $legacy->whereNull('occurred_at')->where('created_at', '>=', $since);
                    });
            });
        }

        return $query->orderByRaw('COALESCE(occurred_at, created_at) desc')->orderByDesc('id');
    }

    /**
     * Domain events with no BrandContextActivity (typically brand_id null).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<DomainEvent>
     */
    private function orphanDomainEventQuery(array $filters, ?Carbon $since): Builder
    {
        $query = DomainEvent::query()
            ->whereDoesntHave('activity')
            ->with(['brand:id,name', 'customer:id,name', 'actorUser:id,name']);

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if ($since !== null) {
            $query->where('occurred_at', '>=', $since);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function fromActivity(BrandContextActivity $row): array
    {
        $at = $row->occurred_at ?? $row->created_at ?? Carbon::now();
        $eventValue = (string) $row->event;
        $payload = is_array($row->payload) ? $row->payload : [];
        $actorKind = $this->normalizeActorKind($row->actor_kind);
        $subjectRoute = $this->destinationForSubjectType($row->subject_type, $eventValue, $row->subject_id);

        return [
            'id' => 'activity:'.$row->id,
            'sort_id' => (int) $row->id,
            'title' => $this->titleForEvent($eventValue, $payload),
            'detail' => $this->detailFromPayload($payload),
            'actor' => $row->actor?->name ?? ($actorKind === 'system' ? 'System' : 'Unknown'),
            'actor_kind' => $actorKind,
            'status' => 'success',
            'brand' => $row->brand?->name,
            'brand_id' => $row->brand_id,
            'customer' => $row->customer?->name,
            'customer_id' => $row->customer_id,
            'created_at' => $at instanceof Carbon ? $at->toIso8601String() : (string) $at,
            'occurred_at' => $at instanceof Carbon ? $at->toIso8601String() : (string) $at,
            'relative' => $at instanceof Carbon ? $at->diffForHumans() : null,
            'route' => $subjectRoute['route'],
            'route_params' => $subjectRoute['params'],
            'event' => $eventValue,
            'event_label' => $this->eventLabel($eventValue),
            'domain_event_id' => $row->domain_event_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromDomainEvent(DomainEvent $event): array
    {
        $type = $event->event_type instanceof DomainEventType
            ? $event->event_type->value
            : (string) $event->event_type;
        $payload = is_array($event->payload) ? $event->payload : [];
        $at = $event->occurred_at ?? $event->created_at ?? Carbon::now();
        $actorKind = $this->normalizeActorKind(
            $event->actor_kind instanceof DomainEventActorKind
                ? $event->actor_kind->value
                : (string) $event->actor_kind
        );
        $subjectId = $event->subject_id !== null ? (int) $event->subject_id : null;
        $subjectRoute = $this->destinationForSubjectKind($event->subject_kind, $type, $subjectId);

        return [
            'id' => 'domain_event:'.$event->id,
            'sort_id' => (int) $event->id,
            'title' => $this->titleForEvent($type, $payload),
            'detail' => $this->detailFromPayload($payload),
            'actor' => $event->actorUser?->name ?? ($actorKind === 'system' ? 'System' : 'Unknown'),
            'actor_kind' => $actorKind,
            'status' => 'success',
            'brand' => $event->brand?->name,
            'brand_id' => $event->brand_id,
            'customer' => $event->customer?->name,
            'customer_id' => $event->customer_id,
            'created_at' => $at instanceof Carbon ? $at->toIso8601String() : (string) $at,
            'occurred_at' => $at instanceof Carbon ? $at->toIso8601String() : (string) $at,
            'relative' => $at instanceof Carbon ? $at->diffForHumans() : null,
            'route' => $subjectRoute['route'],
            'route_params' => $subjectRoute['params'],
            'event' => $type,
            'event_label' => $this->eventLabel($type),
            'domain_event_id' => $event->id,
        ];
    }

    private function normalizeActorKind(?string $kind): string
    {
        return match ($kind) {
            'internal_user', 'client_contact', 'human' => $kind === 'human' ? 'human' : $kind,
            'system' => 'system',
            null, '' => 'system',
            default => $kind,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleForEvent(string $event, array $payload): string
    {
        if (isset($payload['title']) && is_string($payload['title']) && $payload['title'] !== '') {
            return $this->eventLabel($event).': '.$payload['title'];
        }

        return $this->eventLabel($event);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function detailFromPayload(array $payload): ?string
    {
        $parts = [];
        foreach (['finding_count', 'task_count', 'opportunity_count', 'check_count'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $parts[] = str_replace('_', ' ', $key).': '.(int) $payload[$key];
            }
        }
        if (isset($payload['severity']) && is_string($payload['severity'])) {
            $parts[] = 'severity '.$payload['severity'];
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            $parts[] = 'status '.$payload['status'];
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function eventLabel(string $event): string
    {
        try {
            $type = DomainEventType::from($event);

            return match ($type) {
                DomainEventType::FindingCreated => 'Finding created',
                DomainEventType::RecommendationAccepted => 'Recommendation accepted',
                DomainEventType::TaskCompleted => 'Task completed',
                DomainEventType::TaskAssigned => 'Task assigned',
                DomainEventType::QaPassed => 'QA passed',
                DomainEventType::QaFailed => 'QA failed',
                DomainEventType::QaNeedsChanges => 'QA needs changes',
                DomainEventType::ApprovalApproved => 'Approval approved',
                DomainEventType::ApprovalRejected => 'Approval rejected',
                DomainEventType::ApprovalChangesRequested => 'Approval changes requested',
                DomainEventType::RecurringReviewCompleted => 'Recurring review completed',
                DomainEventType::ClientRequestCreated => 'Client request created',
                DomainEventType::OpportunityCreated => 'Opportunity created',
                DomainEventType::ScheduledInternalNotification => 'Scheduled notification',
                DomainEventType::BusinessOutcomeRecheckAttention => 'Business Outcome recheck attention',
                DomainEventType::OperationalAlertOpened => 'Operational alert opened',
            };
        } catch (\ValueError) {
            return ucwords(strtolower(str_replace('_', ' ', $event)));
        }
    }

    /**
     * @return array{route: ?string, params: array<string, mixed>}
     */
    private function destinationForSubjectType(?string $subjectType, string $event, mixed $subjectId): array
    {
        if ($subjectType !== null) {
            $typed = match (true) {
                str_ends_with($subjectType, '\\Task') => WorkUrl::TYPE_TASK,
                str_ends_with($subjectType, '\\ClientRequest') => WorkUrl::TYPE_CLIENT_REQUEST,
                str_ends_with($subjectType, '\\Approval') => WorkUrl::TYPE_APPROVAL,
                str_ends_with($subjectType, '\\RecurringReviewRun') => WorkUrl::TYPE_RECURRING_REVIEW,
                default => null,
            };

            if ($typed !== null && is_numeric($subjectId)) {
                return [
                    'route' => 'operator.work.show',
                    'params' => WorkUrl::parameters($typed, $subjectId),
                ];
            }

            return [
                'route' => $this->routeForSubject($subjectType, $event),
                'params' => [],
            ];
        }

        return [
            'route' => $this->routeForEventType($event),
            'params' => [],
        ];
    }

    /**
     * @return array{route: ?string, params: array<string, mixed>}
     */
    private function destinationForSubjectKind(mixed $kind, string $event, ?int $subjectId): array
    {
        $typed = match (true) {
            $kind === DomainEventSubjectKind::Task || $kind === DomainEventSubjectKind::Task->value => WorkUrl::TYPE_TASK,
            $kind === DomainEventSubjectKind::ClientRequest || $kind === DomainEventSubjectKind::ClientRequest->value => WorkUrl::TYPE_CLIENT_REQUEST,
            $kind === DomainEventSubjectKind::Approval || $kind === DomainEventSubjectKind::Approval->value => WorkUrl::TYPE_APPROVAL,
            $kind === DomainEventSubjectKind::RecurringReviewRun || $kind === DomainEventSubjectKind::RecurringReviewRun->value => WorkUrl::TYPE_RECURRING_REVIEW,
            default => null,
        };

        if ($typed !== null && $subjectId !== null) {
            return [
                'route' => 'operator.work.show',
                'params' => WorkUrl::parameters($typed, $subjectId),
            ];
        }

        return [
            'route' => $this->routeForEventType($event),
            'params' => [],
        ];
    }

    private function routeForSubject(?string $subjectType, string $event): ?string
    {
        if ($subjectType !== null) {
            return match (true) {
                str_ends_with($subjectType, '\\Finding') => 'operator.findings',
                str_ends_with($subjectType, '\\Opportunity') => 'operator.opportunities',
                str_ends_with($subjectType, '\\Recommendation') => 'operator.recommendations',
                str_ends_with($subjectType, '\\Task') => 'operator.work.show',
                str_ends_with($subjectType, '\\ClientRequest') => 'operator.work.show',
                str_ends_with($subjectType, '\\QaReview') => 'operator.tasks',
                str_ends_with($subjectType, '\\Approval') => 'operator.work.show',
                str_ends_with($subjectType, '\\RecurringReviewRun') => 'operator.work.show',
                str_ends_with($subjectType, '\\Playbook') => 'operator.settings',
                default => $this->routeForEventType($event),
            };
        }

        return $this->routeForEventType($event);
    }

    private function routeForEventType(string $event): ?string
    {
        try {
            $type = DomainEventType::from($event);

            return match ($type) {
                DomainEventType::FindingCreated => 'operator.findings',
                DomainEventType::OpportunityCreated => 'operator.opportunities',
                DomainEventType::RecommendationAccepted => 'operator.recommendations',
                DomainEventType::TaskCompleted, DomainEventType::TaskAssigned => 'operator.tasks',
                DomainEventType::QaPassed, DomainEventType::QaFailed, DomainEventType::QaNeedsChanges => 'operator.tasks',
                DomainEventType::ApprovalApproved, DomainEventType::ApprovalRejected, DomainEventType::ApprovalChangesRequested => 'operator.tasks',
                DomainEventType::ClientRequestCreated, DomainEventType::RecurringReviewCompleted => 'operator.work.show',
                DomainEventType::ScheduledInternalNotification,
                DomainEventType::BusinessOutcomeRecheckAttention,
                DomainEventType::OperationalAlertOpened => 'operator.activity',
            };
        } catch (\ValueError) {
            return 'operator.activity';
        }
    }

    private function periodSince(?string $period): ?Carbon
    {
        $days = match ($period) {
            'last_7' => 7,
            'last_14' => 14,
            'last_28' => 28,
            'last_90' => 90,
            null, '', 'all' => null,
            default => 28,
        };

        return $days === null ? null : Carbon::now()->subDays($days);
    }
}
