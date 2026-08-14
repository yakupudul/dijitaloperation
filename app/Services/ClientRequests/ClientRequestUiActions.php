<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestStatus;
use App\Exceptions\ClientRequestInvalidTransitionException;
use App\Exceptions\ClientRequestTargetScopeRequiredException;
use App\Models\ClientRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Thin Livewire/UI adapter around Client Request application services.
 */
final class ClientRequestUiActions
{
    public function __construct(
        private readonly UpdateClientRequest $updater,
        private readonly CreateTaskFromClientRequest $createTask,
        private readonly ClientRequestReadService $reads,
    ) {}

    public function resolve(string|int $id): ?ClientRequest
    {
        if (! is_numeric($id)) {
            return null;
        }

        return ClientRequest::query()->find((int) $id);
    }

    /**
     * @return array{ok: bool, message: string, presentation?: array<string, mixed>|null}
     */
    public function changeStatus(string|int $id, ClientRequestStatus $status, ?User $actor = null): array
    {
        $request = $this->resolve($id);
        if ($request === null) {
            return ['ok' => false, 'message' => 'Client Request not found.'];
        }

        try {
            $updated = $this->updater->changeStatus($request, $status, $actor);

            return [
                'ok' => true,
                'message' => __('operator.requests.status_updated'),
                'presentation' => $this->reads->findPresentation($updated->id),
            ];
        } catch (ClientRequestInvalidTransitionException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string, task_id?: int|null}
     */
    public function createTask(string|int $id, ?User $actor = null, ?string $idempotencyKey = null, ?int $digitalAssetId = null): array
    {
        $request = $this->resolve($id);
        if ($request === null) {
            return ['ok' => false, 'message' => 'Client Request not found.'];
        }

        if (! $this->createTask->userCanConvert($actor)) {
            return ['ok' => false, 'message' => 'Not authorized to create a Task from this Client Request.'];
        }

        $key = $idempotencyKey ?? ('cr-task:'.$request->id.':'.Str::uuid()->toString());

        try {
            $attributes = [];
            if ($digitalAssetId !== null) {
                $attributes['digital_asset_id'] = $digitalAssetId;
            }

            $task = $this->createTask->create($request, $attributes, $actor, $key);

            return [
                'ok' => true,
                'message' => __('operator.requests.task_created'),
                'task_id' => $task->id,
            ];
        } catch (ClientRequestTargetScopeRequiredException) {
            return [
                'ok' => false,
                'message' => 'TARGET_SCOPE_REQUIRED: select an explicit DigitalAsset before creating a Task.',
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'message' => collect($exception->errors())->flatten()->first() ?? 'Task creation failed.',
            ];
        }
    }
}
