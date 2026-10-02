<?php

namespace App\Services\RecurringAutomation\Concerns;

use App\Enums\RecurringMisfirePolicy;
use App\Enums\RecurringScheduleKind;
use App\Models\RecurringOccurrence;
use App\Support\RecurringAutomation\RecurringOccurrenceCalculator;
use App\Support\RecurringAutomation\RecurringScheduleSpec;
use Carbon\CarbonImmutable;

/**
 * Shared due-occurrence discovery with bounded misfire policies.
 */
trait DiscoversDueOccurrences
{
    /**
     * Walk forward from a lookback window and return due slots according to misfire policy.
     *
     * @return list<array{domain_schedule_id: int, spec: RecurringScheduleSpec, scheduled_for_utc: CarbonImmutable}>
     */
    protected function dueFromSpec(
        int $domainScheduleId,
        RecurringScheduleSpec $spec,
        RecurringScheduleKind $kind,
        CarbonImmutable $nowUtc,
        RecurringOccurrenceCalculator $calculator,
        int $maxCatchUp = 3,
        int $lookbackDays = 45,
    ): array {
        $spec->assertValid();
        $localNow = $nowUtc->setTimezone($spec->timezone);
        $cursor = $localNow->subDays($lookbackDays);
        if ($spec->startsAt !== null && $spec->startsAt->setTimezone($spec->timezone)->greaterThan($cursor)) {
            $cursor = $spec->startsAt->setTimezone($spec->timezone)->subSecond();
        }

        $dueSlots = [];
        $guard = 0;
        while ($guard < 96) {
            $guard++;
            try {
                $next = $calculator->nextOccurrence($spec, $cursor);
            } catch (\Throwable) {
                break;
            }
            $nextUtc = $next->setTimezone('UTC');
            if ($nextUtc->greaterThan($nowUtc)) {
                break;
            }

            $exists = RecurringOccurrence::query()
                ->where('schedule_kind', $kind)
                ->where('domain_schedule_id', $domainScheduleId)
                ->where('scheduled_for', $nextUtc)
                ->exists();

            if (! $exists) {
                $dueSlots[] = [
                    'domain_schedule_id' => $domainScheduleId,
                    'spec' => $spec,
                    'scheduled_for_utc' => $nextUtc,
                ];
            }

            $cursor = $next;
        }

        if ($dueSlots === []) {
            return [];
        }

        $latest = end($dueSlots);
        assert(is_array($latest));

        return match ($spec->misfirePolicy) {
            // Only fire if the latest slot is still inside the dispatcher lookback window.
            RecurringMisfirePolicy::SkipMissed => $this->withinLookbackWindow($latest, $nowUtc, 30) ? [$latest] : [],
            RecurringMisfirePolicy::RunLatestMissed => [$latest],
            RecurringMisfirePolicy::CatchUpBounded => array_values(array_slice($dueSlots, -$maxCatchUp)),
        };
    }

    /**
     * @param  array{domain_schedule_id: int, spec: RecurringScheduleSpec, scheduled_for_utc: CarbonImmutable}  $slot
     */
    private function withinLookbackWindow(array $slot, CarbonImmutable $nowUtc, int $lookbackMinutes): bool
    {
        $scheduled = $slot['scheduled_for_utc'];
        if ($scheduled->greaterThan($nowUtc)) {
            return false;
        }

        return $scheduled->greaterThanOrEqualTo($nowUtc->subMinutes($lookbackMinutes));
    }
}
