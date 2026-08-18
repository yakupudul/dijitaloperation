<?php

namespace App\Services\RecurringReviews;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewRunItemState;
use App\Enums\RecurringReviewRunStatus;
use App\Enums\RecurringReviewScheduleStatus;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewRunItem;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Run lifecycle: start / complete / skip. Never mutates Finding/Opportunity/Task status.
 */
final class RecurringReviewRunService
{
    public function __construct(
        private readonly RecurringReviewDueCalculator $dueCalculator,
        private readonly RecurringReviewActivityRecorder $activity,
        private readonly DomainEventEmitter $domainEvents,
    ) {}

    public function startRun(RecurringReviewRun $run, ?User $actor = null): RecurringReviewRun
    {
        $status = $run->status instanceof RecurringReviewRunStatus
            ? $run->status
            : RecurringReviewRunStatus::tryFrom((string) $run->status);

        if ($status === RecurringReviewRunStatus::InProgress) {
            return $run->fresh(['items']) ?? $run;
        }

        if ($status !== RecurringReviewRunStatus::Scheduled) {
            throw new RecurringReviewValidationException('RUN_NOT_STARTABLE', 'Only scheduled runs can be started.');
        }

        $run->forceFill([
            'status' => RecurringReviewRunStatus::InProgress->value,
            'started_at' => now(),
            'reviewer_user_id' => $actor?->id ?? $run->reviewer_user_id,
        ])->save();

        $this->activity->recordRun($run, RecurringReviewActivityRecorder::REVIEW_STARTED, $actor);

        return $run->fresh(['items']) ?? $run;
    }

    public function completeRun(RecurringReviewRun $run, ?User $actor = null): RecurringReviewRun
    {
        $status = $run->status instanceof RecurringReviewRunStatus
            ? $run->status
            : RecurringReviewRunStatus::tryFrom((string) $run->status);

        if ($status === RecurringReviewRunStatus::Completed) {
            return $run->fresh(['items']) ?? $run;
        }

        if (! in_array($status, [RecurringReviewRunStatus::Scheduled, RecurringReviewRunStatus::InProgress], true)) {
            throw new RecurringReviewValidationException('RUN_NOT_COMPLETABLE', 'Run cannot be completed from current status.');
        }

        $run->loadMissing('items');

        foreach ($run->items as $item) {
            if ($this->itemBlocksCompletion($item)) {
                throw new RecurringReviewValidationException(
                    'REQUIRED_ITEMS_INCOMPLETE',
                    'All required items must be completed, skipped, or marked not applicable.',
                );
            }
        }

        return DB::transaction(function () use ($run, $actor): RecurringReviewRun {
            $summary = $this->buildSummary($run);

            $run->forceFill([
                'status' => RecurringReviewRunStatus::Completed->value,
                'completed_at' => now(),
                'started_at' => $run->started_at ?? now(),
                'reviewer_user_id' => $run->reviewer_user_id ?? $actor?->id,
                'summary_json' => $summary,
            ])->save();

            $this->domainEvents->emit([
                'event_type' => DomainEventType::RecurringReviewCompleted,
                'actor_kind' => DomainEventActorKind::InternalUser,
                'actor_user_id' => $actor?->id,
                'customer_id' => $run->customer_id,
                'brand_id' => $run->brand_id,
                'digital_asset_id' => $run->digital_asset_id,
                'subject_kind' => DomainEventSubjectKind::RecurringReviewRun,
                'subject_id' => (int) $run->id,
                'payload' => [
                    'title' => 'Recurring review #'.$run->id,
                    'title_snapshot' => 'Recurring review #'.$run->id,
                    'finding_count' => (int) ($summary['outcomes_finding'] ?? 0),
                    'opportunity_count' => (int) ($summary['outcomes_opportunity'] ?? 0),
                    'task_count' => (int) ($summary['outcomes_task'] ?? 0),
                    'check_count' => (int) ($summary['items_total'] ?? 0),
                    'status' => RecurringReviewRunStatus::Completed->value,
                ],
            ]);

            $this->advanceScheduleAfterCompletedRun($run);

            return $run->fresh(['items']) ?? $run;
        });
    }

