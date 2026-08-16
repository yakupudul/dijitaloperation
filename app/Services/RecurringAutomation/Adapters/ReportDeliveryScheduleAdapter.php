<?php

namespace App\Services\RecurringAutomation\Adapters;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\RecurringDomainRunType;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Enums\ReportDeliveryOccurrenceStatus;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Models\RecurringOccurrence;
use App\Models\ReportDeliveryOccurrence;
use App\Models\ReportDeliverySchedule;
use App\Services\RecurringAutomation\Concerns\DiscoversDueOccurrences;
use App\Services\ReportDelivery\ExecuteReportDeliveryOccurrenceService;
use App\Services\ReportDelivery\ReportDeliveryScheduleService;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleAdapterResult;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

/**
 * Converges Prompt 60 ReportDeliverySchedule onto the shared runtime.
 * Domain truth remains ReportDeliverySchedule / ReportDeliveryOccurrence.
 */
final class ReportDeliveryScheduleAdapter implements RecurringScheduleAdapter
{
    use DiscoversDueOccurrences;

    public function __construct(
        private readonly ReportDeliveryScheduleService $schedules,
        private readonly ExecuteReportDeliveryOccurrenceService $executor,
        private readonly RecurringOccurrenceCalculator $calculator,
    ) {}

    public function kind(): RecurringScheduleKind
    {
        return RecurringScheduleKind::ReportDelivery;
    }

    public function discoverDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc = $nowUtc ?? CarbonImmutable::now('UTC');
        $due = [];

        $active = ReportDeliverySchedule::query()
            ->where('status', ReportDeliveryScheduleStatus::Active)
            ->get();

        foreach ($active as $schedule) {
            $spec = $this->specFromSchedule($schedule);
            // Report delivery: RUN_LATEST_MISSED only (never mass-send catch-up).
            $items = $this->dueFromSpec(
                (int) $schedule->id,
                $spec,
                $this->kind(),
                $nowUtc,
                $this->calculator,
                maxCatchUp: 1,
            );
            foreach ($items as $item) {
                $due[] = $item;
            }
        }

        return $due;
    }

    public function execute(RecurringOccurrence $occurrence): RecurringScheduleAdapterResult
    {
        $schedule = ReportDeliverySchedule::query()->find($occurrence->domain_schedule_id);
        if ($schedule === null) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Failed,
                failureCode: 'DOMAIN_SCHEDULE_NOT_FOUND',
                failureMessage: 'ReportDeliverySchedule missing',
            );
        }

        $scheduledLocal = CarbonImmutable::parse($occurrence->scheduled_for)
            ->setTimezone((string) $schedule->timezone);
        [$start, $end] = $this->schedules->resolvePeriod($schedule, $scheduledLocal);
        $scheduledUtc = CarbonImmutable::parse($occurrence->scheduled_for)->setTimezone('UTC');
        $key = $this->schedules->occurrenceKey($schedule, $scheduledUtc);

        $reportOccurrence = ReportDeliveryOccurrence::query()
            ->where('occurrence_key', $key)
            ->first();

        if ($reportOccurrence === null) {
            $reportOccurrence = ReportDeliveryOccurrence::query()->create([
                'schedule_id' => (int) $schedule->id,
                'scheduled_for' => $scheduledUtc,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => ReportDeliveryOccurrenceStatus::Pending,
                'occurrence_key' => $key,
            ]);
        }

        if (in_array($reportOccurrence->status, [
            ReportDeliveryOccurrenceStatus::Completed,
            ReportDeliveryOccurrenceStatus::Cancelled,
        ], true)) {
            return new RecurringScheduleAdapterResult(
                RecurringOccurrenceStatus::Completed,
                RecurringDomainRunType::ReportDeliveryOccurrence,
                (int) $reportOccurrence->id,
            );
        }

        $executed = $this->executor->execute((int) $reportOccurrence->id);
        $ok = $executed->status === ReportDeliveryOccurrenceStatus::Completed;

        return new RecurringScheduleAdapterResult(
            $ok ? RecurringOccurrenceStatus::Completed : RecurringOccurrenceStatus::Failed,
            RecurringDomainRunType::ReportDeliveryOccurrence,
            (int) $executed->id,
            failureCode: $ok ? null : ($executed->failure_category ?? 'REPORT_OCCURRENCE_FAILED'),
            failureMessage: $ok ? null : ($executed->failure_message ?? 'Report occurrence failed'),
        );
    }

    public function isScheduleActive(int $domainScheduleId): bool
    {
        $schedule = ReportDeliverySchedule::query()->find($domainScheduleId);

        return $schedule !== null && $schedule->status === ReportDeliveryScheduleStatus::Active;
    }

    public function allowedFrequencies(): array
    {
        return [RecurringFrequency::Monthly];
    }

    public function defaultMisfirePolicy(): RecurringMisfirePolicy
    {
        return RecurringMisfirePolicy::RunLatestMissed;
    }

    public function supportsManualRun(): bool
    {
        return false;
    }

    private function specFromSchedule(ReportDeliverySchedule $schedule): RecurringScheduleSpec
    {
        $time = (string) $schedule->delivery_time;
        if (strlen($time) >= 5) {
            $time = substr($time, 0, 5);
        }

        return new RecurringScheduleSpec(
            timezone: (string) $schedule->timezone,
            frequency: RecurringFrequency::Monthly,
            interval: 1,
            localTime: $time,
            dayOfMonth: (int) $schedule->day_of_month,
            monthEndPolicy: 'day_of_month',
            misfirePolicy: RecurringMisfirePolicy::RunLatestMissed,
        );
    }
}
