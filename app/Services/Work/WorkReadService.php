<?php

namespace App\Services\Work;

use App\Services\Approvals\ApprovalReadService;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\RecurringReviews\RecurringReviewReadService;
use App\Services\Tasks\TaskReadService;

/**
 * Work aggregate read model over canonical Tasks (+ residual frozen non-Task types).
 *
 * Work is NOT a persistence domain. Work Item ID for tasks = Task ID.
 * Approvals / QA are production-backed (Prompt 44). Recurring reviews are production-backed (Prompt 46).
 */
final class WorkReadService
{
    public function __construct(
        private readonly TaskReadService $tasks,
        private readonly ClientRequestReadService $clientRequests,
        private readonly ApprovalReadService $approvals,
        private readonly RecurringReviewReadService $recurringReviews,
    ) {}

    /**
     * Frozen Work list rows: production Tasks + Client Requests + Approvals + Recurring Reviews.
     *
     * @return list<array<string, mixed>>
     */
    public function workItems(): array
    {
        $items = [];

        foreach ($this->tasks->forList([], 500) as $task) {
            $items[] = $this->taskToWorkItem($task);
        }

        foreach ($this->clientRequests->forWorkItemPresentation(200) as $request) {
            $items[] = $request;
        }

        foreach ($this->approvals->forWorkItemPresentation(200) as $approval) {
            $items[] = $approval;
        }

        foreach ($this->recurringReviews->forWorkItemPresentation(200) as $review) {
            $items[] = $review;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function taskToWorkItem(array $task): array
    {
        return [
            'id' => $task['id'],
            'type' => 'task',
            'title' => $task['title'],
            'customer' => $task['customer'],
            'customer_id' => $task['customer_id'] ?? null,
            'brand' => $task['brand'],
            'brand_id' => $task['brand_id'] ?? null,
            'asset' => $task['asset'],
            'asset_type' => $task['asset_type'],
            'digital_asset_id' => $task['digital_asset_id'] ?? null,
            'owner' => $task['owner'],
            'owner_id' => $task['owner_id'],
            'due' => $task['due'],
            'due_key' => $task['due_key'],
            'status' => $task['status'],
            'waiting_on_client' => (bool) ($task['waiting_on_client'] ?? false),
            'qa_required' => (bool) ($task['qa_required'] ?? false),
            'qa_status' => $task['qa_status'] ?? null,
            'approval_required' => (bool) ($task['approval_required'] ?? false),
            'current_qa' => $task['current_qa'] ?? null,
            'current_approval' => $task['current_approval'] ?? null,
            'priority' => $task['priority'],
            'effort' => null,
            'service_label' => null,
            'goal_title' => null,
            'offering' => null,
            'source' => $task['source'],
            'source_label' => $task['source_label'],
            'source_kind' => $task['source_kind'],
            'scope_kind' => $task['scope_kind'],
            'in_scope' => true,
            'route' => 'demo.task',
            'route_params' => ['taskId' => $task['id']],
            'recommendation_id' => $task['recommendation_id'],
            'client_request_id' => $task['client_request_id'],
        ];
    }
}
