<?php

namespace App\Services\Qa;

use App\Enums\QaReviewResult;
use App\Enums\QaReviewStatus;
use App\Exceptions\QaReviewValidationException;
use App\Models\QaReview;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Thin Livewire/UI adapter around QA application services.
 */
final class QaUiActions
{
    public function __construct(
        private readonly QaService $qa,
        private readonly QaReadService $reads,
    ) {}

    public function resolveReview(string|int $id): ?QaReview
    {
        if (! is_numeric($id)) {
            return null;
        }

        return QaReview::query()->find((int) $id);
    }

    public function resolveTask(string|int $id): ?Task
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Task::query()->find((int) $id);
    }

    /**
     * @return array{ok: bool, message: string, review_id?: int|null}
     */
    public function requestForTask(string|int $taskId, ?User $actor = null, ?string $idempotencyKey = null): array
    {
        $task = $this->resolveTask($taskId);
        if ($task === null) {
            return ['ok' => false, 'message' => 'Task not found.'];
        }

        try {
            $review = $this->qa->requestReview($task, [], $actor, $idempotencyKey);

            return [
                'ok' => true,
                'message' => __('operator.qa.requested'),
                'review_id' => $review->id,
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'message' => collect($exception->errors())->flatten()->first() ?? 'QA request failed.',
            ];
        }
    }

    /**
     * Frozen "Approve QA" action: ensure an active review exists, then complete as passed.
     *
     * @return array{ok: bool, message: string, review_id?: int|null}
     */
    public function approveQaForTask(string|int $taskId, ?User $actor = null, ?string $idempotencyKey = null): array
    {
        $task = $this->resolveTask($taskId);
        if ($task === null) {
            return ['ok' => false, 'message' => 'Task not found.'];
        }

        try {
            $review = $this->qa->requestReview(
                $task,
                [],
                $actor,
                $idempotencyKey !== null ? $idempotencyKey.':request' : null,
            );

            if ($review->status === QaReviewStatus::Completed
                && $review->result === QaReviewResult::Passed) {
                return [
                    'ok' => true,
                    'message' => __('operator.qa.approve'),
                    'review_id' => $review->id,
                ];
            }

            if ($review->status === QaReviewStatus::Pending) {
                $review = $this->qa->startReview($review, $actor);
            }

            $review = $this->qa->completeReview(
                $review,
                ['result' => QaReviewResult::Passed->value],
                $actor,
                $idempotencyKey !== null ? $idempotencyKey.':complete' : null,
            );

            return [
                'ok' => true,
                'message' => __('operator.qa.approve'),
                'review_id' => $review->id,
            ];
        } catch (QaReviewValidationException|ValidationException $exception) {
            return [
                'ok' => false,
                'message' => $exception instanceof ValidationException
                    ? (collect($exception->errors())->flatten()->first() ?? 'QA completion failed.')
                    : $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{result: string, notes?: string|null}  $input
     * @return array{ok: bool, message: string, presentation?: array<string, mixed>|null}
     */
    public function complete(string|int $reviewId, array $input, ?User $actor = null): array
    {
        $review = $this->resolveReview($reviewId);
        if ($review === null) {
            return ['ok' => false, 'message' => 'QA review not found.'];
        }

        try {
            $updated = $this->qa->completeReview($review, $input, $actor);

            return [
                'ok' => true,
                'message' => __('operator.qa.approve'),
                'presentation' => $this->reads->toPresentation($updated),
            ];
        } catch (QaReviewValidationException|ValidationException $exception) {
            return [
                'ok' => false,
                'message' => $exception instanceof ValidationException
                    ? (collect($exception->errors())->flatten()->first() ?? 'QA completion failed.')
                    : $exception->getMessage(),
            ];
        }
    }
}
