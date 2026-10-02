<?php

namespace App\Services\DataPool\Freshness;

use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Freshness\Support\WatermarkSnapshot;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;

/**
 * Derives watermark concepts from canonical coverage evidence.
 * Never uses MAX(fact_date) alone as verified watermark truth.
 */
final class DatasetWatermarkCalculator
{
    public function __construct(
        private readonly CollectableEndResolver $collectableEnds = new CollectableEndResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $policy
     */
    public function calculate(
        ?DatasetMaterialization $materialization,
        array $policy,
        ?string $reportingTimezone = null,
        ?\DateTimeInterface $clockNow = null,
    ): WatermarkSnapshot {
        $collectableEnd = $this->collectableEnds->resolve($policy, $reportingTimezone, $clockNow);

        if ($materialization === null) {
            return new WatermarkSnapshot(
                verifiedContiguousWatermark: null,
                latestObservedReportingDate: null,
                lastSuccessfulReportingDate: null,
                lastSuccessfulCollectionAt: null,
                currentCollectableEnd: $collectableEnd,
                coverageIntervals: [],
                internalGaps: [],
                zeroRowDates: [],
                continuityProven: false,
                coverageSource: 'none',
            );
        }

        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        $successfulDates = [];
        if (isset($meta['successful_coverage_dates']) && is_array($meta['successful_coverage_dates'])) {
            $successfulDates = array_values(array_filter($meta['successful_coverage_dates'], 'is_string'));
        }

        $zeroRowDates = [];
        if (isset($meta['zero_row_success_dates']) && is_array($meta['zero_row_success_dates'])) {
            $zeroRowDates = array_values(array_filter($meta['zero_row_success_dates'], 'is_string'));
            foreach ($zeroRowDates as $date) {
                $successfulDates[] = $date;
            }
        }

        $successfulDates = array_values(array_unique($successfulDates));
        sort($successfulDates);

        $lastCollectedAt = optional($materialization->last_collected_at)?->toIso8601String();

        if ($successfulDates !== []) {
            $set = CoverageIntervalSet::fromSuccessfulDates($successfulDates);
            $bounds = $set->bounds();
            $verified = $set->verifiedContiguousWatermark();

            return new WatermarkSnapshot(
                verifiedContiguousWatermark: $verified,
                latestObservedReportingDate: $bounds['end'],
                lastSuccessfulReportingDate: $verified,
                lastSuccessfulCollectionAt: $lastCollectedAt,
                currentCollectableEnd: $collectableEnd,
                coverageIntervals: $set->intervals,
                internalGaps: $set->internalGaps(),
                zeroRowDates: $zeroRowDates,
                continuityProven: true,
                coverageSource: 'successful_coverage_dates',
            );
        }

        // Persisted derived watermark (from prior proven interval updates) may be reused.
        $persistedVerified = is_string($meta['verified_contiguous_watermark'] ?? null)
            ? (string) $meta['verified_contiguous_watermark']
            : null;
        $persistedLatest = is_string($meta['latest_observed_reporting_date'] ?? null)
            ? (string) $meta['latest_observed_reporting_date']
            : null;

        if ($persistedVerified !== null) {
            return new WatermarkSnapshot(
                verifiedContiguousWatermark: $persistedVerified,
                latestObservedReportingDate: $persistedLatest ?? $persistedVerified,
                lastSuccessfulReportingDate: $persistedVerified,
                lastSuccessfulCollectionAt: $lastCollectedAt,
                currentCollectableEnd: $collectableEnd,
                coverageIntervals: is_array($meta['coverage_intervals'] ?? null) ? $meta['coverage_intervals'] : [],
                internalGaps: is_array($meta['internal_gaps'] ?? null) ? $meta['internal_gaps'] : [],
                zeroRowDates: $zeroRowDates,
                continuityProven: true,
                coverageSource: 'persisted_watermark_metadata',
            );
        }

        // Min/max coverage_end alone is NOT a verified contiguous watermark.
        $claimedEnd = optional($materialization->coverage_end_date)?->toDateString();

        return new WatermarkSnapshot(
            verifiedContiguousWatermark: null,
            latestObservedReportingDate: $claimedEnd,
            lastSuccessfulReportingDate: null,
            lastSuccessfulCollectionAt: $lastCollectedAt,
            currentCollectableEnd: $collectableEnd,
            coverageIntervals: [],
            internalGaps: [],
            zeroRowDates: $zeroRowDates,
            continuityProven: false,
            coverageSource: $claimedEnd !== null ? 'coverage_bounds_unproven' : 'none',
        );
    }
}
