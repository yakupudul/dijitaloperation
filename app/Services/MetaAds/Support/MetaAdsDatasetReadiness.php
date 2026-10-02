<?php

namespace App\Services\MetaAds\Support;

use App\Enums\DataPool\DataSourceState;

final class MetaAdsDatasetReadiness
{
    public const string COVERAGE_FULLY_COVERED = 'FULLY_COVERED';

    public const string COVERAGE_PARTIALLY_COVERED = 'PARTIALLY_COVERED';

    public const string COVERAGE_NOT_COVERED = 'NOT_COVERED';

    /**
     * @param  list<string>  $coveredDates
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly bool $integrityReady,
        public readonly string $integrityStatus,
        public readonly ?string $integrityAuditRunUuid,
        public readonly string $freshnessState,
        public readonly string $coverageState,
        public readonly array $coveredDates,
        public readonly ?string $effectiveStart,
        public readonly ?string $effectiveEnd,
        public readonly bool $materializationExists,
    ) {}

    public function isUsable(): bool
    {
        return $this->integrityReady && $this->coverageState !== self::COVERAGE_NOT_COVERED;
    }

    public function isFullyCovered(): bool
    {
        return $this->integrityReady && $this->coverageState === self::COVERAGE_FULLY_COVERED;
    }

    public function dataSourceState(): DataSourceState
    {
        if (! $this->integrityReady) {
            return DataSourceState::Unavailable;
        }

        return match ($this->coverageState) {
            self::COVERAGE_FULLY_COVERED => DataSourceState::Real,
            self::COVERAGE_PARTIALLY_COVERED => DataSourceState::PartialReal,
            default => DataSourceState::Unavailable,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'integrity_ready' => $this->integrityReady,
            'integrity_status' => $this->integrityStatus,
            'integrity_audit_run_uuid' => $this->integrityAuditRunUuid,
            'freshness_state' => $this->freshnessState,
            'coverage_state' => $this->coverageState,
            'covered_dates_count' => count($this->coveredDates),
            'effective_start' => $this->effectiveStart,
            'effective_end' => $this->effectiveEnd,
            'materialization_exists' => $this->materializationExists,
            'data_source_state' => $this->dataSourceState()->value,
        ];
    }
}
