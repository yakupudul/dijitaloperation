<?php

namespace App\Services\DataPool;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Carbon\CarbonImmutable;

/**
 * Dataset materialization / pool state — distinct from CollectionRun control-plane state.
 */
final class MaterializationService
{
    /**
     * @param  list<array<string, mixed>>  $preparedRows
     * @param  array{inserted: int, updated: int, unchanged: int}  $stats
     */
    public function recordSuccessfulWrite(NormalizedDatasetBatch $batch, array $preparedRows, array $stats): void
    {
        $provider = $batch->providerOrSource
            ?? $this->inferProvider($batch->datasetId);

        $materialization = DatasetMaterialization::query()->firstOrNew([
            'dataset_id' => $batch->datasetId,
            'digital_asset_id' => $batch->digitalAssetId,
            'external_resource_id' => $batch->externalResourceId,
            'contract_version' => $batch->contractVersion,
        ]);

        if (! $materialization->exists) {
            $materialization->provider_or_source = $provider;
            $materialization->status = MaterializationStatus::NotCollected;
            $materialization->row_count_approx = 0;
            $materialization->row_count_semantics = 'approximate_from_batches';
            $materialization->partial = false;
        }

        $dates = [];
        foreach ($preparedRows as $row) {
            if (! empty($row['reporting_date'])) {
                $dates[] = CarbonImmutable::parse($row['reporting_date'])->toDateString();
            }
        }

        if ($dates !== []) {
            $this->mergeSuccessfulCoverageDates($materialization, $dates, zeroRow: false);
            $materialization->last_source_data_at = CarbonImmutable::parse(max($dates))->endOfDay();
        }

        $materialization->provider_or_source = $provider;
        $materialization->last_successful_collection_run_id = $batch->collectionRunId;
        $materialization->last_successful_dataset_run_id = $batch->datasetRunId;
        $materialization->last_collected_at = now();
        $materialization->row_count_approx = (int) $materialization->row_count_approx + $stats['inserted'];
        $materialization->status = $materialization->partial
            ? MaterializationStatus::Partial
            : MaterializationStatus::Available;
        $materialization->save();
    }

    /**
     * Record durable successful coverage for inclusive reporting dates, including zero-row days.
     * Advances verified contiguous watermark from interval evidence — never from MAX(fact_date) alone.
     *
     * @param  list<string>  $dates  Y-m-d
     */
    public function recordSuccessfulCoverageDates(
        string $datasetId,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        int $contractVersion,
        array $dates,
        ?int $collectionRunId = null,
        ?int $datasetRunId = null,
        ?string $providerOrSource = null,
        bool $zeroRow = false,
    ): DatasetMaterialization {
        $materialization = DatasetMaterialization::query()->firstOrNew([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'contract_version' => $contractVersion,
        ]);

        if (! $materialization->exists) {
            $materialization->provider_or_source = $providerOrSource ?? $this->inferProvider($datasetId);
            $materialization->status = MaterializationStatus::Available;
            $materialization->row_count_approx = 0;
            $materialization->row_count_semantics = 'approximate_from_batches';
            $materialization->partial = false;
        }

        $this->mergeSuccessfulCoverageDates($materialization, $dates, zeroRow: $zeroRow);

        if ($collectionRunId !== null) {
            $materialization->last_successful_collection_run_id = $collectionRunId;
        }
        if ($datasetRunId !== null) {
            $materialization->last_successful_dataset_run_id = $datasetRunId;
        }
        $materialization->last_collected_at = now();
        if ($materialization->status === MaterializationStatus::NotCollected) {
            $materialization->status = MaterializationStatus::Available;
        }
        $materialization->save();

        return $materialization;
    }

    /**
     * Expand an inclusive Y-m-d range into daily coverage success dates.
     */
    public function recordSuccessfulCoverageRange(
        string $datasetId,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        int $contractVersion,
        string $start,
        string $end,
        ?int $collectionRunId = null,
        ?int $datasetRunId = null,
        ?string $providerOrSource = null,
        bool $zeroRow = false,
    ): DatasetMaterialization {
        $dates = [];
        $cursor = CarbonImmutable::parse($start)->startOfDay();
        $last = CarbonImmutable::parse($end)->startOfDay();
        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $this->recordSuccessfulCoverageDates(
            $datasetId,
            $digitalAssetId,
            $externalResourceId,
            $contractVersion,
            $dates,
            $collectionRunId,
            $datasetRunId,
            $providerOrSource,
            $zeroRow,
        );
    }