    public function skipRun(RecurringReviewRun $run, ?User $actor = null, ?string $reason = null): RecurringReviewRun
    {
        $status = $run->status instanceof RecurringReviewRunStatus
            ? $run->status
            : RecurringReviewRunStatus::tryFrom((string) $run->status);

        if ($status === RecurringReviewRunStatus::Skipped) {
            return $run->fresh(['items']) ?? $run;
        }

        if (in_array($status, [RecurringReviewRunStatus::Completed, RecurringReviewRunStatus::Cancelled], true)) {
            throw new RecurringReviewValidationException('RUN_NOT_SKIPPABLE', 'Completed or cancelled runs cannot be skipped.');
        }

        return DB::transaction(function () use ($run, $actor, $reason): RecurringReviewRun {
            $run->forceFill([
                'status' => RecurringReviewRunStatus::Skipped->value,
                'completed_at' => now(),
                'summary_json' => array_merge($run->summary_json ?? [], [
                    'skipped' => true,
                    'reason' => $reason,
                ]),
            ])->save();

            $this->activity->recordRun($run, RecurringReviewActivityRecorder::REVIEW_SKIPPED, $actor, [
                'reason' => $reason,
            ]);

            $this->advanceScheduleAfterCompletedRun($run);

            return $run->fresh(['items']) ?? $run;
        });
    }

    private function itemBlocksCompletion(RecurringReviewRunItem $item): bool
    {
        $state = $item->state instanceof RecurringReviewRunItemState
            ? $item->state
            : RecurringReviewRunItemState::tryFrom((string) $item->state);

        $terminal = in_array($state, [
            RecurringReviewRunItemState::Completed,
            RecurringReviewRunItemState::Skipped,
            RecurringReviewRunItemState::NotApplicable,
        ], true);

        if ($terminal) {
            return false;
        }

        return (bool) $item->is_required_snapshot;
    }

    /**
     * @return array<string, int>
     */
    private function buildSummary(RecurringReviewRun $run): array
    {
        $counts = [
            'items_total' => 0,
            'items_completed' => 0,
            'items_skipped' => 0,
            'items_not_applicable' => 0,
            'items_pending' => 0,
            'outcomes_no_issue' => 0,
            'outcomes_finding' => 0,
            'outcomes_opportunity' => 0,
            'outcomes_task' => 0,
            'open_findings' => 0,
            'open_opportunities' => 0,
            'open_tasks' => 0,
        ];

        foreach ($run->items as $item) {
            $counts['items_total']++;
            $state = $item->state instanceof RecurringReviewRunItemState
                ? $item->state->value
                : (string) $item->state;

            match ($state) {
                RecurringReviewRunItemState::Completed->value => $counts['items_completed']++,
                RecurringReviewRunItemState::Skipped->value => $counts['items_skipped']++,
                RecurringReviewRunItemState::NotApplicable->value => $counts['items_not_applicable']++,
                default => $counts['items_pending']++,
            };

            $outcome = $item->outcome_kind instanceof \BackedEnum
                ? $item->outcome_kind->value
                : ($item->outcome_kind !== null ? (string) $item->outcome_kind : null);

            match ($outcome) {
                'no_issue' => $counts['outcomes_no_issue']++,
                'finding' => $counts['outcomes_finding']++,
                'opportunity' => $counts['outcomes_opportunity']++,
                'task' => $counts['outcomes_task']++,
                default => null,
            };

            if ($item->finding_id !== null) {
                $counts['open_findings']++;
            }
            if ($item->opportunity_id !== null) {
                $counts['open_opportunities']++;
            }
            if ($item->task_id !== null) {
                $counts['open_tasks']++;
            }
        }

        return $counts;
    }

    private function advanceScheduleAfterCompletedRun(RecurringReviewRun $run): void
    {
        $occurrenceKind = $run->occurrence_kind instanceof RecurringReviewOccurrenceKind
            ? $run->occurrence_kind
            : RecurringReviewOccurrenceKind::tryFrom((string) $run->occurrence_kind);

        if ($occurrenceKind !== RecurringReviewOccurrenceKind::Scheduled) {
            return;
        }

        $schedule = RecurringReviewSchedule::query()->find($run->schedule_id);
        if (! $schedule instanceof RecurringReviewSchedule) {
            return;
        }

        $status = $schedule->status instanceof RecurringReviewScheduleStatus
            ? $schedule->status
            : RecurringReviewScheduleStatus::tryFrom((string) $schedule->status);

        if ($status !== RecurringReviewScheduleStatus::Active) {
            return;
        }

        $after = CarbonImmutable::parse($run->due_at);
        $schedule->forceFill([
            'next_due_at' => $this->dueCalculator->nextDueAfter($schedule, $after),
        ])->save();
    }
}
