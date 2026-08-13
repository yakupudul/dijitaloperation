<?php

namespace App\Services\DataPool\Freshness;

use App\Enums\DataPool\DatasetCollectionMode;
use App\Services\Collection\Support\CollectionClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Resolves current_collectable_end from Dataset policy + reporting timezone.
 * Wall-clock "today" is never automatically a complete reporting day.
 */
final class CollectableEndResolver
{
    public function __construct(
        private readonly CollectionClock $clock = new CollectionClock,
    ) {}

    /**
     * @param  array<string, mixed>  $policy
     */
    public function resolve(
        array $policy,
        ?string $reportingTimezone = null,
        ?\DateTimeInterface $clockNow = null,
    ): ?string {
        $mode = DatasetCollectionMode::tryFrom((string) ($policy['collection_mode'] ?? ''))
            ?? DatasetCollectionMode::HistoricalIncremental;

        if (! $mode->usesDailyWatermark()) {
            return null;
        }

        $tz = $this->resolveTimezone($policy, $reportingTimezone);
        $now = $clockNow !== null
            ? CarbonImmutable::instance(Carbon::parse($clockNow))->timezone($tz)
            : $this->clock->now($tz);

        $providerLocalDate = $now->startOfDay();
        $lag = (int) ($policy['safe_collection_lag_days'] ?? 1);
        if ($lag < 0) {
            $lag = 0;
        }

        $currentPeriodCollectable = (bool) ($policy['current_period_collectable'] ?? false);
        if (! $currentPeriodCollectable && $lag < 1) {
            // Open day must not be treated complete unless policy explicitly permits.
            $lag = 1;
        }

        return $providerLocalDate->subDays($lag)->toDateString();
    }

    /**
     * Provider-local reporting "today" (open day) — informational; not collectable end.
     *
     * @param  array<string, mixed>  $policy
     */
    public function providerLocalReportingDate(
        array $policy,
        ?string $reportingTimezone = null,
        ?\DateTimeInterface $clockNow = null,
    ): string {
        $tz = $this->resolveTimezone($policy, $reportingTimezone);
        $now = $clockNow !== null
            ? CarbonImmutable::instance(Carbon::parse($clockNow))->timezone($tz)
            : $this->clock->now($tz);

        return $now->toDateString();
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function resolveTimezone(array $policy, ?string $reportingTimezone): string
    {
        if (is_string($reportingTimezone) && $reportingTimezone !== '') {
            return $reportingTimezone;
        }

        $source = (string) ($policy['timezone_source'] ?? '');

        // GSC official reporting-day labels use Pacific Time.
        if ($source === 'gsc_reporting_date_semantics') {
            return 'America/Los_Angeles';
        }

        // Without a resource timezone, planning uses UTC — never browser TZ.
        return 'UTC';
    }
}
