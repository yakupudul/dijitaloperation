<?php

namespace App\Services\Tasks;

use App\Models\BrandContextActivity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

final class TaskActivityRecorder
{
    public const string CREATED = 'TASK_CREATED';

    public const string UPDATED = 'TASK_UPDATED';

    public const string STATUS_CHANGED = 'TASK_STATUS_CHANGED';

    public const string ASSIGNED = 'TASK_ASSIGNED';

    public const string SCOPE_CHANGED = 'TASK_SCOPE_CHANGED';

    public const string COMPLETED = 'TASK_COMPLETED';

    public const string CANCELLED = 'TASK_CANCELLED';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(Task $task, string $event, ?User $actor = null, array $extra = []): ?BrandContextActivity
    {
        $allowed = [
            self::CREATED,
            self::UPDATED,
            self::STATUS_CHANGED,
            self::ASSIGNED,
            self::SCOPE_CHANGED,
            self::COMPLETED,
            self::CANCELLED,
        ];

        if (! in_array($event, $allowed, true)) {
            return null;
        }

        $brandId = $task->brand_id;
        if ($brandId === null) {
            // Customer-scoped Tasks still need a Brand for brand_context_activities.
            // Record against first related brand only when we have brand_id; otherwise skip.
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $brandId,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'payload' => array_merge([
                'task_id' => $task->id,
                'customer_id' => $task->customer_id,
                'brand_id' => $task->brand_id,
                'digital_asset_id' => $task->digital_asset_id,
                'scope_kind' => $task->scope_kind instanceof \BackedEnum
                    ? $task->scope_kind->value
                    : $task->scope_kind,
                'source_kind' => $task->source_kind instanceof \BackedEnum
                    ? $task->source_kind->value
                    : $task->source_kind,
                'recommendation_id' => $task->recommendation_id,
                'client_request_id' => $task->client_request_id,
                'status' => $task->status,
                'priority' => $task->priority,
                'assignee_id' => $task->assignee_id,
            ], $extra),
            'created_at' => Carbon::now(),
        ]);
    }
}
