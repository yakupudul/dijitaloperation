<?php

namespace App\Services\RecurringReviews;

use App\Enums\RecurringReviewCadence;
use App\Models\RecurringReviewSchedule;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Timezone/DST-aware recurrence. Does not materialize Runs.
 */
final class RecurringReviewDueCalculator
{
    public function nextDueAfter(RecurringReviewSchedule $schedule, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        $status = $schedule->status?->value ?? (string) $schedule->status;
        if ($status !== 'active') {
            return null;
        }

        $tz = new DateTimeZone((string) $schedule->timezone);
        $starts = CarbonImmutable::parse($schedule->starts_at)->timezone($tz);
        $cursor = ($after ?? CarbonImmutable::now($tz))->timezone($tz);

        if ($schedule->ends_at !== null) {
            $ends = CarbonImmutable::parse($schedule->ends_at)->timezone($tz);
            if ($cursor->greaterThan($ends)) {
                return null;
            }
        }

        $cadence = $schedule->cadence instanceof RecurringReviewCadence
            ? $schedule->cadence
            : RecurringReviewCadence::from((string) $schedule->cadence);

        $occurrence = $starts;
        // Walk forward from starts until after cursor (bounded; schedules are sparse).
        $guard = 0;
        while ($occurrence->lessThanOrEqualTo($cursor) && $guard < 520) {
            $occurrence = $this->advance($occurrence, $cadence);
            $guard++;
        }

        if ($schedule->ends_at !== null) {
            $ends = CarbonImmutable::parse($schedule->ends_at)->timezone($tz);
            if ($occurrence->greaterThan($ends)) {
                return null;
            }
        }

        return $occurrence;
    }

    public function occurrenceKey(CarbonImmutable $dueLocal): string
    {
        return 'scheduled:'.$dueLocal->format('Y-m-d\TH:i:s');
    }

    public function advance(CarbonImmutable $from, RecurringReviewCadence $cadence): CarbonImmutable
    {
        return match ($cadence) {
            RecurringReviewCadence::Weekly => $from->addWeek(),
            RecurringReviewCadence::Monthly => $from->addMonthNoOverflow(),
            RecurringReviewCadence::Quarterly => $from->addMonthsNoOverflow(3),
        };
    }
}
