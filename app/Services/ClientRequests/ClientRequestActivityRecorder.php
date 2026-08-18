<?php

namespace App\Services\ClientRequests;

use App\Models\BrandContextActivity;
use App\Models\ClientRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Meaningful Client Request Activity only. Does not duplicate full Request body.
 */
final class ClientRequestActivityRecorder
{
    public const string CREATED = 'CLIENT_REQUEST_CREATED';

    public const string UPDATED = 'CLIENT_REQUEST_UPDATED';

    public const string STATUS_CHANGED = 'CLIENT_REQUEST_STATUS_CHANGED';

    public const string SCOPE_CLASSIFIED = 'CLIENT_REQUEST_SCOPE_CLASSIFIED';

    public const string OWNER_CHANGED = 'CLIENT_REQUEST_OWNER_CHANGED';

    public const string TASK_CREATED = 'CLIENT_REQUEST_TASK_CREATED';

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    public function record(
        ClientRequest $request,
        string $event,
        ?User $actor = null,
        array $extraPayload = [],
    ): ?BrandContextActivity {
        $allowed = [
            self::CREATED,
            self::UPDATED,
            self::STATUS_CHANGED,
            self::SCOPE_CLASSIFIED,
            self::OWNER_CHANGED,
            self::TASK_CREATED,
        ];

        if (! in_array($event, $allowed, true)) {
            return null;
        }

        if ($request->brand_id === null) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $request->brand_id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => ClientRequest::class,
            'subject_id' => $request->id,
            'payload' => array_merge([
                'client_request_id' => $request->id,
                'customer_id' => $request->customer_id,
                'brand_id' => $request->brand_id,
                'status' => $request->status?->value ?? $request->status,
                'priority' => $request->priority,
                'channel' => $request->channel?->value ?? $request->channel,
                'service_definition_id' => $request->service_definition_id,
                'intake_scope_state' => $request->intake_scope_state?->value ?? $request->intake_scope_state,
                'owner_user_id' => $request->owner_user_id,
                'created_by_user_id' => $request->created_by_user_id,
                'customer_contact_id' => $request->customer_contact_id,
                'digital_asset_id' => $request->digital_asset_id,
            ], $extraPayload),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    public function recordTaskCreated(
        ClientRequest $request,
        Task $task,
        ?User $actor = null,
        array $extraPayload = [],
    ): ?BrandContextActivity {
        return $this->record($request, self::TASK_CREATED, $actor, array_merge([
            'task_id' => $task->id,
            'task_status' => $task->status,
            'task_digital_asset_id' => $task->digital_asset_id,
        ], $extraPayload));
    }
}
