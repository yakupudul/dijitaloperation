<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalActorKind;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalSubjectKind;
use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Exceptions\ApprovalValidationException;
use App\Models\Approval;
use App\Models\CustomerContact;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\Roles;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Approval write boundary. Distinct from QA and Task status.
 */
final class ApprovalService
{
    public function __construct(
        private readonly ApprovalActivityRecorder $activity,
        private readonly DomainEventEmitter $domainEvents,
    ) {}

    /**
     * @param  array{
     *     kind?: string|null,
     *     notes?: string|null,
     *     waiting_on_client?: bool|null,
     * }  $input
     */
    public function request(Task $task, array $input = [], ?User $actor = null, ?string $idempotencyKey = null): Approval
    {
        $this->assertCanAct($actor);
        $task = Task::query()->findOrFail($task->id);

        if ($idempotencyKey !== null) {
            $existing = Approval::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof Approval) {
                return $existing;
            }
        }

        $data = Validator::make($input, [
            'kind' => ['nullable', 'string', Rule::in(array_column(ApprovalKind::cases(), 'value'))],
            'notes' => ['nullable', 'string'],
            'waiting_on_client' => ['nullable', 'boolean'],
        ])->validate();

        $kind = ApprovalKind::from($data['kind'] ?? ApprovalKind::Client->value);
        $waitingOnClient = array_key_exists('waiting_on_client', $data)
            ? (bool) $data['waiting_on_client']
            : $kind === ApprovalKind::Client;

