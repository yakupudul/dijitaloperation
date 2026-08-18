<?php

namespace App\Services\Qa;

use App\Enums\QaReviewResult;
use App\Enums\QaReviewStatus;
use App\Models\QaReview;
use App\Models\Task;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Support\Collection;

final class QaReadService
{
    /**
     * @return array<string, mixed>|null
     */
    public function latestForTask(Task|int $task): ?array
    {
        $taskId = $task instanceof Task ? $task->id : $task;
        $review = QaReview::query()
            ->with(['reviewer:id,name'])
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->first();

        return $review === null ? null : $this->toPresentation($review, $task instanceof Task ? $task : null);
    }

    /**
     * @param  list<int>  $taskIds
     * @return array<int, array<string, mixed>|null>
     */
    public function latestByTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $reviews = QaReview::query()
            ->with(['reviewer:id,name'])
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('task_id')
            ->map(fn (Collection $group): QaReview => $group->first());

        $tasks = Task::query()->whereIn('id', $taskIds)->get()->keyBy('id');
        $out = [];
        foreach ($taskIds as $taskId) {
            $review = $reviews->get($taskId);
            $out[$taskId] = $review === null
                ? null
                : $this->toPresentation($review, $tasks->get($taskId));
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForTask(int $taskId, int $limit = 50): array
    {
        return QaReview::query()
            ->with(['reviewer:id,name'])
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (QaReview $review): array => $this->toPresentation($review))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(QaReview $review, ?Task $task = null): array
    {
        $status = $review->status instanceof QaReviewStatus ? $review->status : QaReviewStatus::tryFrom((string) $review->status);
        $result = $review->result instanceof QaReviewResult
            ? $review->result
            : ($review->result !== null ? QaReviewResult::tryFrom((string) $review->result) : null);

        $current = true;
        if ($task !== null) {
            $current = $review->subject_fingerprint === TaskReviewedStateFingerprint::for($task);
        }

        $needsAttention = in_array($status, [QaReviewStatus::Pending, QaReviewStatus::InReview], true)
            || ($status === QaReviewStatus::Completed && in_array($result, [QaReviewResult::Failed, QaReviewResult::NeedsChanges], true) && $current);

        return [
            'id' => $review->id,
            'task_id' => $review->task_id,
            'customer_id' => $review->customer_id,
            'brand_id' => $review->brand_id,
            'status' => $status?->value,
            'result' => $result?->value,
            'reviewer_id' => $review->reviewer_id,
            'reviewer' => $review->reviewer?->name,
            'requested_by' => $review->requested_by,
            'notes' => null,
            'subject_fingerprint' => $review->subject_fingerprint,
            'subject_title_snapshot' => $review->subject_title_snapshot,
            'requested_at' => $review->requested_at?->toIso8601String(),
            'started_at' => $review->started_at?->toIso8601String(),
            'completed_at' => $review->completed_at?->toIso8601String(),
            'is_current_for_subject' => $current,
            'qa_required_projection' => $needsAttention,
            'source_state' => 'REAL',
        ];
    }
}
