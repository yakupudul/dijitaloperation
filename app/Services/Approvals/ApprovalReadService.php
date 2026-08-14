<?php

namespace App\Services\Approvals;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Models\Approval;
use App\Models\Task;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Support\Collection;

final class ApprovalReadService
{
    /**
     * @return array<string, mixed>|null
     */
    public function latestForTask(Task|int $task): ?array
    {
        $taskId = $task instanceof Task ? $task->id : $task;
        $approval = Approval::query()
            ->with(['decidedByUser:id,name', 'decidedByCustomerContact:id,name', 'requestedBy:id,name', 'customer:id,name', 'brand:id,name'])
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first();

        return $approval === null ? null : $this->toPresentation($approval, $task instanceof Task ? $task : null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(int $id): ?array
    {
        $approval = Approval::query()
            ->with(['decidedByUser:id,name', 'decidedByCustomerContact:id,name', 'requestedBy:id,name', 'customer:id,name', 'brand:id,name', 'task'])
            ->whereKey($id)
            ->first();

        if ($approval === null) {
            return null;
        }

        return $this->toWorkItem($approval);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forBrandPresentation(int $brandId, int $limit = 100): array
    {
        return Approval::query()
            ->with(['customer:id,name', 'brand:id,name', 'requestedBy:id,name', 'task:id,title,status'])
            ->where('brand_id', $brandId)
            ->orderByDesc('requested_at')
            ->limit($limit)
            ->get()
            ->map(fn (Approval $approval): array => $this->toWorkItem($approval))
            ->all();
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>|null>
     */
    public function latestByTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $approvals = Approval::query()
            ->with(['decidedByUser:id,name', 'decidedByCustomerContact:id,name'])
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('task_id')
            ->map(fn (Collection $group): Approval => $group->first());

        $tasks = Task::query()->whereIn('id', $taskIds)->get()->keyBy('id');
        $out = [];
        foreach ($taskIds as $taskId) {
            $approval = $approvals->get($taskId);
            $out[$taskId] = $approval === null
                ? null
                : $this->toPresentation($approval, $tasks->get($taskId));
        }

        return $out;
    }

    /**
     * Pending Approvals for Work aggregate (type=approval rows).
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkItemPresentation(int $limit = 200): array
    {
        return Approval::query()
            ->with(['customer:id,name', 'brand:id,name', 'requestedBy:id,name', 'task:id,title,status'])
            ->where('status', ApprovalStatus::Pending->value)
            ->orderByDesc('requested_at')
            ->limit($limit)
            ->get()
            ->map(fn (Approval $approval): array => $this->toWorkItem($approval))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForTask(int $taskId, int $limit = 50): array
    {
        return Approval::query()
            ->with(['decidedByUser:id,name', 'decidedByCustomerContact:id,name'])
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Approval $approval): array => $this->toPresentation($approval))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(Approval $approval, ?Task $task = null): array
    {
        $status = $approval->status instanceof ApprovalStatus ? $approval->status : ApprovalStatus::tryFrom((string) $approval->status);
        $decision = $approval->decision instanceof ApprovalDecision
            ? $approval->decision
            : ($approval->decision !== null ? ApprovalDecision::tryFrom((string) $approval->decision) : null);
        $kind = $approval->kind instanceof ApprovalKind ? $approval->kind : ApprovalKind::tryFrom((string) $approval->kind);

        $current = true;
        if ($task !== null) {
            $current = $approval->subject_fingerprint === TaskReviewedStateFingerprint::for($task);
        }

        $approver = $approval->decidedByUser?->name
            ?? $approval->decidedByCustomerContact?->name;

        return [
            'id' => $approval->id,
            'subject_kind' => $approval->subject_kind instanceof \BackedEnum
                ? $approval->subject_kind->value
                : $approval->subject_kind,
            'task_id' => $approval->task_id,
            'customer_id' => $approval->customer_id,
            'brand_id' => $approval->brand_id,
            'kind' => $kind?->value,
            'status' => $status?->value,
            'decision' => $decision?->value,
            'requested_by' => $approval->requested_by,
            'requester' => $approval->requestedBy?->name,
            'approver' => $approver,
            'decided_by_actor_kind' => $approval->decided_by_actor_kind instanceof \BackedEnum
                ? $approval->decided_by_actor_kind->value
                : $approval->decided_by_actor_kind,
            'waiting_on_client' => (bool) $approval->waiting_on_client,
            'subject_fingerprint' => $approval->subject_fingerprint,
            'subject_title_snapshot' => $approval->subject_title_snapshot,
            'requested_at' => $approval->requested_at?->toIso8601String(),
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'is_current_for_subject' => $current,
            'approval_required_projection' => $status === ApprovalStatus::Pending && $current,
            'source_state' => 'REAL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkItem(Approval $approval): array
    {
        $kind = $approval->kind instanceof ApprovalKind ? $approval->kind : ApprovalKind::tryFrom((string) $approval->kind);
        $title = $approval->subject_title_snapshot !== ''
            ? (($kind === ApprovalKind::Client ? 'Client approval — ' : 'Internal approval — ').$approval->subject_title_snapshot)
            : ('Approval #'.$approval->id);

        return [
            'id' => (string) $approval->id,
            'type' => 'approval',
            'approval_kind' => $kind?->value,
            'title' => $title,
            'customer' => $approval->customer?->name ?? '',
            'customer_id' => $approval->customer_id,
            'brand' => $approval->brand?->name ?? '',
            'brand_id' => $approval->brand_id,
            'asset' => null,
            'asset_type' => null,
            'owner' => $approval->requestedBy?->name ?? 'Unassigned',
            'owner_id' => $approval->requested_by,
            'due' => '—',
            'due_key' => 'none',
            'status' => $approval->status instanceof ApprovalStatus
                ? $approval->status->value
                : (string) $approval->status,
            'waiting_on_client' => (bool) $approval->waiting_on_client,
            'qa_required' => false,
            'qa_status' => null,
            'priority' => 'medium',
            'effort' => null,
            'service_label' => null,
            'goal_title' => null,
            'offering' => null,
            'source' => 'approval',
            'source_label' => 'Approval',
            'in_scope' => true,
            'task_id' => $approval->task_id,
            'route' => 'demo.work.show',
            'route_params' => ['workId' => $approval->id, 'type' => 'approval'],
            'source_state' => 'REAL',
        ];
    }
}
