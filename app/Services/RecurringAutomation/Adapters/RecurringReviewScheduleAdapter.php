<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewScheduleStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\RecurringOccurrence;
use App\Models\RecurringReviewSchedule;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Services\RecurringReviews\MaterializeRecurringReviewOccurrence;
use App\Services\RecurringReviews\RecurringReviewDueCalculator;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

/**
 * Executes Prompt 46 RecurringReviewSchedule via shared runtime. No ReviewScheduleV2.
 */
final class RecurringReviewScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly MaterializeRecurringReviewOccurrence $materialize,
        private readonly RecurringReviewDueCalculator $dueCalculator,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::RecurringReview;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];

        $schedules = RecurringReviewSchedule::query()
            ->where('status', RecurringReviewScheduleStatus::Active)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', $nowUtc)
            ->limit(200)
            ->get();

        foreach ($schedules as $schedule) {
            $spec = $this->specFromSchedule($schedule);
            foreach ($this->dueFromSpec(
                (int) $schedule->id,
                $spec,
                $this->kind(),
                $nowUtc,
                $this->calculator,
                maxCatchUp: 1,
                lookbackDays: 60,
            ) as $item) {
                $due[] = $item;
            }
        }

        return $due;
    }

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
    {
        $schedule = RecurringReviewSchedule::query()->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
                failureMessage: 'RecurringReviewSchedule missing',
            );
        }

        $dueLocal = CarbonImmutable::parse($occurrence->scheduled_for)
            ->setTimezone((string) ($occurrence->timezone_snapshot ?: $schedule->timezone));
        $key = $this->dueCalculator->occurrenceKey($dueLocal);

        try {
            $run = $this->materialize->materialize(
                $schedule,
                $key,
                $dueLocal,
                RecurringReviewOccurrenceKind::Scheduled,
            );
        } catch (\Throwable $e) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'REVIEW_MATERIALIZE_FAILED',
                failureMessage: $e->getMessage(),
            );
        }

        return new RecurringScheduleAdapterResult(
            RecurringOccurrenceStatus::Completed,
            RecurringDomainRunType::RecurringReviewRun,
            (int) $run->id,
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = RecurringReviewSchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === RecurringReviewScheduleStatus::Active;
    }

    public function allowedFrequencies(): array
    {
        return [RecurringFrequency::Weekly, RecurringFrequency::Monthly];
    }

    public function defaultMisfirePolicy(): RecurringMisfirePolicy
    {
        return RecurringMisfirePolicy::RunLatestMissed;
    }

    public function supportsManualRun(): bool
    {
        return true;
    }

    private function specFromSchedule(RecurringReviewSchedule $schedule): RecurringScheduleSpec
    {
        $cadence = (string) ($schedule->cadence?->value ?? $schedule->cadence);
        $frequency = match ($cadence) {
            'weekly' => RecurringFrequency::Weekly,
            'monthly', 'quarterly' => RecurringFrequency::Monthly,
            default => RecurringFrequency::Monthly,
        };

        $due = $schedule->next_due_at !== null
            ? CarbonImmutable::parse($schedule->next_due_at)->setTimezone((string) $schedule->timezone)
            : CarbonImmutable::now((string) $schedule->timezone);

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: $frequency,
            interval: $cadence === 'quarterly' ? 3 : 1,
            localTime: $due->format('H:i'),
            weekdays: $frequency === RecurringFrequency::Weekly ? [(int) $due->dayOfWeekIso] : null,
            dayOfMonth: $frequency === RecurringFrequency::Monthly ? (int) $due->day : null,
            monthEndPolicy: 'day_of_month',
            startsAt: $schedule->starts_at !== null ? CarbonImmutable::parse($schedule->starts_at) : null,
            endsAt: $schedule->ends_at !== null ? CarbonImmutable::parse($schedule->ends_at) : null,
            misfirePolicy: RecurringMisfirePolicy::RunLatestMissed,
        );
    }
}
