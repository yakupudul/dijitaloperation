<?php

namespace App\Services\Tasks;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical Task reads. No provider calls. No Demo fallback.
 */
final class TaskReadService
{
    /**
     * @param  array{
     *     customer_id?: int|null,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     scope_kind?: string|null,
     *     source_kind?: string|null,
     *     status?: string|null,
     *     assignee_id?: int|null,
     *     priority?: string|null,
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);
        $paginator->setCollection(
            collect($paginator->items())->map(fn (Task $task): array => $this->toPresentation($task))
        );

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function forList(array $filters = [], int $limit = 200): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task): array => $this->toPresentation($task))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(int $id): ?array
    {
        $task = $this->baseQuery()->whereKey($id)->first();

        return $task === null ? null : $this->toPresentation($task);
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(?int $customerId = null): array
    {
        $query = Task::query();
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        return $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * @return Builder<Task>
     */
    private function baseQuery(): Builder
    {
        return Task::query()->with([
            'customer:id,name',
            'brand:id,name,customer_id',
            'digitalAsset:id,name,type,brand_id',
            'assignee:id,name',
            'recommendation:id,title,status,source_kind,finding_id,opportunity_id',
            'clientRequest:id,title,status,intake_scope_state,service_definition_id',
        ]);
    }

    /**
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (! empty($filters['scope_kind'])) {
            $query->where('scope_kind', $filters['scope_kind']);
        }
        if (! empty($filters['source_kind'])) {
            $query->where('source_kind', $filters['source_kind']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (array_key_exists('assignee_id', $filters) && $filters['assignee_id'] !== null) {
            $query->where('assignee_id', (int) $filters['assignee_id']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(Task $task): array
    {
        $scope = $task->scope_kind instanceof TaskScopeKind
            ? $task->scope_kind
            : TaskScopeKind::tryFrom((string) $task->scope_kind);
        $source = $task->source_kind instanceof TaskSourceKind
            ? $task->source_kind
            : TaskSourceKind::tryFrom((string) $task->source_kind);

        return [
            'id' => (string) $task->id,
            'type' => 'task',
            'title' => $task->title,
            'action' => $task->action,
            'description' => $task->action,
            'customer_id' => $task->customer_id,
            'customer' => $task->customer?->name ?? '',
            'brand_id' => $task->brand_id,
            'brand' => $task->brand?->name ?? '',
            'digital_asset_id' => $task->digital_asset_id,
            'asset' => $task->digitalAsset?->name,
            'asset_type' => $task->digitalAsset?->type,
            'scope_kind' => $scope?->value,
            'source_kind' => $source?->value,
            'source' => match ($source) {
                TaskSourceKind::Recommendation => 'recommendation',
                TaskSourceKind::ClientRequest => 'client_request',
                TaskSourceKind::Direct => 'direct',
                default => 'task',
            },
            'source_label' => match ($source) {
                TaskSourceKind::Recommendation => 'Recommendation',
                TaskSourceKind::ClientRequest => 'Client Request',
                TaskSourceKind::Direct => 'Direct',
                default => 'Task',
            },
            'recommendation_id' => $task->recommendation_id,
            'client_request_id' => $task->client_request_id,
            'source_title' => $task->recommendation?->title ?? $task->clientRequest?->title,
            'source_status' => $task->recommendation?->status ?? $task->clientRequest?->status?->value,
            'owner' => $task->assignee?->name ?? 'Unassigned',
            'owner_id' => $task->assignee_id,
            'assignee_id' => $task->assignee_id,
            'due' => $task->due_date?->toDateString() ?? '—',
            'due_date' => $task->due_date?->toDateString(),
            'due_key' => $this->dueKey($task),
            'status' => $task->status,
            'priority' => $task->priority,
            'waiting_on_client' => false,
            'qa_required' => false,
            'qa_status' => null,
            'effort' => null,
            'service_label' => null,
            'goal_title' => null,
            'offering' => null,
            'in_scope' => true,
            'route' => 'filament.app.resources.tasks.view',
            'route_params' => ['record' => $task->id],
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'source_state' => 'REAL',
        ];
    }

    private function dueKey(Task $task): string
    {
        if ($task->due_date === null) {
            return 'none';
        }
        $today = now()->startOfDay();
        $due = $task->due_date->copy()->startOfDay();
        if ($due->lt($today)) {
            return 'overdue';
        }
        if ($due->equalTo($today)) {
            return 'today';
        }
        if ($due->lte($today->copy()->addDays(3))) {
            return 'soon';
        }

        return 'later';
    }
}
