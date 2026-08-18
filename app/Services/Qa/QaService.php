<?php

namespace App\Services\Qa;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\QaReviewResult;
use App\Enums\QaReviewStatus;
use App\Exceptions\QaReviewValidationException;
use App\Models\QaReview;
use App\Models\Task;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\Roles;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical QA write boundary. Never mutates Finding/Opportunity/Approval/Task status
 * unless an explicit separate Task transition is invoked by the caller.
 */
final class QaService
{
    public function __construct(
        private readonly QaActivityRecorder $activity,
        private readonly DomainEventEmitter $domainEvents,
    ) {}

    /**
     * @param  array{notes?: string|null}  $input
     */
    public function requestReview(Task $task, array $input = [], ?User $actor = null, ?string $idempotencyKey = null): QaReview
    {
        $this->assertCanAct($actor);
        $task = Task::query()->findOrFail($task->id);

        if ($idempotencyKey !== null) {
            $existing = QaReview::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof QaReview) {
                return $existing;
            }
        }

        $active = QaReview::query()
            ->where('task_id', $task->id)
            ->whereIn('status', [QaReviewStatus::Pending->value, QaReviewStatus::InReview->value])
            ->first();
        if ($active instanceof QaReview) {
            return $active;
        }

        $data = Validator::make($input, [
            'notes' => ['nullable', 'string'],
        ])->validate();

        try {
            return DB::transaction(function () use ($task, $data, $actor, $idempotencyKey): QaReview {
                $review = QaReview::query()->create([
                    'task_id' => $task->id,
                    'customer_id' => $task->customer_id,
                    'brand_id' => $task->brand_id,
                    'status' => QaReviewStatus::Pending->value,
                    'result' => null,
                    'reviewer_id' => null,
                    'requested_by' => $actor?->id,
                    'created_by' => $actor?->id,
                    'notes' => $data['notes'] ?? null,
                    'subject_fingerprint' => TaskReviewedStateFingerprint::for($task),
                    'subject_title_snapshot' => TaskReviewedStateFingerprint::titleSnapshot($task),
                    'requested_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->activity->record($review, QaActivityRecorder::REQUESTED, $actor);

                return $review->fresh(['task', 'reviewer']) ?? $review;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = QaReview::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof QaReview) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    public function startReview(QaReview $review, ?User $actor = null): QaReview
    {
        $this->assertCanAct($actor);
        $review = QaReview::query()->findOrFail($review->id);

        if ($review->status === QaReviewStatus::InReview) {
            return $review;
        }
        if ($review->status !== QaReviewStatus::Pending) {
            throw new QaReviewValidationException('Only pending QA reviews can be started.');
        }

        return DB::transaction(function () use ($review, $actor): QaReview {
            $review->forceFill([
                'status' => QaReviewStatus::InReview->value,
                'reviewer_id' => $actor?->id ?? $review->reviewer_id,
                'started_at' => $review->started_at ?? now(),
            ])->save();
            $this->activity->record($review->fresh() ?? $review, QaActivityRecorder::STARTED, $actor);

            return $review->fresh(['task', 'reviewer']) ?? $review;
        });
    }

    /**
     * @param  array{result: string, notes?: string|null}  $input
     */
    public function completeReview(QaReview $review, array $input, ?User $actor = null, ?string $idempotencyKey = null): QaReview
    {
        $this->assertCanAct($actor);
        $review = QaReview::query()->findOrFail($review->id);

        if ($idempotencyKey !== null && $review->status === QaReviewStatus::Completed) {
            return $review;
        }

        if ($review->status === QaReviewStatus::Completed) {
            return $review;
        }
        if (! in_array($review->status, [QaReviewStatus::Pending, QaReviewStatus::InReview], true)) {
            throw new QaReviewValidationException('Only pending or in-review QA can be completed.');
        }

        $data = Validator::make($input, [
            'result' => ['required', 'string', Rule::in(array_column(QaReviewResult::cases(), 'value'))],
            'notes' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($review, $data, $actor): QaReview {
            /** @var QaReview $locked */
            $locked = QaReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->status === QaReviewStatus::Completed) {
                return $locked->fresh(['task', 'reviewer']) ?? $locked;
            }
            if (! in_array($locked->status, [QaReviewStatus::Pending, QaReviewStatus::InReview], true)) {
                throw new QaReviewValidationException('Only pending or in-review QA can be completed.');
            }

            $locked->forceFill([
                'status' => QaReviewStatus::Completed->value,
                'result' => $data['result'],
                'reviewer_id' => $actor?->id ?? $locked->reviewer_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
                'started_at' => $locked->started_at ?? now(),
                'completed_at' => now(),
            ])->save();
            $fresh = $locked->fresh(['task', 'reviewer']) ?? $locked;

            $eventType = match (QaReviewResult::from((string) $data['result'])) {
                QaReviewResult::Passed => DomainEventType::QaPassed,
                QaReviewResult::Failed => DomainEventType::QaFailed,
                QaReviewResult::NeedsChanges => DomainEventType::QaNeedsChanges,
            };

            $this->domainEvents->emit([
                'event_type' => $eventType,
                'actor_kind' => DomainEventActorKind::InternalUser,
                'actor_user_id' => $actor?->id,
                'customer_id' => $fresh->customer_id,
                'brand_id' => $fresh->brand_id,
                'digital_asset_id' => $fresh->task?->digital_asset_id,
                'subject_kind' => DomainEventSubjectKind::QaReview,
                'subject_id' => (int) $fresh->id,
                'payload' => [
                    'title' => $fresh->task?->title ?? ('QA #'.$fresh->id),
                    'result' => (string) $data['result'],
                    'status' => QaReviewStatus::Completed->value,
                    'task_id' => $fresh->task_id,
                ],
            ]);

            return $fresh;
        });
    }

    public function cancelReview(QaReview $review, ?User $actor = null): QaReview
    {
        $this->assertCanAct($actor);
        $review = QaReview::query()->findOrFail($review->id);

        if ($review->status === QaReviewStatus::Cancelled) {
            return $review;
        }
        if ($review->status === QaReviewStatus::Completed) {
            throw new QaReviewValidationException('Completed QA cannot be cancelled; start a new round.');
        }

        return DB::transaction(function () use ($review, $actor): QaReview {
            $review->forceFill([
                'status' => QaReviewStatus::Cancelled->value,
                'result' => null,
                'completed_at' => now(),
            ])->save();
            $fresh = $review->fresh() ?? $review;
            $this->activity->record($fresh, QaActivityRecorder::CANCELLED, $actor);

            return $fresh;
        });
    }

    public function userCanAct(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }

    private function assertCanAct(?User $actor): void
    {
        if (! $this->userCanAct($actor)) {
            throw ValidationException::withMessages([
                'actor' => 'You are not allowed to manage QA reviews.',
            ]);
        }
    }
}
