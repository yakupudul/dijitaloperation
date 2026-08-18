<?php

namespace App\Services\DomainEvents;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\DomainEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Durable Domain Event write boundary. Idempotent on idempotency_key.
 * Projects Activity + Notifications in-process. Safe to call inside DB::transaction.
 */
final class DomainEventEmitter
{
    public function __construct(
        private readonly MeaningfulEventProjector $projector,
    ) {}

    /**
     * @param  array{
     *     event_type: string|DomainEventType,
     *     actor_kind: string|DomainEventActorKind,
     *     actor_user_id?: int|null,
     *     customer_id?: int|null,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     subject_kind: string|DomainEventSubjectKind,
     *     subject_id: int,
     *     payload?: array<string, mixed>|null,
     *     correlation_id?: string|null,
     *     causation_event_id?: int|null,
     *     occurred_at?: Carbon|string|null,
     * }  $input
     */
    public function emit(array $input, ?string $idempotencyKey = null): DomainEvent
    {
        $data = Validator::make($input, [
            'event_type' => ['required'],
            'actor_kind' => ['required'],
            'actor_user_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'digital_asset_id' => ['nullable', 'integer'],
            'subject_kind' => ['required'],
            'subject_id' => ['required', 'integer'],
            'payload' => ['nullable', 'array'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
            'causation_event_id' => ['nullable', 'integer'],
            'occurred_at' => ['nullable'],
        ])->validate();

        $eventType = $data['event_type'] instanceof DomainEventType
            ? $data['event_type']
            : DomainEventType::from((string) $data['event_type']);

        $actorKind = $data['actor_kind'] instanceof DomainEventActorKind
            ? $data['actor_kind']
            : DomainEventActorKind::from((string) $data['actor_kind']);

        $subjectKind = $data['subject_kind'] instanceof DomainEventSubjectKind
            ? $data['subject_kind']
            : DomainEventSubjectKind::from((string) $data['subject_kind']);

        $subjectId = (int) $data['subject_id'];
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : null;

        $key = $this->resolveIdempotencyKey(
            $eventType,
            $subjectKind,
            $subjectId,
            $payload,
            $idempotencyKey,
        );

        $existing = DomainEvent::query()->where('idempotency_key', $key)->first();
        if ($existing instanceof DomainEvent) {
            return $this->ensureProjected($existing);
        }

        $occurredAt = $this->resolveOccurredAt($data['occurred_at'] ?? null);

        try {
            $event = DomainEvent::query()->create([
                'event_type' => $eventType->value,
                'idempotency_key' => $key,
                'actor_kind' => $actorKind->value,
                'actor_user_id' => $data['actor_user_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'digital_asset_id' => $data['digital_asset_id'] ?? null,
                'subject_kind' => $subjectKind->value,
                'subject_id' => $subjectId,
                'payload' => $payload,
                'correlation_id' => $data['correlation_id'] ?? null,
                'causation_event_id' => $data['causation_event_id'] ?? null,
                'occurred_at' => $occurredAt,
                'projection_status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $race = DomainEvent::query()->where('idempotency_key', $key)->first();
            if ($race instanceof DomainEvent) {
                return $this->ensureProjected($race);
            }

            throw $exception;
        }

        return $this->ensureProjected($event);
    }

    private function ensureProjected(DomainEvent $event): DomainEvent
    {
        if ($event->projection_status === 'projected') {
            return $event;
        }

        $this->projector->project($event);

        $event->forceFill(['projection_status' => 'projected'])->save();

        return $event->fresh() ?? $event;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function resolveIdempotencyKey(
        DomainEventType $type,
        DomainEventSubjectKind $subjectKind,
        int $subjectId,
        ?array $payload,
        ?string $provided,
    ): string {
        if ($provided !== null && $provided !== '') {
            return $provided;
        }

        return match ($type) {
            DomainEventType::FindingCreated => "FINDING_CREATED:finding:{$subjectId}",
            DomainEventType::TaskCompleted => "TASK_COMPLETED:task:{$subjectId}",
            DomainEventType::TaskAssigned => sprintf(
                'TASK_ASSIGNED:task:%d:assignee:%s',
                $subjectId,
                isset($payload['assignee_id']) ? (string) $payload['assignee_id'] : 'none',
            ),
            DomainEventType::RecommendationAccepted => "RECOMMENDATION_ACCEPTED:recommendation:{$subjectId}",
            DomainEventType::QaPassed => "QA_PASSED:qa_review:{$subjectId}",
            DomainEventType::ApprovalApproved => "APPROVAL_APPROVED:approval:{$subjectId}",
            DomainEventType::RecurringReviewCompleted => "RECURRING_REVIEW_COMPLETED:recurring_review_run:{$subjectId}",
            DomainEventType::ClientRequestCreated => "CLIENT_REQUEST_CREATED:client_request:{$subjectId}",
            DomainEventType::OpportunityCreated => "OPPORTUNITY_CREATED:opportunity:{$subjectId}",
            default => sprintf(
                '%s:%s:%d:%s',
                $type->value,
                $subjectKind->value,
                $subjectId,
                $this->transitionHash($payload),
            ),
        };
    }

    /**
     * Stable hash of transition-identity payload fields (excludes volatile notes/timestamps).
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function transitionHash(?array $payload): string
    {
        if ($payload === null || $payload === []) {
            return 'none';
        }

        $identity = [];
        foreach (
            [
                'assignee_id',
                'from_status',
                'to_status',
                'status',
                'result',
                'decision',
                'severity',
                'transition',
            ] as $field
        ) {
            if (array_key_exists($field, $payload)) {
                $identity[$field] = $payload[$field];
            }
        }

        if ($identity === []) {
            return 'none';
        }

        ksort($identity);

        return substr(hash('sha256', (string) json_encode($identity)), 0, 16);
    }

    private function resolveOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return Carbon::now();
    }
}
