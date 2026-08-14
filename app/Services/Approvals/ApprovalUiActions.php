<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalActorKind;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Exceptions\ApprovalValidationException;
use App\Models\Approval;
use App\Models\CustomerContact;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Thin Livewire/UI adapter around Approval application services.
 */
final class ApprovalUiActions
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ApprovalReadService $reads,
    ) {}

    public function resolve(string|int $id): ?Approval
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Approval::query()->find((int) $id);
    }

    public function resolveTask(string|int $id): ?Task
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Task::query()->find((int) $id);
    }

    /**
     * @return array{ok: bool, message: string, approval_id?: int|null}
     */
    public function requestForTask(string|int $taskId, ?User $actor = null, ?string $idempotencyKey = null, string $kind = 'client'): array
    {
        $task = $this->resolveTask($taskId);
        if ($task === null) {
            return ['ok' => false, 'message' => 'Task not found.'];
        }

        try {
            $approval = $this->approvals->request($task, [
                'kind' => $kind,
            ], $actor, $idempotencyKey);

            return [
                'ok' => true,
                'message' => __('operator.approvals.requested'),
                'approval_id' => $approval->id,
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'message' => collect($exception->errors())->flatten()->first() ?? 'Approval request failed.',
            ];
        }
    }

    /**
     * Frozen Work "Approve" action — records an approved decision for a pending round.
     *
     * @return array{ok: bool, message: string, presentation?: array<string, mixed>|null}
     */
    public function approve(string|int $id, ?User $actor = null): array
    {
        return $this->decide($id, ApprovalDecision::Approved->value, $actor);
    }

    /**
     * @return array{ok: bool, message: string, presentation?: array<string, mixed>|null}
     */
    public function decide(string|int $id, string $decision, ?User $actor = null, ?string $reason = null): array
    {
        $approval = $this->resolve($id);
        if ($approval === null) {
            return ['ok' => false, 'message' => 'Approval not found.'];
        }

        $input = [
            'decision' => $decision,
            'reason' => $reason,
        ];

        $kind = $approval->kind instanceof ApprovalKind
            ? $approval->kind
            : ApprovalKind::tryFrom((string) $approval->kind);

        if ($kind === ApprovalKind::Client) {
            $contactId = CustomerContact::query()
                ->where('customer_id', $approval->customer_id)
                ->orderBy('id')
                ->value('id');
            if ($contactId !== null) {
                $input['decided_by_actor_kind'] = ApprovalActorKind::ClientContact->value;
                $input['decided_by_customer_contact_id'] = (int) $contactId;
            } else {
                // Operator records client approval without a persisted contact identity.
                $input['decided_by_actor_kind'] = ApprovalActorKind::InternalUser->value;
                $input['decided_by_user_id'] = $actor?->id;
            }
        } else {
            $input['decided_by_actor_kind'] = ApprovalActorKind::InternalUser->value;
            $input['decided_by_user_id'] = $actor?->id;
        }

        try {
            $updated = $this->approvals->decide($approval, $input, $actor);

            return [
                'ok' => true,
                'message' => __('operator.approvals.approve'),
                'presentation' => $this->reads->toPresentation($updated),
            ];
        } catch (ApprovalValidationException|ValidationException $exception) {
            return [
                'ok' => false,
                'message' => $exception instanceof ValidationException
                    ? (collect($exception->errors())->flatten()->first() ?? 'Approval decision failed.')
                    : $exception->getMessage(),
            ];
        }
    }
}
