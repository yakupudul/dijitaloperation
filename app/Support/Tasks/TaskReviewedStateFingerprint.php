<?php

namespace App\Support\Tasks;

use App\Models\Task;

/**
 * Deterministic reviewed-state fingerprint for Task QA/Approval currentness.
 * Includes execution content; excludes assignee/priority/due metadata.
 */
final class TaskReviewedStateFingerprint
{
    public static function for(Task $task): string
    {
        $payload = [
            'id' => $task->id,
            'title' => trim((string) $task->title),
            'action' => trim((string) $task->action),
            'rationale' => trim((string) ($task->rationale ?? '')),
            'digital_asset_id' => $task->digital_asset_id,
            'brand_id' => $task->brand_id,
            'scope_kind' => $task->scope_kind instanceof \BackedEnum
                ? $task->scope_kind->value
                : $task->scope_kind,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function titleSnapshot(Task $task): string
    {
        return mb_substr(trim((string) $task->title), 0, 255);
    }
}
