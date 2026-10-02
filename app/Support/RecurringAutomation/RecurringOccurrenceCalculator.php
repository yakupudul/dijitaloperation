<?php

namespace App\Support\RecurringAutomation;

use App\Enums\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Timezone-aware next-occurrence calculator for shared recurring automation (Prompt 61).
 */
final class RecurringOccurrenceCalculator
{
    /**
     * Next occurrence strictly after $afterLocalInclusiveExclusive (exclusive lower bound),
     * evaluated in the schedule timezone.
     */
    public function nextOccurrence(RecurringScheduleSpec $spec, CarbonImmutable $afterLocalInclusiveExclusive): CarbonImmutable
    {
        $spec->assertValid();

        $tz = $spec->timezone;
        $after = $afterLocalInclusiveExclusive->setTimezone($tz);

        if ($spec->startsAt !== null) {
            $starts = $spec->startsAt->setTimezone($tz);
            if ($after->lessThan($starts)) {
                $after = $starts->subSecond();
            }
        }

        $candidate = match ($spec->frequency) {
            RecurringFrequency::Hourly => $this->nextHourly($spec, $after),
            RecurringFrequency::Daily => $this->nextDaily($spec, $after),
            RecurringFrequency::Weekly => $this->nextWeekly($spec, $after),
            RecurringFrequency::Monthly => $this->nextMonthly($spec, $after),
        };

        if ($spec->endsAt !== null && $candidate->greaterThan($spec->endsAt->setTimezone($tz))) {
            throw ValidationException::withMessages(['schedule' => 'NO_NEXT_OCCURRENCE_WITHIN_WINDOW']);
        }

        return $candidate;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolvePreviousCalendarMonth(CarbonImmutable $scheduledForLocal): array
    {
        $previous = $scheduledForLocal->subMonthNoOverflow();

        return [
            $previous->startOfMonth()->startOfDay(),
            $previous->endOfMonth()->startOfDay(),
        ];
    }

    /**
     * Previous ISO calendar week (Mon–Sun) relative to the scheduled local instant.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolvePreviousCalendarWeek(CarbonImmutable $scheduledForLocal): array
    {
        $startOfThisWeek = $scheduledForLocal->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $start = $startOfThisWeek->subWeek();
        $end = $start->addDays(6)->startOfDay();

        return [$start, $end];
    }

    private function nextHourly(RecurringScheduleSpec $spec, CarbonImmutable $after): CarbonImmutable
    {
        $interval = $spec->interval;
        $floored = $after->setMinute(0)->setSecond(0)->setMicrosecond(0);
        if ($floored->lessThanOrEqualTo($after)) {
            return $floored->addHours($interval);
        }

        return $floored;
    }

    private function nextDaily(RecurringScheduleSpec $spec, CarbonImmutable $after): CarbonImmutable
    {
        [$h, $m] = $this->parseLocalTime($spec->localTime);
        $candidate = $after->setTime($h, $m, 0);
        if ($candidate->lessThanOrEqualTo($after)) {
            $candidate = $candidate->addDays($spec->interval)->setTime($h, $m, 0);
        }

        return $candidate;
    }

    private function nextWeekly(RecurringScheduleSpec $spec, CarbonImmutable $after): CarbonImmutable
    {
        [$h, $m] = $this->parseLocalTime($spec->localTime);
        /** @var list<int> $weekdays */
        $weekdays = array_values(array_unique($spec->weekdays ?? []));
        sort($weekdays);

        $horizonDays = max(14, ($spec->interval * 7) + 7);
        for ($i = 0; $i <= $horizonDays; $i++) {
            $day = $after->addDays($i)->startOfDay();
            $iso = (int) $day->dayOfWeekIso;
            if (! in_array($iso, $weekdays, true)) {
                continue;
            }

            if ($spec->interval > 1) {
                $epoch = CarbonImmutable::create(1970, 1, 5, 0, 0, 0, $spec->timezone);
                $weeksSince = (int) floor($epoch->diffInDays($day) / 7);
                if ($weeksSince % $spec->interval !== 0) {
                    continue;
                }
            }

            $candidate = $day->setTime($h, $m, 0);
            if ($candidate->greaterThan($after)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['schedule' => 'NO_NEXT_WEEKLY_OCCURRENCE']);
    }

    private function nextMonthly(RecurringScheduleSpec $spec, CarbonImmutable $after): CarbonImmutable
    {
        [$h, $m] = $this->parseLocalTime($spec->localTime);
        $interval = $spec->interval;
        $base = $after->startOfMonth();

        for ($i = 0; $i < 48; $i++) {
            if ($i % $interval !== 0) {
                continue;
            }

            $month = $base->addMonthsNoOverflow($i);

            if ($spec->monthEndPolicy === 'last_day_of_month') {
                $day = $month->daysInMonth;
            } else {
                $day = min((int) $spec->dayOfMonth, $month->daysInMonth);
            }

            $candidate = $month->setDate($month->year, $month->month, $day)->setTime($h, $m, 0);
            if ($candidate->greaterThan($after)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['schedule' => 'NO_NEXT_MONTHLY_OCCURRENCE']);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseLocalTime(?string $localTime): array
    {
        if ($localTime === null || ! preg_match('/^(\d{2}):(\d{2})$/', $localTime, $matches)) {
            throw ValidationException::withMessages(['local_time' => 'INVALID_LOCAL_TIME']);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }
}
