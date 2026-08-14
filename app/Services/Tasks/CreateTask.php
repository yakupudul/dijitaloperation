<?php

namespace App\Services\Tasks;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Exceptions\TaskScopeValidationException;
use App\Exceptions\TaskSourceValidationException;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\Roles;
use App\Support\Tasks\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Canonical Task writer. Source ≠ scope. No fake Brand/Asset. No morphTo.
 */
final class CreateTask
{
    public function __construct(
        private readonly TaskActivityRecorder $activity,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     action?: string|null,
     *     rationale?: string|null,
     *     priority?: string|null,
     *     assignee_id?: int|null,
     *     due_date?: string|null,
     *     customer_id: int,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     scope_kind: string,
     *     source_kind: string,
     *     recommendation_id?: int|null,
     *     client_request_id?: int|null,
     *     snapshot_json?: array<string, mixed>|null,
     *     status?: string|null,
     * }  $input
     */
    public function create(array $input, ?User $actor = null, ?string $idempotencyKey = null): Task
    {
        if ($idempotencyKey !== null) {
            $existing = Task::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof Task) {
                return $existing;
            }
            // Prompt 42 Client Request bridge key
            $existing = Task::query()->where('client_request_task_idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof Task) {
                return $existing;
            }
        }

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'scope_kind' => ['required', 'string', Rule::in(array_column(TaskScopeKind::cases(), 'value'))],
            'source_kind' => ['required', 'string', Rule::in(array_column(TaskSourceKind::cases(), 'value'))],
            'recommendation_id' => ['nullable', 'integer', Rule::exists('recommendations', 'id')],
            'client_request_id' => ['nullable', 'integer', Rule::exists('client_requests', 'id')],
            'status' => ['nullable', 'string', Rule::in(TaskStatus::all())],
        ])->validate();

        $scopeKind = TaskScopeKind::from($data['scope_kind']);
        $sourceKind = TaskSourceKind::from($data['source_kind']);

        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $brand = isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null;
        $asset = isset($data['digital_asset_id']) ? DigitalAsset::query()->find((int) $data['digital_asset_id']) : null;

        $this->assertScopeShape($scopeKind, $customer, $brand, $asset);
        $this->assertSourceShape(
            $sourceKind,
            isset($data['recommendation_id']) ? (int) $data['recommendation_id'] : null,
            isset($data['client_request_id']) ? (int) $data['client_request_id'] : null,
        );
        $this->assertSourceTenantBoundary(
            $sourceKind,
            $customer,
            $brand,
            isset($data['recommendation_id']) ? (int) $data['recommendation_id'] : null,
            isset($data['client_request_id']) ? (int) $data['client_request_id'] : null,
        );

        $attributes = [
            'recommendation_id' => $data['recommendation_id'] ?? null,
            'client_request_id' => $data['client_request_id'] ?? null,
            'client_request_task_idempotency_key' => $sourceKind === TaskSourceKind::ClientRequest ? $idempotencyKey : null,
            'source_kind' => $sourceKind->value,
            'idempotency_key' => $idempotencyKey,
            'customer_id' => $customer->id,
            'brand_id' => $brand?->id,
            'digital_asset_id' => $asset?->id,
            'scope_kind' => $scopeKind->value,
            'title' => trim((string) $data['title']),
            'action' => (string) ($data['action'] ?? $data['title']),
            'rationale' => $data['rationale'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'snapshot_json' => $input['snapshot_json'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? TaskStatus::OPEN,
        ];

        try {
            return DB::transaction(function () use ($attributes, $actor): Task {
                $task = Task::query()->create($attributes);
                $this->activity->record($task, TaskActivityRecorder::CREATED, $actor);

                return $task->fresh([
                    'customer',
                    'brand',
                    'digitalAsset',
                    'recommendation',
                    'clientRequest',
                    'assignee',
                ]) ?? $task;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = Task::query()->where('idempotency_key', $idempotencyKey)->first()
                    ?? Task::query()->where('client_request_task_idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof Task) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    public function userCanCreate(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }

    private function assertScopeShape(
        TaskScopeKind $scopeKind,
        Customer $customer,
        ?Brand $brand,
        ?DigitalAsset $asset,
    ): void {
        match ($scopeKind) {
            TaskScopeKind::Customer => $this->assertCustomerScope($brand, $asset),
            TaskScopeKind::Brand => $this->assertBrandScope($customer, $brand, $asset),
            TaskScopeKind::DigitalAsset => $this->assertDigitalAssetScope($customer, $brand, $asset),
        };
    }

    private function assertCustomerScope(?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand !== null || $asset !== null) {
            throw new TaskScopeValidationException('CUSTOMER scope requires brand_id and digital_asset_id to be null.');
        }
    }

    private function assertBrandScope(Customer $customer, ?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand === null) {
            throw new TaskScopeValidationException('BRAND scope requires brand_id.');
        }
        if ($asset !== null) {
            throw new TaskScopeValidationException('BRAND scope requires digital_asset_id to be null.');
        }
        if ((int) $brand->customer_id !== (int) $customer->id) {
            throw new TaskScopeValidationException('Brand must belong to Task Customer.');
        }
    }

    private function assertDigitalAssetScope(Customer $customer, ?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand === null || $asset === null) {
            throw new TaskScopeValidationException('DIGITAL_ASSET scope requires brand_id and digital_asset_id.');
        }
        if ((int) $brand->customer_id !== (int) $customer->id) {
            throw new TaskScopeValidationException('Brand must belong to Task Customer.');
        }
        if ((int) $asset->brand_id !== (int) $brand->id) {
            throw new TaskScopeValidationException('DigitalAsset must belong to Task Brand.');
        }
    }

    private function assertSourceShape(
        TaskSourceKind $sourceKind,
        ?int $recommendationId,
        ?int $clientRequestId,
    ): void {
        match ($sourceKind) {
            TaskSourceKind::Recommendation => $this->assertRecommendationSource($recommendationId, $clientRequestId),
            TaskSourceKind::ClientRequest => $this->assertClientRequestSource($recommendationId, $clientRequestId),
            TaskSourceKind::Direct => $this->assertDirectSource($recommendationId, $clientRequestId),
        };
    }

    private function assertRecommendationSource(?int $recommendationId, ?int $clientRequestId): void
    {
        if ($recommendationId === null || $clientRequestId !== null) {
            throw new TaskSourceValidationException('RECOMMENDATION source requires recommendation_id and null client_request_id.');
        }
    }

    private function assertClientRequestSource(?int $recommendationId, ?int $clientRequestId): void
    {
        if ($clientRequestId === null || $recommendationId !== null) {
            throw new TaskSourceValidationException('CLIENT_REQUEST source requires client_request_id and null recommendation_id.');
        }
    }

    private function assertDirectSource(?int $recommendationId, ?int $clientRequestId): void
    {
        if ($recommendationId !== null || $clientRequestId !== null) {
            throw new TaskSourceValidationException('DIRECT source requires both recommendation_id and client_request_id to be null.');
        }
    }

    private function assertSourceTenantBoundary(
        TaskSourceKind $sourceKind,
        Customer $customer,
        ?Brand $brand,
        ?int $recommendationId,
        ?int $clientRequestId,
    ): void {
        if ($sourceKind === TaskSourceKind::Recommendation && $recommendationId !== null) {
            $recommendation = Recommendation::query()->with(['digitalAsset.brand', 'finding', 'opportunity'])->findOrFail($recommendationId);
            $sourceBrand = $recommendation->digitalAsset?->brand
                ?? $recommendation->finding?->digitalAsset?->brand
                ?? $recommendation->opportunity?->digitalAsset?->brand;
            $sourceCustomerId = $sourceBrand?->customer_id
                ?? $recommendation->finding?->customer_id
                ?? $recommendation->opportunity?->customer_id;

            if ($sourceCustomerId !== null && (int) $sourceCustomerId !== (int) $customer->id) {
                throw new TaskSourceValidationException('Recommendation source Customer must match Task Customer.');
            }
            if ($sourceBrand !== null && $brand !== null && (int) $sourceBrand->id !== (int) $brand->id) {
                throw new TaskSourceValidationException('Brand-scoped Recommendation cannot create a cross-Brand Task.');
            }
        }

        if ($sourceKind === TaskSourceKind::ClientRequest && $clientRequestId !== null) {
            $request = ClientRequest::query()->findOrFail($clientRequestId);
            if ((int) $request->customer_id !== (int) $customer->id) {
                throw new TaskSourceValidationException('Client Request source Customer must match Task Customer.');
            }
            if ($request->brand_id !== null && $brand !== null && (int) $request->brand_id !== (int) $brand->id) {
                throw new TaskSourceValidationException('Brand-scoped Client Request cannot create a cross-Brand Task.');
            }
        }
    }
}