        $active = Approval::query()
            ->where('task_id', $task->id)
            ->where('kind', $kind->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->first();
        if ($active instanceof Approval) {
            return $active;
        }

        try {
            return DB::transaction(function () use ($task, $kind, $waitingOnClient, $data, $actor, $idempotencyKey): Approval {
                $approval = Approval::query()->create([
                    'subject_kind' => ApprovalSubjectKind::Task->value,
                    'task_id' => $task->id,
                    'customer_id' => $task->customer_id,
                    'brand_id' => $task->brand_id,
                    'kind' => $kind->value,
                    'status' => ApprovalStatus::Pending->value,
                    'decision' => null,
                    'requested_by' => $actor?->id,
                    'created_by' => $actor?->id,
                    'notes' => $data['notes'] ?? null,
                    'waiting_on_client' => $waitingOnClient,
                    'subject_fingerprint' => TaskReviewedStateFingerprint::for($task),
                    'subject_title_snapshot' => TaskReviewedStateFingerprint::titleSnapshot($task),
                    'requested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->activity->record($approval, ApprovalActivityRecorder::REQUESTED, $actor);

                return $approval->fresh(['task', 'decidedByUser', 'decidedByCustomerContact']) ?? $approval;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = Approval::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof Approval) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array{
     *     decision: string,
     *     notes?: string|null,
     *     reason?: string|null,
     *     decided_by_actor_kind?: string|null,
     *     decided_by_user_id?: int|null,
     *     decided_by_customer_contact_id?: int|null,
     * }  $input
     */
    public function decide(Approval $approval, array $input, ?User $actor = null): Approval
    {
        $this->assertCanAct($actor);
        $approval = Approval::query()->with('task')->findOrFail($approval->id);

        if ($approval->status === ApprovalStatus::Decided) {
            return $approval;
        }
        if ($approval->status !== ApprovalStatus::Pending) {
            throw new ApprovalValidationException('Only pending Approvals can be decided.');
        }

        $data = Validator::make($input, [
            'decision' => ['required', 'string', Rule::in(array_column(ApprovalDecision::cases(), 'value'))],
            'notes' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'decided_by_actor_kind' => ['nullable', 'string', Rule::in(array_column(ApprovalActorKind::cases(), 'value'))],
            'decided_by_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'decided_by_customer_contact_id' => ['nullable', 'integer', Rule::exists('customer_contacts', 'id')],
        ])->validate();

        $decision = ApprovalDecision::from($data['decision']);
        $actorKind = ApprovalActorKind::from(
            $data['decided_by_actor_kind']
                ?? ($approval->kind === ApprovalKind::Client
                    ? ApprovalActorKind::ClientContact->value
                    : ApprovalActorKind::InternalUser->value)
        );

        $decidedByUserId = null;
        $decidedByContactId = null;

        if ($actorKind === ApprovalActorKind::InternalUser) {
            $decidedByUserId = isset($data['decided_by_user_id'])
                ? (int) $data['decided_by_user_id']
                : $actor?->id;
            if ($decidedByUserId === null) {
                throw ValidationException::withMessages([
                    'decided_by_user_id' => 'Internal Approval decision requires a User.',
                ]);
            }
        } else {
            $decidedByContactId = isset($data['decided_by_customer_contact_id'])
                ? (int) $data['decided_by_customer_contact_id']
                : null;
            if ($decidedByContactId === null) {
                throw ValidationException::withMessages([
                    'decided_by_customer_contact_id' => 'Client Approval decision requires a Customer Contact.',
                ]);
            }
            $contact = CustomerContact::query()->findOrFail($decidedByContactId);
            if ((int) $contact->customer_id !== (int) $approval->customer_id) {
                throw ValidationException::withMessages([
                    'decided_by_customer_contact_id' => 'Client approver must belong to the Approval Customer.',
                ]);
            }
        }

        return DB::transaction(function () use ($approval, $decision, $actorKind, $decidedByUserId, $decidedByContactId, $data, $actor): Approval {
            /** @var Approval $locked */
            $locked = Approval::query()->lockForUpdate()->findOrFail($approval->id);
            if ($locked->status === ApprovalStatus::Decided) {
                return $locked->fresh(['task', 'decidedByUser', 'decidedByCustomerContact']) ?? $locked;
            }
            if ($locked->status !== ApprovalStatus::Pending) {
                throw new ApprovalValidationException('Only pending Approvals can be decided.');
            }

            $locked->forceFill([
                'status' => ApprovalStatus::Decided->value,
                'decision' => $decision->value,
                'decided_by_actor_kind' => $actorKind->value,
                'decided_by_user_id' => $decidedByUserId,
                'decided_by_customer_contact_id' => $decidedByContactId,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $locked->reason,
                'waiting_on_client' => false,
                'decided_at' => now(),
            ])->save();

            $fresh = $locked->fresh(['task', 'decidedByUser', 'decidedByCustomerContact']) ?? $locked;
            $eventType = match ($decision) {
                ApprovalDecision::Approved => DomainEventType::ApprovalApproved,
                ApprovalDecision::Rejected => DomainEventType::ApprovalRejected,
                ApprovalDecision::ChangesRequested => DomainEventType::ApprovalChangesRequested,
            };

            $eventActorKind = match ($actorKind) {
                ApprovalActorKind::InternalUser => DomainEventActorKind::InternalUser,
                ApprovalActorKind::ClientContact => DomainEventActorKind::ClientContact,
            };

            $this->domainEvents->emit([
                'event_type' => $eventType,
                'actor_kind' => $eventActorKind,
                'actor_user_id' => $decidedByUserId,
                'customer_id' => $fresh->customer_id,
                'brand_id' => $fresh->brand_id,
                'digital_asset_id' => $fresh->task?->digital_asset_id,
                'subject_kind' => DomainEventSubjectKind::Approval,
                'subject_id' => (int) $fresh->id,
                'payload' => [
                    'title' => $fresh->task?->title ?? ('Approval #'.$fresh->id),
                    'decision' => $decision->value,
                    'status' => ApprovalStatus::Decided->value,
                    'task_id' => $fresh->task_id,
                ],
            ]);

            return $fresh;
        });
    }

    public function cancel(Approval $approval, ?User $actor = null): Approval
    {
        $this->assertCanAct($actor);
        $approval = Approval::query()->findOrFail($approval->id);

        if ($approval->status === ApprovalStatus::Cancelled) {
            return $approval;
        }
        if ($approval->status === ApprovalStatus::Decided) {
            throw new ApprovalValidationException('Decided Approvals cannot be cancelled; request a new round.');
        }

        return DB::transaction(function () use ($approval, $actor): Approval {
            $approval->forceFill([
                'status' => ApprovalStatus::Cancelled->value,
                'decision' => null,
                'waiting_on_client' => false,
                'decided_at' => now(),
            ])->save();
            $fresh = $approval->fresh() ?? $approval;
            $this->activity->record($fresh, ApprovalActivityRecorder::CANCELLED, $actor);

            return $fresh;
        });
    }

    public function userCanAct(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }

    private function assertCanAct(?User $actor): void
    {
        if (! $this->userCanAct($actor)) {
            throw ValidationException::withMessages([
                'actor' => 'You are not allowed to manage Approvals.',
            ]);
        }
    }
}
