<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestStatus;
use App\Exceptions\ClientRequestTargetScopeRequiredException;
use App\Models\ClientRequest;
use App\Models\DigitalAsset;
use App\Models\Task;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Explicit Request → Task handoff. Reuses canonical Task. No TaskV2.
 * No unrestricted polymorphic Task source. No Service Scope mutation.
 * No Evidence / Finding / Opportunity / Recommendation creation.
 */
final class CreateTaskFromClientRequest
{
    public function __construct(
        private readonly ClientRequestScopeResolver $scopeResolver,
        private readonly ClientRequestActivityRecorder $activity,
        private readonly UpdateClientRequest $updater,
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     action?: string|null,
     *     digital_asset_id?: int|null,
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
        if ($idempotencyKey !== null) {
            $existing = Task::query()
                ->where('client_request_task_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof Task) {
                return $existing;
            }
        }

        $request = ClientRequest::query()->with(['brand', 'customer', 'digitalAsset'])->findOrFail($request->id);

        $data = Validator::make($attributes, [
            'title' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'mark_request_planned' => ['nullable', 'boolean'],
        ])->validate();

        $digitalAssetId = isset($data['digital_asset_id'])
            ? (int) $data['digital_asset_id']
            : ($request->digital_asset_id !== null ? (int) $request->digital_asset_id : null);

        if ($digitalAssetId === null) {
            throw new ClientRequestTargetScopeRequiredException($request);
        }

        $asset = DigitalAsset::query()->with('brand')->findOrFail($digitalAssetId);

        if ((int) $asset->brand_id !== (int) $request->brand_id) {
            throw ValidationException::withMessages([
                'digital_asset_id' => 'Task DigitalAsset must belong to the Request Brand.',
            ]);
        }

        if ((int) ($asset->brand?->customer_id) !== (int) $request->customer_id) {
            throw ValidationException::withMessages([
                'digital_asset_id' => 'Task DigitalAsset Customer must match Request Customer.',
            ]);
        }

        $title = filled($data['title'] ?? null)
            ? (string) $data['title']
            : $request->title;

        $action = filled($data['action'] ?? null)
            ? (string) $data['action']
            : (string) ($request->description ?? $request->title);

        $priority = filled($data['priority'] ?? null)
            ? (string) $data['priority']
            : (string) ($request->priority ?? 'medium');

        // Frozen Demo copies Request owner → Task assignee as a default.
        $assigneeId = array_key_exists('assignee_id', $data)
            ? $data['assignee_id']
            : $request->owner_user_id;

        $scope = $this->scopeResolver->resolve($request);

        $snapshot = [
            'customer_id' => $request->customer_id,
            'brand_id' => $request->brand_id,
            'digital_asset_id' => $asset->id,
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

        try {
            return DB::transaction(function () use (
                $request,
                $asset,
                $title,
                $action,
                $priority,
                $assigneeId,
                $data,
                $snapshot,
                $idempotencyKey,
                $scope,
                $actor,
                $markPlanned,
            ): Task {
                $task = Task::query()->create([
                    'recommendation_id' => null,
                    'client_request_id' => $request->id,
                    'client_request_task_idempotency_key' => $idempotencyKey,
                    'customer_id' => $request->customer_id,
                    'brand_id' => $request->brand_id,
                    'digital_asset_id' => $asset->id,
                    'title' => $title,
                    'action' => $action,
                    'rationale' => null,
                    'priority' => $priority,
                    'snapshot_json' => $snapshot,
                    'assignee_id' => $assigneeId,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => 'open',
                ]);

                // Frozen Demo sets Request status to planned when a Task is created.
                // Does not mark Request done/completed.
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
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = Task::query()
                    ->where('client_request_task_idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof Task) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    public function userCanConvert(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN)
            || $user->hasRole(Roles::TEAM_MEMBER);
    }
}
