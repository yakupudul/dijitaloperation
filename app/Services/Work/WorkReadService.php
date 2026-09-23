<?php

namespace App\Services\Work;

use App\Services\Tasks\TaskReadService;
use App\Support\Work\WorkUrl;

/**
 * Work aggregate read model over canonical Tasks.
 *
 * Work is NOT a persistence domain. Work Item ID for tasks = Task ID.
 */
final class WorkReadService
{
    public function __construct(
        private readonly TaskReadService $tasks,
    ) {}

    /**
     * Work list rows: production Tasks.
     *
     * @return list<array<string, mixed>>
     */
    public function workItems(): array
    {
        $items = [];

        foreach ($this->tasks->forList([], 500) as $task) {
            $items[] = $this->taskToWorkItem($task);
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
            'route' => 'operator.work.show',
            'route_params' => WorkUrl::parameters(WorkUrl::TYPE_TASK, $task['id']),
            'detail_url' => WorkUrl::show(WorkUrl::TYPE_TASK, $task['id']),
            'recommendation_id' => $task['recommendation_id'],
            'client_request_id' => $task['client_request_id'],
        ];
    }
}
