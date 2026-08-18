<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestStatus;
use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Exceptions\ClientRequestTargetScopeRequiredException;
use App\Models\ClientRequest;
use App\Models\DigitalAsset;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CreateTask;
use App\Support\Roles;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Explicit Client Request → Task handoff. Converges into canonical CreateTask (Prompt 43).
 */
final class CreateTaskFromClientRequest
{
    public function __construct(
        private readonly ClientRequestScopeResolver $scopeResolver,
        private readonly ClientRequestActivityRecorder $activity,
        private readonly UpdateClientRequest $updater,
        private readonly CreateTask $createTask,
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     action?: string|null,
     *     digital_asset_id?: int|null,
     *     brand_id?: int|null,
     *     scope_kind?: string|null,
     *     assignee_id?: int|null,
     *     due_date?: string|null,
     *     priority?: string|null,
     *     mark_request_planned?: bool|null,
     * }  $attributes
     *
     * @throws ClientRequestTargetScopeRequiredException
     * @throws ValidationException
     */
    public function create(
        ClientRequest $request,
        array $attributes = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): Task {
        $request = ClientRequest::query()->with(['brand', 'customer', 'digitalAsset'])->findOrFail($request->id);

        $data = Validator::make($attributes, [
            'title' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'scope_kind' => ['nullable', 'string', Rule::in(array_column(TaskScopeKind::cases(), 'value'))],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'mark_request_planned' => ['nullable', 'boolean'],
        ])->validate();

        $brandId = array_key_exists('brand_id', $data) && $data['brand_id'] !== null
            ? (int) $data['brand_id']
            : ($request->brand_id !== null ? (int) $request->brand_id : null);
        $digitalAssetId = isset($data['digital_asset_id'])
            ? (int) $data['digital_asset_id']
            : ($request->digital_asset_id !== null ? (int) $request->digital_asset_id : null);

        $requestedScope = isset($data['scope_kind'])
            ? TaskScopeKind::from((string) $data['scope_kind'])
            : (
                $digitalAssetId !== null
                    ? TaskScopeKind::DigitalAsset
                    : (
                        $brandId !== null
                            ? TaskScopeKind::Brand
                            : TaskScopeKind::Customer
                    )
            );

        // Prompt 43: Brand/Customer Tasks need no DigitalAsset. DIGITAL_ASSET still requires explicit asset
        // (never first-asset fallback). Explicit DIGITAL_ASSET without asset remains TARGET_SCOPE_REQUIRED.
        if ($requestedScope === TaskScopeKind::DigitalAsset && $digitalAssetId === null) {
            throw new ClientRequestTargetScopeRequiredException($request);
        }

        if ($requestedScope === TaskScopeKind::Customer) {
            $brandId = null;
            $digitalAssetId = null;
        }

        if ($requestedScope === TaskScopeKind::Brand) {
            if ($brandId === null) {
                throw ValidationException::withMessages([
                    'brand_id' => 'BRAND Task scope requires an explicit Brand under the Request Customer.',
                ]);
            }
            $digitalAssetId = null;
        }

        if ($requestedScope === TaskScopeKind::DigitalAsset) {
            $asset = DigitalAsset::query()->with('brand')->findOrFail($digitalAssetId);
            if ($brandId === null) {
                $brandId = (int) $asset->brand_id;
            }
            if ((int) $asset->brand_id !== $brandId) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'Task DigitalAsset must belong to the Request Brand.',
                ]);
            }
            if ((int) ($asset->brand?->customer_id) !== (int) $request->customer_id) {
                throw ValidationException::withMessages([
                    'digital_asset_id' => 'Task DigitalAsset Customer must match Request Customer.',
                ]);
            }
        }

        $title = filled($data['title'] ?? null) ? (string) $data['title'] : $request->title;
        $action = filled($data['action'] ?? null)
            ? (string) $data['action']
            : (string) ($request->description ?? $request->title);
        $priority = filled($data['priority'] ?? null)
            ? (string) $data['priority']
            : (string) ($request->priority ?? 'medium');
        // Assignee is explicit only — Request owner is not silently copied (Prompt 43).
        $assigneeId = $data['assignee_id'] ?? null;

        $scope = $this->scopeResolver->resolve($request);

        $snapshot = [
            'customer_id' => $request->customer_id,
            'brand_id' => $brandId,
            'digital_asset_id' => $digitalAssetId,
            'scope_kind' => $requestedScope->value,
            'source_kind' => TaskSourceKind::ClientRequest->value,
            'client_request_id' => $request->id,
            'title' => $title,
            'action' => $action,
            'priority' => $priority,
            'client_request' => [
                'id' => $request->id,
                'status' => $request->status->value,
                'channel' => $request->channel?->value,
                'service_definition_id' => $request->service_definition_id,
                'intake_scope_state' => $request->intake_scope_state?->value,
                'scope_state_at_task_creation' => $scope->state->value,
                'owner_user_id' => $request->owner_user_id,
                'created_by_user_id' => $request->created_by_user_id,
            ],
        ];

        $markPlanned = $data['mark_request_planned'] ?? true;

        $task = $this->createTask->create([
            'title' => $title,
            'action' => $action,
            'priority' => $priority,
            'assignee_id' => $assigneeId,
            'due_date' => $data['due_date'] ?? null,
            'customer_id' => $request->customer_id,
            'brand_id' => $brandId,
            'digital_asset_id' => $digitalAssetId,
            'scope_kind' => $requestedScope->value,
            'source_kind' => TaskSourceKind::ClientRequest->value,
            'recommendation_id' => null,
            'client_request_id' => $request->id,
            'snapshot_json' => $snapshot,
        ], $actor, $idempotencyKey);

        if ($markPlanned
            && $request->status !== ClientRequestStatus::Planned
            && $request->status->canTransitionTo(ClientRequestStatus::Planned)
        ) {
            $this->updater->changeStatus($request, ClientRequestStatus::Planned, $actor);
        }

        $this->activity->recordTaskCreated(
            $request->fresh() ?? $request,
            $task,
            $actor,
            ['scope_state_at_task_creation' => $scope->state->value],
        );

        return $task;
    }

    public function userCanConvert(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }
}
