<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\BusinessOutcomeRecheckScheduleStatus;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\BusinessOutcomeRecheckSchedule;
use App\Models\RecurringOccurrence;
use App\Services\BusinessOutcomes\BusinessOutcomeRecheckService;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

final class BusinessOutcomeRecheckScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly BusinessOutcomeRecheckService $rechecks,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::BusinessOutcomeRecheck;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];
        foreach (BusinessOutcomeRecheckSchedule::query()->where('status', BusinessOutcomeRecheckScheduleStatus::Active)->cursor() as $schedule) {
            foreach ($this->dueFromSpec(
                (int) $schedule->id,
                $this->specFromSchedule($schedule),
                $this->kind(),
                $nowUtc,
                $this->calculator,
                maxCatchUp: 1,
            ) as $item) {
                $due[] = $item;
            }
        }

        return $due;
    }

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
    {
        $schedule = BusinessOutcomeRecheckSchedule::query()->with('recipients')->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
            );
        }

        $run = $this->rechecks->executeForOccurrence($schedule, $occurrence);

        return new RecurringScheduleAdapterResult(
            RecurringOccurrenceStatus::Completed,
            RecurringDomainRunType::BusinessOutcomeRecheckRun,
            (int) $run->id,
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = BusinessOutcomeRecheckSchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === BusinessOutcomeRecheckScheduleStatus::Active;
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

    private function specFromSchedule(BusinessOutcomeRecheckSchedule $schedule): RecurringScheduleSpec
    {
        $time = substr((string) $schedule->delivery_time, 0, 5);
        $frequency = $schedule->frequency instanceof RecurringFrequency
            ? $schedule->frequency
            : RecurringFrequency::from((string) $schedule->frequency);

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: $frequency,
            interval: 1,
            localTime: $time !== '' ? $time : '09:00',
            weekdays: is_array($schedule->weekdays) ? array_map('intval', $schedule->weekdays) : ($frequency === RecurringFrequency::Weekly ? [1] : null),
            dayOfMonth: $schedule->day_of_month !== null ? (int) $schedule->day_of_month : ($frequency === RecurringFrequency::Monthly ? 5 : null),
            monthEndPolicy: 'day_of_month',
            misfirePolicy: $schedule->misfire_policy instanceof RecurringMisfirePolicy
                ? $schedule->misfire_policy
                : RecurringMisfirePolicy::RunLatestMissed,
        );
    }
}
