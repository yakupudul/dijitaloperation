<?php

namespace App\Services\DataPool;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DatasetMaterialization;
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
            $min = min($dates);
            $max = max($dates);
            $materialization->coverage_start_date = $materialization->coverage_start_date
                ? min($materialization->coverage_start_date->toDateString(), $min)
                : $min;
            $materialization->coverage_end_date = $materialization->coverage_end_date
                ? max($materialization->coverage_end_date->toDateString(), $max)
                : $max;
            $materialization->last_source_data_at = CarbonImmutable::parse($max)->endOfDay();
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
