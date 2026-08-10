<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Human-only Task lifecycle transitions (ADR-029 / Outcome Loop V1).
 *
 * Completing a Task does NOT resolve Findings, mutate Evidence, or change Recommendation truth.
 */
final class TaskLifecycleService
{
    public function start(Task $task, ?User $actor = null): Task
    {
        $this->authorizeActor($actor);

        if ($task->status !== TaskStatus::OPEN) {
            throw ValidationException::withMessages([
                'status' => 'Only open Tasks can be started.',
            ]);
        }

        $task->forceFill(['status' => TaskStatus::IN_PROGRESS])->save();

        return $task->refresh();
    }

    public function block(Task $task, ?User $actor = null): Task
    {
        $this->authorizeActor($actor);

        if ($task->status !== TaskStatus::IN_PROGRESS) {
            throw ValidationException::withMessages([
                'status' => 'Only in-progress Tasks can be blocked.',
            ]);
        }

        $task->forceFill(['status' => TaskStatus::BLOCKED])->save();

        return $task->refresh();
    }

    public function resume(Task $task, ?User $actor = null): Task
    {
        $this->authorizeActor($actor);

        if ($task->status !== TaskStatus::BLOCKED) {
            throw ValidationException::withMessages([
                'status' => 'Only blocked Tasks can be resumed.',
            ]);
        }

        $task->forceFill(['status' => TaskStatus::IN_PROGRESS])->save();

        return $task->refresh();
    }

    /**
     * @param  array{completion_note?: ?string, outcome_review_after_at?: ?\DateTimeInterface|string}  $input
     */
    public function complete(Task $task, array $input = [], ?User $actor = null): Task
    {
        $actor = $this->authorizeActor($actor);

        if (! in_array($task->status, TaskStatus::active(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Only open, in-progress, or blocked Tasks can be completed.',
            ]);
        }

        $note = isset($input['completion_note']) ? trim((string) $input['completion_note']) : null;
        if ($note === '') {
            $note = null;
        }

        $reviewAfter = $input['outcome_review_after_at'] ?? null;

        return DB::transaction(function () use ($task, $actor, $note, $reviewAfter): Task {
            $task->forceFill([
                'status' => TaskStatus::COMPLETED,
                'completed_at' => now(),
                'completed_by_id' => $actor->id,
                'completion_note' => $note,
                'outcome_review_after_at' => $reviewAfter,
                'outcome_status' => TaskOutcomeStatus::AWAITING_FOLLOW_UP,
                'outcome_checked_at' => now(),
                'outcome_run_id' => null,
                'outcome_json' => [
                    'version' => TaskOutcomeStatus::EVALUATOR_VERSION,
                    'signal' => TaskOutcomeStatus::AWAITING_FOLLOW_UP,
                    'reason_code' => 'awaiting_follow_up_evaluation',
                    'causal_attribution' => false,
                    'source' => $this->sourceProvenance($task),
                    'baseline' => $this->baselineFromSnapshot($task),
                    'follow_up' => null,
                    'explanation' => TaskOutcomeStatus::explanation(TaskOutcomeStatus::AWAITING_FOLLOW_UP),
                ],
            ])->save();

            return $task->refresh();
        });
    }

    public function cancel(Task $task, ?User $actor = null): Task
    {
        $this->authorizeActor($actor);

        if (! in_array($task->status, TaskStatus::active(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Only open, in-progress, or blocked Tasks can be cancelled.',
            ]);
        }

        $task->forceFill([
            'status' => TaskStatus::CANCELLED,
            'outcome_status' => null,
            'outcome_checked_at' => null,
            'outcome_run_id' => null,
            'outcome_json' => null,
        ])->save();

        return $task->refresh();
    }

    private function authorizeActor(?User $actor): User
    {
        $actor ??= Auth::user();

        if (! $actor instanceof User) {
            throw ValidationException::withMessages([
                'actor' => 'An authenticated operator is required for Task lifecycle actions.',
            ]);
        }

        if (! $actor->hasAnyRole(['Admin', 'Team Member'])) {
            throw ValidationException::withMessages([
                'actor' => 'Only Admin or Team Member operators may change Task status.',
            ]);
        }

        return $actor;
    }

    /**
     * @return array{recommendation_id: ?int, finding_id: ?int, finding_fingerprint: ?string, digital_asset_id: ?int, source_module: ?string}
     */
    private function sourceProvenance(Task $task): array
    {
        $snapshot = is_array($task->snapshot_json) ? $task->snapshot_json : [];
        $finding = is_array($snapshot['finding'] ?? null) ? $snapshot['finding'] : [];

        return [
            'recommendation_id' => $task->recommendation_id ?? ($snapshot['recommendation_id'] ?? null),
            'finding_id' => isset($finding['id']) ? (int) $finding['id'] : null,
            'finding_fingerprint' => isset($finding['fingerprint']) ? (string) $finding['fingerprint'] : null,
            'digital_asset_id' => $task->digital_asset_id,
            'source_module' => isset($finding['source_module']) ? (string) $finding['source_module'] : null,
        ];
    }

    /**
     * @return array{run_id: ?int, finding_status: ?string, observed_at: ?string, severity: ?string}|null
     */
    private function baselineFromSnapshot(Task $task): ?array
    {
        $snapshot = is_array($task->snapshot_json) ? $task->snapshot_json : [];
        $finding = is_array($snapshot['finding'] ?? null) ? $snapshot['finding'] : [];

        if ($finding === []) {
            return null;
        }

        return [
            'run_id' => isset($finding['last_run_id']) ? (int) $finding['last_run_id'] : null,
            'finding_status' => isset($finding['status']) ? (string) $finding['status'] : null,
            'observed_at' => isset($finding['last_seen_at']) ? (string) $finding['last_seen_at'] : null,
            'severity' => isset($finding['severity']) ? (string) $finding['severity'] : null,
        ];
    }
}
