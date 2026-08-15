<?php

namespace App\Services\RecurringReviews;

use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewOutcomeKind;
use App\Enums\RecurringReviewRunItemState;
use App\Enums\RecurringReviewRunStatus;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thin Livewire/UI adapter. Business logic stays in application services.
 */
final class RecurringReviewUiActions
{
    public function __construct(
        private readonly RecurringReviewReadService $reads,
        private readonly RecurringReviewRunService $runs,
        private readonly CompleteRecurringReviewCheck $completeCheck,
        private readonly MaterializeRecurringReviewOccurrence $materialize,
    ) {}

    public function resolveRun(string|int $id): ?RecurringReviewRun
    {
        if (! is_numeric($id)) {
            return null;
        }

        return RecurringReviewRun::query()->with(['items', 'schedule.checkDefinitions'])->find((int) $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(string|int $id): ?array
    {
        if (! is_numeric($id)) {
            return null;
        }

        return $this->reads->runDetail((int) $id);
    }

    /**
     * Frozen Work actions apply a primary disposition then finish remaining checks as No Issue.
     *
     * @return array{ok: bool, message: string, run_id?: int|null}
     */
    public function completeReview(string|int $runId, string $result, ?User $actor = null, ?string $idempotencyKey = null): array
    {
        $allowed = ['no_issue', 'finding', 'opportunity', 'task'];
        if (! in_array($result, $allowed, true)) {
            return ['ok' => false, 'message' => 'Invalid review result.'];
        }

        $run = $this->resolveRun($runId);
        if ($run === null) {
            return ['ok' => false, 'message' => 'Recurring review run not found.'];
        }

        $key = $idempotencyKey ?? ('rr-ui-complete:'.$run->id.':'.$result);

        try {
            return DB::transaction(function () use ($run, $result, $actor, $key): array {
                $run = $run->fresh(['items']) ?? $run;
                $status = $run->status instanceof RecurringReviewRunStatus
                    ? $run->status
                    : RecurringReviewRunStatus::tryFrom((string) $run->status);

                if ($status === RecurringReviewRunStatus::Completed) {
                    return [
                        'ok' => true,
                        'message' => __('operator.reviews.completed'),
                        'run_id' => $run->id,
                    ];
                }

                if ($status === RecurringReviewRunStatus::Scheduled) {
                    $run = $this->runs->startRun($run, $actor);
                }

                $items = $run->items()->orderBy('position')->get();
                $primaryApplied = false;

                foreach ($items as $index => $item) {
                    $state = $item->state instanceof RecurringReviewRunItemState
                        ? $item->state
                        : RecurringReviewRunItemState::tryFrom((string) $item->state);

                    if ($state !== RecurringReviewRunItemState::Pending) {
                        continue;
                    }

                    $outcome = RecurringReviewOutcomeKind::NoIssue->value;
                    $options = [];
                    if (! $primaryApplied) {
                        $outcome = $result;
                        $primaryApplied = true;
                        if ($outcome === RecurringReviewOutcomeKind::Task->value) {
                            $options['title'] = 'Follow-up from '.$item->title_snapshot;
                        }
                    }

                    $this->completeCheck->complete(
                        $item,
                        $outcome,
                        $options,
                        $actor,
                        $key.':item:'.$item->id,
                    );
                }

                $this->runs->completeRun($run->fresh(['items']) ?? $run, $actor);

                return [
                    'ok' => true,
                    'message' => __('operator.reviews.completed'),
                    'run_id' => $run->id,
                ];
            });
        } catch (RecurringReviewValidationException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string, run_id?: int|null}
     */
    public function skipReview(string|int $runId, ?User $actor = null, string $reason = 'Skipped by operator'): array
    {
        $run = $this->resolveRun($runId);
        if ($run === null) {
            return ['ok' => false, 'message' => 'Recurring review run not found.'];
        }

        try {
            $this->runs->skipRun($run, $actor, $reason);

            return [
                'ok' => true,
                'message' => __('operator.reviews.skipped'),
                'run_id' => $run->id,
            ];
        } catch (RecurringReviewValidationException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * Explicit Run now — manual occurrence, never collides with scheduled keys.
     *
     * @return array{ok: bool, message: string, run_id?: int|null}
     */
    public function runNow(int $scheduleId, ?User $actor = null): array
    {
        $schedule = RecurringReviewSchedule::query()->find($scheduleId);
        if ($schedule === null) {
            return ['ok' => false, 'message' => 'Schedule not found.'];
        }

        try {
            $key = 'manual:'.Str::uuid()->toString();
            $run = ($this->materialize)(
                $schedule,
                $key,
                now(),
                RecurringReviewOccurrenceKind::Manual,
                $actor,
            );

            return [
                'ok' => true,
                'message' => 'Review run materialized.',
                'run_id' => $run->id,
            ];
        } catch (RecurringReviewValidationException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }
}
