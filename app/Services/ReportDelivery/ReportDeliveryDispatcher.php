<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryOccurrenceStatus;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Jobs\Reports\ExecuteReportDeliveryOccurrenceJob;
use App\Models\ReportDeliveryOccurrence;
use App\Models\ReportDeliverySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Due-occurrence dispatcher — identifies work and queues jobs (Prompt 60).
 */
final class ReportDeliveryDispatcher
{
    public function __construct(
        private readonly ReportDeliveryScheduleService $schedules,
    ) {}

    /**
     * @return list<int> occurrence IDs claimed/dispatched
     */
    public function dispatchDue(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now('UTC');
        $ids = [];

        $active = ReportDeliverySchedule::query()
            ->where('status', ReportDeliveryScheduleStatus::Active)
            ->with('recipients')
            ->get();

        foreach ($active as $schedule) {
            $localNow = $now->setTimezone($schedule->timezone);
            $next = $this->schedules->nextMonthlyOccurrence($schedule, $localNow->subMinute());
            // Due if scheduled_for <= now (within lookback window).
            $scheduledUtc = $next->setTimezone('UTC');
            if ($scheduledUtc->greaterThan($now)) {
                continue;
            }

            $lookback = (int) config('report_delivery.schedule.dispatcher_lookback_minutes', 30);
            if ($scheduledUtc->lessThan($now->subMinutes($lookback))) {
                // Too old for this dispatcher pass — still create if not exists for observability.
            }

            $key = $this->schedules->occurrenceKey($schedule, $scheduledUtc);
            $occurrence = $this->ensureOccurrence($schedule, $scheduledUtc, $next);
            if ($occurrence === null) {
                continue;
            }

            if (in_array($occurrence->status, [
                ReportDeliveryOccurrenceStatus::Completed,
                ReportDeliveryOccurrenceStatus::Cancelled,
                ReportDeliveryOccurrenceStatus::Failed,
            ], true)) {
                continue;
            }

            if ($occurrence->status === ReportDeliveryOccurrenceStatus::Pending) {
                ExecuteReportDeliveryOccurrenceJob::dispatch((int) $occurrence->id);
                $ids[] = (int) $occurrence->id;
            }
        }

        return $ids;
    }

    private function ensureOccurrence(
        ReportDeliverySchedule $schedule,
        CarbonImmutable $scheduledUtc,
        CarbonImmutable $scheduledLocal,
    ): ?ReportDeliveryOccurrence {
        $key = $this->schedules->occurrenceKey($schedule, $scheduledUtc);

        return DB::transaction(function () use ($schedule, $scheduledUtc, $scheduledLocal, $key): ReportDeliveryOccurrence {
            $existing = ReportDeliveryOccurrence::query()
                ->where('occurrence_key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            [$start, $end] = $this->schedules->resolvePeriod($schedule, $scheduledLocal);

            return ReportDeliveryOccurrence::query()->create([
                'schedule_id' => (int) $schedule->id,
                'scheduled_for' => $scheduledUtc,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => ReportDeliveryOccurrenceStatus::Pending,
                'occurrence_key' => $key,
            ]);
        });
    }
}
