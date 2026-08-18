<?php

namespace Database\Factories;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalSubjectKind;
use App\Models\Approval;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_kind' => ApprovalSubjectKind::Task->value,
            'task_id' => Task::factory(),
            'customer_id' => function (array $attributes): int {
                return (int) Task::query()->find($attributes['task_id'])?->customer_id;
            },
            'brand_id' => function (array $attributes): ?int {
                return Task::query()->find($attributes['task_id'])?->brand_id;
            },
            'kind' => ApprovalKind::Client->value,
            'status' => ApprovalStatus::Pending->value,
            'decision' => null,
            'requested_by' => User::factory(),
            'decided_by_actor_kind' => null,
            'decided_by_user_id' => null,
            'decided_by_customer_contact_id' => null,
            'created_by' => function (array $attributes) {
                return $attributes['requested_by'] ?? null;
            },
            'notes' => null,
            'reason' => null,
            'waiting_on_client' => true,
            'subject_fingerprint' => function (array $attributes): string {
                $task = Task::query()->findOrFail($attributes['task_id']);

                return TaskReviewedStateFingerprint::for($task);
            },
            'subject_title_snapshot' => function (array $attributes): string {
                $task = Task::query()->findOrFail($attributes['task_id']);

                return TaskReviewedStateFingerprint::titleSnapshot($task);
            },
            'requested_at' => now(),
            'decided_at' => null,
            'idempotency_key' => null,
        ];
    }
}
