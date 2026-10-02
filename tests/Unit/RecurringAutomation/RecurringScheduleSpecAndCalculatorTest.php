<?php

namespace Tests\Unit\RecurringAutomation;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringOccurrenceStatus;
use App\Enums\RecurringScheduleKind;
use App\Models\RecurringOccurrence;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecurringScheduleSpecAndCalculatorTest extends TestCase
{
    public function test_spec_fingerprint_is_stable_for_same_fields(): void
    {
        $a = new RecurringScheduleSpec(
            timezone: 'Europe/Istanbul',
            frequency: RecurringFrequency::Daily,
            interval: 1,
            localTime: '09:00',
            misfirePolicy: RecurringMisfirePolicy::SkipMissed,
        );
        $b = new RecurringScheduleSpec(
            timezone: 'Europe/Istanbul',
            frequency: RecurringFrequency::Daily,
            interval: 1,
            localTime: '09:00',
            misfirePolicy: RecurringMisfirePolicy::SkipMissed,
        );

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_spec_assert_valid_rejects_bad_timezone(): void
    {
        $spec = new RecurringScheduleSpec(
            timezone: 'Not/AZone',
            frequency: RecurringFrequency::Hourly,
            interval: 1,
        );

        $this->expectException(ValidationException::class);
        $spec->assertValid();
    }

    public function test_next_daily_occurrence_after_local_time(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Daily,
            interval: 1,
            localTime: '09:00',
        );

        $next = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-08-16 09:00:00', 'UTC'));
        $this->assertSame('2026-08-17 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_hourly_from_floored_time(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Hourly,
            interval: 2,
        );

        $next = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-08-16 10:30:00', 'UTC'));
        $this->assertSame('2026-08-16 12:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_weekly_matches_weekday(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Weekly,
            interval: 1,
            localTime: '10:00',
            weekdays: [1, 3],
        );

        // Sunday 2026-08-16 → next Monday
        $next = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-08-16 12:00:00', 'UTC'));
        $this->assertSame(1, (int) $next->dayOfWeekIso);
        $this->assertSame('2026-08-17 10:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_monthly_clamps_day_of_month(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Monthly,
            interval: 1,
            localTime: '08:00',
            dayOfMonth: 31,
            monthEndPolicy: 'day_of_month',
        );

        $next = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC'));
        $this->assertSame('2026-02-28 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_next_monthly_last_day_policy(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $spec = new RecurringScheduleSpec(
            timezone: 'UTC',
            frequency: RecurringFrequency::Monthly,
            interval: 1,
            localTime: '08:00',
            monthEndPolicy: 'last_day_of_month',
        );

        $next = $calc->nextOccurrence($spec, CarbonImmutable::parse('2026-01-15 00:00:00', 'UTC'));
        $this->assertSame('2026-01-31 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_resolve_previous_calendar_month_and_week(): void
    {
        $calc = new RecurringOccurrenceCalculator;
        $scheduled = CarbonImmutable::parse('2026-08-05 09:00:00', 'Europe/Istanbul');

        [$mStart, $mEnd] = $calc->resolvePreviousCalendarMonth($scheduled);
        $this->assertSame('2026-07-01', $mStart->toDateString());
        $this->assertSame('2026-07-31', $mEnd->toDateString());

        [$wStart, $wEnd] = $calc->resolvePreviousCalendarWeek($scheduled);
        $this->assertSame('2026-07-27', $wStart->toDateString());
        $this->assertSame('2026-08-02', $wEnd->toDateString());
    }

    public function test_occurrence_is_terminal(): void
    {
        $pending = new RecurringOccurrence(['status' => RecurringOccurrenceStatus::Pending]);
        $this->assertFalse($pending->isTerminal());

        $done = new RecurringOccurrence(['status' => RecurringOccurrenceStatus::Completed]);
        $this->assertTrue($done->isTerminal());

        $skipped = new RecurringOccurrence(['status' => RecurringOccurrenceStatus::Skipped]);
        $this->assertTrue($skipped->isTerminal());
    }

    public function test_schedule_kind_values(): void
    {
        $this->assertSame('collection', RecurringScheduleKind::Collection->value);
        $this->assertSame('report_delivery', RecurringScheduleKind::ReportDelivery->value);
    }
}
