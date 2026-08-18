<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestScopeState;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Support\ClientRequests\ClientRequestScopeResolution;
use App\Support\Work\WorkUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Production Client Request reads. Never falls back to Demo fixtures.
 */
final class ClientRequestReadService
{
    public function __construct(
        private readonly ClientRequestScopeResolver $scopeResolver,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function forCustomer(Customer|int $customer, int $perPage = 50): LengthAwarePaginator
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        $paginator = $this->baseQuery()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $resolutions = $this->scopeResolver->resolveMany(collect($paginator->items()));

        $paginator->setCollection(
            collect($paginator->items())->map(
                fn (ClientRequest $request): array => $this->toPresentation(
                    $request,
                    $resolutions[$request->id] ?? $this->scopeResolver->resolve($request),
                )
            )
        );

        return $paginator;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomerPresentation(Customer|int $customer, int $limit = 100): array
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        $rows = $this->baseQuery()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->presentMany($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forBrandPresentation(int $brandId, int $limit = 100): array
    {
        $rows = $this->baseQuery()
            ->where('brand_id', $brandId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->presentMany($rows);
    }

    /**
     * Work / Operations list rows (frozen AgencyExecutionFixtures work-item shape).
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkItemPresentation(int $limit = 200): array
    {
        $rows = $this->baseQuery()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_map(
            fn (array $row): array => $this->toWorkItem($row),
            $this->presentMany($rows),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(int $id): ?array
    {
        $request = $this->baseQuery()->whereKey($id)->first();
        if ($request === null) {
            return null;
        }

        return $this->toPresentation($request, $this->scopeResolver->resolve($request));
    }

    /**
     * @param  Collection<int, ClientRequest>  $rows
     * @return list<array<string, mixed>>
     */
    private function presentMany(Collection $rows): array
    {
        $resolutions = $this->scopeResolver->resolveMany($rows);

        return $rows
            ->map(fn (ClientRequest $request): array => $this->toPresentation(
                $request,
                $resolutions[$request->id] ?? $this->scopeResolver->resolve($request),
            ))
            ->values()
            ->all();
    }

    /**
     * @return Builder<ClientRequest>
     */
    private function baseQuery(): Builder
    {
        return ClientRequest::query()->with([
            'customer:id,name',
            'brand:id,name,customer_id',
            'digitalAsset:id,name,type,brand_id',
            'serviceDefinition:id,code,name',
            'requester:id,name,email,phone,title,customer_id',
            'owner:id,name',
            'createdBy:id,name',
            'tasks:id,client_request_id,title,status,digital_asset_id,created_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(ClientRequest $request, ClientRequestScopeResolution $currentScope): array
    {
        $intake = $request->intake_scope_state instanceof ClientRequestScopeState
            ? $request->intake_scope_state
            : ClientRequestScopeState::Unclassified;

        $latestTask = $request->relationLoaded('tasks')
            ? $request->tasks->sortByDesc('id')->first()
            : null;

        return [
            'id' => (string) $request->id,
            'customer_id' => $request->customer_id,
            'customer' => $request->customer?->name ?? '',
            'brand_id' => $request->brand_id,
            'brand' => $request->brand?->name ?? '',
            'digital_asset_id' => $request->digital_asset_id,
            'asset' => $request->digitalAsset?->name,
            'asset_type' => $request->digitalAsset?->type,
            'service_definition_id' => $request->service_definition_id,
            'service_code' => $request->serviceDefinition?->code,
            'service_label' => $request->serviceDefinition?->name,
            'source' => $request->channel?->value,
            'source_label' => $request->channel?->label(),
            'channel' => $request->channel?->value,
            'status' => $request->status->value,
            'waiting_on_client' => $request->isWaitingOnClient(),
            'in_scope' => $currentScope->state->presentationInScope(),
            'scope_state' => $currentScope->state->value,
            'intake_scope_state' => $intake->value,
            'intake_scope_snapshot' => $request->intake_scope_snapshot,
            'current_scope' => $currentScope->toArray(),
            'owner_id' => $request->owner_user_id,
            'owner' => $request->owner?->name ?? 'Unassigned',
            'requester_id' => $request->customer_contact_id,
            'requester' => $request->requester?->name,
            'created_by_id' => $request->created_by_user_id,
            'created_by' => $request->createdBy?->name,
            'due' => $request->due_label
                ?? ($request->due_date?->toDateString() ?? '—'),
            'due_key' => $this->dueKey($request),
            'due_date' => $request->due_date?->toDateString(),
            'priority' => $request->priority ?? 'medium',
            'effort' => $request->effort,
            'title' => $request->title,
            'description' => $request->description,
            'linked_task_id' => $latestTask?->id !== null ? (string) $latestTask->id : null,
            'task_count' => $request->relationLoaded('tasks') ? $request->tasks->count() : 0,
            'tasks' => $request->relationLoaded('tasks')
                ? $request->tasks->map(fn ($task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'digital_asset_id' => $task->digital_asset_id,
                ])->values()->all()
                : [],
            'goal_title' => null,
            'offering' => null,
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'source_state' => 'REAL',
        ];
    }

    /**
     * @param  array<string, mixed>  $presentation
     * @return array<string, mixed>
     */
    private function toWorkItem(array $presentation): array
    {
        return [
            'id' => $presentation['id'],
            'type' => 'client_request',
            'title' => $presentation['title'],
            'customer' => $presentation['customer'],
            'brand' => $presentation['brand'],
            'asset' => $presentation['asset'],
            'asset_type' => $presentation['asset_type'],
            'owner' => $presentation['owner'],
            'owner_id' => $presentation['owner_id'],
            'due' => $presentation['due'],
            'due_key' => $presentation['due_key'],
            'status' => $presentation['status'],
            'waiting_on_client' => $presentation['waiting_on_client'],
            'qa_required' => false,
            'qa_status' => null,
            'priority' => $presentation['priority'],
            'effort' => $presentation['effort'],
            'service_label' => $presentation['service_label'],
            'goal_title' => null,
            'offering' => null,
            'source' => $presentation['source'] ?? 'client',
            'source_label' => $presentation['source_label'] ?? 'Client',
            'in_scope' => $presentation['in_scope'],
            'linked_task_id' => $presentation['linked_task_id'],
            'route' => 'operator.work.show',
            'route_params' => WorkUrl::parameters(WorkUrl::TYPE_CLIENT_REQUEST, $presentation['id']),
            'detail_url' => WorkUrl::show(WorkUrl::TYPE_CLIENT_REQUEST, $presentation['id']),
        ];
    }

    private function dueKey(ClientRequest $request): string
    {
        if ($request->due_date === null) {
            return 'none';
        }

        $today = now()->startOfDay();
        $due = $request->due_date->copy()->startOfDay();

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
