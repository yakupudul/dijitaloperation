<?php

namespace App\Services\DataPool\Freshness\Support;

/**
 * Per Resource × Dataset watermark concepts — never a single global last-sync.
 */
final class WatermarkSnapshot
{
    /**
     * @param  list<array{start: string, end: string}>  $coverageIntervals
     * @param  list<array{start: string, end: string}>  $internalGaps
     * @param  list<string>  $zeroRowDates
     */
    public function __construct(
        public readonly ?string $verifiedContiguousWatermark,
        public readonly ?string $latestObservedReportingDate,
        public readonly ?string $lastSuccessfulReportingDate,
        public readonly ?string $lastSuccessfulCollectionAt,
        public readonly ?string $currentCollectableEnd,
        public readonly array $coverageIntervals,
        public readonly array $internalGaps,
        public readonly array $zeroRowDates,
        public readonly bool $continuityProven,
        public readonly string $coverageSource,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verified_contiguous_watermark' => $this->verifiedContiguousWatermark,
            'latest_observed_reporting_date' => $this->latestObservedReportingDate,
            'last_successful_reporting_date' => $this->lastSuccessfulReportingDate,
            'last_successful_collection_at' => $this->lastSuccessfulCollectionAt,
            'current_collectable_end' => $this->currentCollectableEnd,
            'coverage_intervals' => $this->coverageIntervals,
            'internal_gaps' => $this->internalGaps,
            'zero_row_dates' => $this->zeroRowDates,
            'continuity_proven' => $this->continuityProven,
            'coverage_source' => $this->coverageSource,
        ];
    }
}
