<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\InternalNotificationScheduleStatus;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\InternalNotificationSchedule;
use App\Models\RecurringOccurrence;
use App\Services\Notifications\ScheduledInternalNotificationService;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

final class InternalNotificationScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly ScheduledInternalNotificationService $notifications,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::InternalNotification;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];
        foreach (InternalNotificationSchedule::query()->where('status', InternalNotificationScheduleStatus::Active)->cursor() as $schedule) {
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
        $schedule = InternalNotificationSchedule::query()->with('recipients')->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
            );
        }

        $ids = $this->notifications->deliver($schedule, $occurrence);

        return new RecurringScheduleAdapterResult(
            RecurringOccurrenceStatus::Completed,
            RecurringDomainRunType::NotificationBatch,
            $ids[0] ?? null,
            failureMessage: $ids === [] ? 'No valid recipients' : null,
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = InternalNotificationSchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === InternalNotificationScheduleStatus::Active;
    }

    public function allowedFrequencies(): array
    {
        return [RecurringFrequency::Daily, RecurringFrequency::Weekly, RecurringFrequency::Monthly];
    }

    public function defaultMisfirePolicy(): RecurringMisfirePolicy
    {
        return RecurringMisfirePolicy::SkipMissed;
    }

    public function supportsManualRun(): bool
    {
        return true;
    }

    private function specFromSchedule(InternalNotificationSchedule $schedule): RecurringScheduleSpec
    {
        $frequency = $schedule->frequency instanceof RecurringFrequency
            ? $schedule->frequency
            : RecurringFrequency::from((string) $schedule->frequency);
        $time = $schedule->local_time !== null ? substr((string) $schedule->local_time, 0, 5) : '09:00';

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: $frequency,
            interval: max(1, (int) $schedule->interval),
            localTime: $time,
            weekdays: is_array($schedule->weekdays) ? array_map('intval', $schedule->weekdays) : ($frequency === RecurringFrequency::Weekly ? [1] : null),
            dayOfMonth: $schedule->day_of_month !== null ? (int) $schedule->day_of_month : ($frequency === RecurringFrequency::Monthly ? 1 : null),
            monthEndPolicy: 'day_of_month',
            misfirePolicy: $schedule->misfire_policy instanceof RecurringMisfirePolicy
                ? $schedule->misfire_policy
                : RecurringMisfirePolicy::SkipMissed,
        );
    }
}
