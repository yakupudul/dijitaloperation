<?php

namespace App\Services\Work;

use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\Tasks\TaskReadService;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\DemoState;

/**
 * Work aggregate read model over canonical Tasks (+ residual frozen non-Task types).
 *
 * Work is NOT a persistence domain. Work Item ID for tasks = Task ID.
 * Client Request / recurring review / approval rows remain separate domain Demo/prod
 * surfaces until their prompts; they are not duplicated into a works table.
 */
final class WorkReadService
{
    public function __construct(
        private readonly TaskReadService $tasks,
        private readonly ClientRequestReadService $clientRequests,
    ) {}

    /**
     * Frozen Work list rows: production Tasks + production Client Requests + Demo reviews/approvals.
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

        $state = DemoState::all();
        foreach (DemoState::recurringReviewsWithState() as $review) {
            $items[] = AgencyExecutionFixtures::mapRecurringReviewToWorkItemPublic($review);
        }
        foreach (AgencyExecutionFixtures::approvalsWithState() as $approval) {
            if (($approval['status'] ?? '') === 'waiting') {
                $items[] = AgencyExecutionFixtures::mapApprovalToWorkItemPublic($approval);
            }
        }

        // Intentionally no Demo tasks — Task rows are production-only.
        unset($state);

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
            'waiting_on_client' => false,
            'qa_required' => false,
            'qa_status' => null,
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