    public function markPartial(string $datasetId, ?int $digitalAssetId, ?int $externalResourceId, int $contractVersion = 1): void
    {
        $row = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('contract_version', $contractVersion)
            ->first();

        if ($row === null) {
            return;
        }

        $row->forceFill([
            'partial' => true,
            'status' => MaterializationStatus::Partial,
        ])->save();
    }

    public function markStale(DatasetMaterialization $materialization): void
    {
        if ($materialization->status === MaterializationStatus::NotCollected) {
            return;
        }

        $materialization->forceFill([
            'status' => MaterializationStatus::Stale,
        ])->save();
    }

    /**
     * Failed refresh must not erase previously usable pool state.
     */
    public function recordFailedRefresh(DatasetMaterialization $materialization): void
    {
        if (in_array($materialization->status, [MaterializationStatus::Available, MaterializationStatus::Partial], true)) {
            $this->markStale($materialization);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    private function mergeSuccessfulCoverageDates(
        DatasetMaterialization $materialization,
        array $dates,
        bool $zeroRow,
    ): void {
        $dates = array_values(array_unique(array_filter($dates, 'is_string')));
        if ($dates === []) {
            return;
        }

        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        $existing = [];
        if (isset($meta['successful_coverage_dates']) && is_array($meta['successful_coverage_dates'])) {
            $existing = array_values(array_filter($meta['successful_coverage_dates'], 'is_string'));
        }

        $merged = array_values(array_unique(array_merge($existing, $dates)));
        sort($merged);

        if ($zeroRow) {
            $zero = [];
            if (isset($meta['zero_row_success_dates']) && is_array($meta['zero_row_success_dates'])) {
                $zero = array_values(array_filter($meta['zero_row_success_dates'], 'is_string'));
            }
            $zero = array_values(array_unique(array_merge($zero, $dates)));
            sort($zero);
            $meta['zero_row_success_dates'] = $zero;
        }

        $set = CoverageIntervalSet::fromSuccessfulDates($merged);
        $bounds = $set->bounds();
        $verified = $set->verifiedContiguousWatermark();

        $meta['successful_coverage_dates'] = $merged;
        $meta['coverage_intervals'] = $set->intervals;
        $meta['internal_gaps'] = $set->internalGaps();
        $meta['verified_contiguous_watermark'] = $verified;
        $meta['latest_observed_reporting_date'] = $bounds['end'];
        $meta['last_successful_reporting_date'] = $verified;
        $meta['watermark_provenance'] = 'successful_coverage_dates';
        $meta['max_fact_date_is_not_verified_watermark'] = true;

        $materialization->freshness_metadata = $meta;

        if ($bounds['start'] !== null) {
            $materialization->coverage_start_date = $materialization->coverage_start_date
                ? min($materialization->coverage_start_date->toDateString(), $bounds['start'])
                : $bounds['start'];
        }
        // coverage_end_date tracks latest observed reporting boundary (may exceed verified watermark when gaps exist).
        if ($bounds['end'] !== null) {
            $materialization->coverage_end_date = $materialization->coverage_end_date
                ? max($materialization->coverage_end_date->toDateString(), $bounds['end'])
                : $bounds['end'];
        }

        // Partial flag reflects unresolved internal gaps when interval evidence exists.
        if ($set->internalGaps() !== []) {
            $materialization->partial = true;
            $materialization->status = MaterializationStatus::Partial;
        } elseif ($materialization->partial && $set->internalGaps() === [] && $verified !== null) {
            $materialization->partial = false;
            if ($materialization->status === MaterializationStatus::Partial) {
                $materialization->status = MaterializationStatus::Available;
            }
        }
    }

    private function inferProvider(string $datasetId): string
    {
        return match (true) {
            str_starts_with($datasetId, 'ga4_') => 'GA4',
            str_starts_with($datasetId, 'gsc_') => 'SEARCH_CONSOLE',
            str_starts_with($datasetId, 'google_ads_') => 'GOOGLE_ADS',
            str_starts_with($datasetId, 'meta_') => 'META_ADS',
            str_starts_with($datasetId, 'website_') => 'WEBSITE_DIRECT',
            str_starts_with($datasetId, 'dataforseo_') => 'DATAFORSEO',
            default => 'UNKNOWN',
        };
    }
}
