<?php

namespace App\Services\Tasks;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Models\Task;
use App\Models\User;

/**
 * Frozen Capture supports direct Task creation (Demo Capture type=task).
 * Direct Tasks never invent fake Recommendation or Client Request sources.
 */
final class CreateDirectTask
{
    public function __construct(
        private readonly CreateTask $createTask,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     action?: string|null,
     *     customer_id: int,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     scope_kind?: string|null,
     *     priority?: string|null,
     *     assignee_id?: int|null,
     *     due_date?: string|null,
     * }  $input
     */
    public function create(array $input, ?User $actor = null, ?string $idempotencyKey = null): Task
    {
        $scopeKind = isset($input['scope_kind'])
            ? TaskScopeKind::from((string) $input['scope_kind'])
            : (
                ! empty($input['digital_asset_id'])
                    ? TaskScopeKind::DigitalAsset
                    : (
                        ! empty($input['brand_id'])
                            ? TaskScopeKind::Brand
                            : TaskScopeKind::Customer
                    )
            );

        return $this->createTask->create([
            'title' => $input['title'],
            'action' => $input['action'] ?? $input['title'],
            'priority' => $input['priority'] ?? 'medium',
            'assignee_id' => $input['assignee_id'] ?? $actor?->id,
            'due_date' => $input['due_date'] ?? null,
            'customer_id' => $input['customer_id'],
            'brand_id' => $input['brand_id'] ?? null,
            'digital_asset_id' => $scopeKind === TaskScopeKind::DigitalAsset
                ? ($input['digital_asset_id'] ?? null)
                : null,
            'scope_kind' => $scopeKind->value,
            'source_kind' => TaskSourceKind::Direct->value,
            'recommendation_id' => null,
            'client_request_id' => null,
            'snapshot_json' => [
                'source_kind' => TaskSourceKind::Direct->value,
                'scope_kind' => $scopeKind->value,
                'origin' => 'operator',
            ],
        ], $actor, $idempotencyKey);
    }
}
