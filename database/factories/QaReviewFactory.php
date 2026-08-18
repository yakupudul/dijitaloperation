<?php

namespace Database\Factories;

use App\Enums\QaReviewStatus;
use App\Models\QaReview;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaReview>
 */
class QaReviewFactory extends Factory
{
    protected $model = QaReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'customer_id' => function (array $attributes): int {
                return (int) Task::query()->find($attributes['task_id'])?->customer_id;
            },
            'brand_id' => function (array $attributes): ?int {
                return Task::query()->find($attributes['task_id'])?->brand_id;
            },
            'status' => QaReviewStatus::Pending->value,
            'result' => null,
            'reviewer_id' => null,
            'requested_by' => User::factory(),
            'created_by' => function (array $attributes) {
                return $attributes['requested_by'] ?? null;
            },
            'notes' => null,
            'subject_fingerprint' => function (array $attributes): string {
                $task = Task::query()->findOrFail($attributes['task_id']);

                return TaskReviewedStateFingerprint::for($task);
            },
            'subject_title_snapshot' => function (array $attributes): string {
                $task = Task::query()->findOrFail($attributes['task_id']);

                return TaskReviewedStateFingerprint::titleSnapshot($task);
            },
            'requested_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'idempotency_key' => null,
        ];
    }
}
