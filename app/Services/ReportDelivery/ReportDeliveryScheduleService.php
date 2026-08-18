<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryScheduleCadence;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Enums\ReportPeriodStrategy;
use App\Enums\ReportType;
use App\Models\Brand;
use App\Models\ReportDeliverySchedule;
use App\Models\ReportDeliveryScheduleRecipient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand-scoped report delivery schedules (Prompt 60).
 * Not a generic automation platform.
 */
final class ReportDeliveryScheduleService
{
    /**
     * @param  array{
     *     locale?: string,
     *     timezone?: string,
     *     cadence?: string,
     *     day_of_month?: int,
     *     delivery_time?: string,
     *     period_strategy?: string,
     *     share_ttl_hours?: int,
     *     recipients: list<array{email: string, display_name?: string|null}>
     * }  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function create(
        Brand $brand,
        array $input,
        ?User $actor = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): ReportDeliverySchedule {
        $this->assertBrandAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $recipients = $input['recipients'] ?? [];
        if (! is_array($recipients) || $recipients === []) {
            throw ValidationException::withMessages(['recipients' => 'RECIPIENTS_REQUIRED']);
        }

        $day = (int) ($input['day_of_month'] ?? 5);
        if ($day < 1 || $day > 31) {
            throw ValidationException::withMessages(['day_of_month' => 'INVALID_DAY_OF_MONTH']);
        }

        $timezone = (string) ($input['timezone'] ?? config('report_delivery.schedule.default_timezone'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'INVALID_TIMEZONE']);
        }

        $time = (string) ($input['delivery_time'] ?? '09:00');
        if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            throw ValidationException::withMessages(['delivery_time' => 'INVALID_DELIVERY_TIME']);
        }

        return DB::transaction(function () use ($brand, $input, $actor, $recipients, $day, $timezone, $time): ReportDeliverySchedule {
            $schedule = ReportDeliverySchedule::query()->create([
                'customer_id' => (int) $brand->customer_id,
                'brand_id' => (int) $brand->id,
                'report_type' => ReportType::ClientValueStory->value,
                'locale' => in_array(($input['locale'] ?? 'en'), ['en', 'tr'], true) ? ($input['locale'] ?? 'en') : 'en',
                'timezone' => $timezone,
                'cadence' => ReportDeliveryScheduleCadence::Monthly,
                'day_of_month' => $day,
                'delivery_time' => strlen($time) === 5 ? $time.':00' : $time,
                'period_strategy' => ReportPeriodStrategy::PreviousCalendarMonth,
                'share_ttl_hours' => (int) ($input['share_ttl_hours'] ?? config('report_delivery.schedule.default_share_ttl_hours')),
                'status' => ReportDeliveryScheduleStatus::Active,
                'created_by' => $actor?->id,
            ]);

            $seen = [];
            foreach ($recipients as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                ReportDeliveryScheduleRecipient::query()->create([
                    'schedule_id' => (int) $schedule->id,
                    'email' => $email,
                    'display_name' => isset($row['display_name']) ? (string) $row['display_name'] : null,
                    'enabled' => true,
                ]);
            }

            if ($seen === []) {
                throw ValidationException::withMessages(['recipients' => 'RECIPIENTS_REQUIRED']);
            }

            return $schedule->fresh(['recipients']);
        });
    }

    /**
     * @return array{scheduled_for: string, period_start: string, period_end: string}
     */
    public function previewNextOccurrence(ReportDeliverySchedule $schedule, ?CarbonImmutable $now = null): array
    {
        $now = $now?->setTimezone($schedule->timezone) ?? CarbonImmutable::now($schedule->timezone);
        $scheduledFor = $this->nextMonthlyOccurrence($schedule, $now);
        [$start, $end] = $this->resolvePeriod($schedule, $scheduledFor);

        return [
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }

    public function nextMonthlyOccurrence(ReportDeliverySchedule $schedule, CarbonImmutable $nowLocal): CarbonImmutable
    {
        $day = (int) $schedule->day_of_month;
        $time = (string) $schedule->delivery_time;
        [$h, $m] = array_map('intval', explode(':', $time));

        $candidateDay = min($day, $nowLocal->daysInMonth);
        $candidate = $nowLocal->setDate($nowLocal->year, $nowLocal->month, $candidateDay)
            ->setTime($h, $m, 0);

        if ($candidate->lessThanOrEqualTo($nowLocal)) {
            $nextMonth = $nowLocal->addMonthNoOverflow()->startOfMonth();
            $candidateDay = min($day, $nextMonth->daysInMonth);
            $candidate = $nextMonth->setDate($nextMonth->year, $nextMonth->month, $candidateDay)
                ->setTime($h, $m, 0);
        }

        return $candidate;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolvePeriod(ReportDeliverySchedule $schedule, CarbonImmutable $scheduledForLocal): array
    {
        if ($schedule->period_strategy !== ReportPeriodStrategy::PreviousCalendarMonth
            && $schedule->period_strategy?->value !== ReportPeriodStrategy::PreviousCalendarMonth->value) {
            throw ValidationException::withMessages(['period_strategy' => 'UNSUPPORTED_PERIOD_STRATEGY']);
        }

        $previous = $scheduledForLocal->subMonthNoOverflow();
        $start = $previous->startOfMonth()->startOfDay();
        $end = $previous->endOfMonth()->startOfDay();

        return [$start, $end];
    }

    public function occurrenceKey(ReportDeliverySchedule $schedule, CarbonImmutable $scheduledForUtc): string
    {
        return 'schedule:'.$schedule->id.':'.$scheduledForUtc->format('Y-m-d\TH:i:s\Z');
    }

    public function pause(ReportDeliverySchedule $schedule): void
    {
        $schedule->status = ReportDeliveryScheduleStatus::Paused;
        $schedule->save();
    }

    public function activate(ReportDeliverySchedule $schedule): void
    {
        $schedule->status = ReportDeliveryScheduleStatus::Active;
        $schedule->save();
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertBrandAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
