<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeRecheckPeriodStrategy;
use App\Enums\BusinessOutcomeRecheckResultStatus;
use App\Enums\BusinessOutcomeRecheckRunStatus;
use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Models\Brand;
use App\Models\BusinessOutcomeRecheckRun;
use App\Models\BusinessOutcomeRecheckSchedule;
use App\Models\RecurringOccurrence;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic Business Outcome recheck — no provider calls, no Outcome writes.
 */
final class BusinessOutcomeRecheckService
{
    public function __construct(
        private readonly BusinessOutcomeReadService $outcomes,
        private readonly RecurringOccurrenceCalculator $calculator,
        private readonly DomainEventEmitter $events,
    ) {}

    public function executeForOccurrence(
        BusinessOutcomeRecheckSchedule $schedule,
        RecurringOccurrence $occurrence,
    ): BusinessOutcomeRecheckRun {
        $existing = BusinessOutcomeRecheckRun::query()
            ->where('recurring_occurrence_id', $occurrence->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $brand = Brand::query()->findOrFail($schedule->brand_id);
        $local = CarbonImmutable::parse($occurrence->scheduled_for)
            ->setTimezone((string) $schedule->timezone);

        [$start, $end] = match ($schedule->period_strategy) {
            BusinessOutcomeRecheckPeriodStrategy::PreviousCalendarWeek => $this->calculator->resolvePreviousCalendarWeek($local),
            default => $this->calculator->resolvePreviousCalendarMonth($local),
        };

        $results = [];
        $needsAttention = false;
        foreach ($this->outcomes->listActiveDefinitions($brand) as $definition) {
            $kind = $definition->kind instanceof BusinessOutcomeKind
                ? $definition->kind
                : BusinessOutcomeKind::from((string) $definition->kind);
            $aggregate = $this->outcomes->aggregate(
                $brand,
                $kind,
                $start->toDateString(),
                $end->toDateString(),
            );
            $mapped = $this->mapStatus($aggregate->status);
            $results[] = [
                'definition_id' => (int) $definition->id,
                'kind' => $kind->value,
                'status' => $mapped->value,
                'value' => $aggregate->value,
                'observation_revision_ids' => $aggregate->observationRevisionIds ?? [],
                'limitations' => $aggregate->limitations ?? [],
            ];
            if ($this->shouldNotify($schedule, $mapped)) {
                $needsAttention = true;
            }
        }

        if ($results === []) {
            $results[] = [
                'definition_id' => null,
                'kind' => null,
                'status' => BusinessOutcomeRecheckResultStatus::NoData->value,
                'value' => null,
                'observation_revision_ids' => [],
                'limitations' => ['no_definition'],
            ];
            if ($schedule->attention_on_no_data) {
                $needsAttention = true;
            }
        }

        return DB::transaction(function () use ($schedule, $occurrence, $start, $end, $results, $needsAttention, $brand): BusinessOutcomeRecheckRun {
            $run = BusinessOutcomeRecheckRun::query()->create([
                'schedule_id' => (int) $schedule->id,
                'recurring_occurrence_id' => (int) $occurrence->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => BusinessOutcomeRecheckRunStatus::Completed,
                'results_payload' => $results,
                'notified' => false,
                'created_at' => CarbonImmutable::now(),
                'completed_at' => CarbonImmutable::now(),
            ]);

            if ($needsAttention) {
                $this->notifyResponsible($schedule, $run, $brand);
                $run->notified = true;
                $run->save();
            }

            return $run;
        });
    }

    private function mapStatus(BusinessOutcomeAggregateStatus|string $status): BusinessOutcomeRecheckResultStatus
    {
        $value = $status instanceof BusinessOutcomeAggregateStatus ? $status->value : (string) $status;

        return match ($value) {
            'complete' => BusinessOutcomeRecheckResultStatus::Complete,
            'partial' => BusinessOutcomeRecheckResultStatus::Partial,
            'unknown_completeness' => BusinessOutcomeRecheckResultStatus::UnknownCompleteness,
            'no_data' => BusinessOutcomeRecheckResultStatus::NoData,
            'incompatible_currency', 'overlap_conflict', 'unsupported_grain' => BusinessOutcomeRecheckResultStatus::IntegrityBlocked,
            default => BusinessOutcomeRecheckResultStatus::UnknownCompleteness,
        };
    }

    private function shouldNotify(
        BusinessOutcomeRecheckSchedule $schedule,
        BusinessOutcomeRecheckResultStatus $status,
    ): bool {
        return match ($status) {
            BusinessOutcomeRecheckResultStatus::NoData => (bool) $schedule->attention_on_no_data,
            BusinessOutcomeRecheckResultStatus::Partial => (bool) $schedule->attention_on_partial,
            BusinessOutcomeRecheckResultStatus::UnknownCompleteness,
            BusinessOutcomeRecheckResultStatus::IntegrityBlocked => (bool) $schedule->attention_on_unknown,
            default => false,
        };
    }

    private function notifyResponsible(
        BusinessOutcomeRecheckSchedule $schedule,
        BusinessOutcomeRecheckRun $run,
        Brand $brand,
    ): void {
        $recipientIds = $schedule->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        if ($recipientIds === []) {
            return;
        }

        $statuses = collect($run->results_payload ?? [])->pluck('status')->unique()->implode(', ');
        $title = 'Business Outcome recheck attention: '.$brand->name;
        $body = 'Period '.$run->period_start.' → '.$run->period_end.' requires attention ('.$statuses.'). Values were not invented.';

        $this->events->emit([
            'event_type' => DomainEventType::BusinessOutcomeRecheckAttention,
            'actor_kind' => DomainEventActorKind::System,
            'customer_id' => (int) $brand->customer_id,
            'brand_id' => (int) $brand->id,
            'subject_kind' => DomainEventSubjectKind::BusinessOutcomeRecheckRun,
            'subject_id' => (int) $run->id,
            'payload' => [
                'title' => $title,
                'body' => $body,
                'recipient_user_ids' => $recipientIds,
                'period_start' => $run->period_start?->toDateString() ?? (string) $run->period_start,
                'period_end' => $run->period_end?->toDateString() ?? (string) $run->period_end,
            ],
        ], 'bo-recheck-attention:'.$run->id);
    }
}
