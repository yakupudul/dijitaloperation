<?php

namespace App\Services\Analysis\Support;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Usable collected-facts window: non-partial verified contiguous coverage plus completed DatasetRuns.
 * Running/failed DatasetRuns with partial warehouse batches are never a synthesis source.
 */
final class CollectedFactsCompletedCoverage
{
    public function __construct(
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $datasetRunId,
        public readonly ?int $collectionRunId,
        public readonly int $materializationId,
        public readonly string $datasetId,
        public readonly int $digitalAssetId,
        public readonly int $externalResourceId,
    ) {}

    public static function resolve(
        string $datasetId,
        int $digitalAssetId,
        int $externalResourceId,
        int $periodDays,
    ): ?self {
        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->first();

        if (! $materialization instanceof DatasetMaterialization) {
            return null;
        }

        if ($materialization->last_successful_dataset_run_id === null
            || $materialization->coverage_end_date === null
            || $materialization->partial === true
            || $materialization->status === MaterializationStatus::Partial
            || in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true)
        ) {
            return null;
        }

        $datasetRun = CollectionDatasetRun::query()->find($materialization->last_successful_dataset_run_id);
        if (! $datasetRun instanceof CollectionDatasetRun || $datasetRun->status !== CollectionRunStatus::Completed) {
            return null;
        }

        $coverageDates = self::successfulCoverageDates($materialization);
        if ($coverageDates === []) {
            return null;
        }

        $set = CoverageIntervalSet::fromSuccessfulDates($coverageDates);
        $watermark = self::verifiedWatermark($materialization, $set);
        if ($watermark === null) {
            return null;
        }

        $periodEnd = $watermark;
        $periodStart = CarbonImmutable::parse($periodEnd)->subDays($periodDays - 1)->toDateString();

        if ($set->gapsIn($periodStart, $periodEnd) !== []) {
            return null;
        }

        return new self(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            datasetRunId: (int) $datasetRun->id,
            collectionRunId: $materialization->last_successful_collection_run_id !== null
                ? (int) $materialization->last_successful_collection_run_id
                : null,
            materializationId: (int) $materialization->id,
            datasetId: $datasetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
        );
    }

    /**
     * Usable current-state snapshot: non-partial materialization whose last successful DatasetRun completed.
     * Snapshot datasets have no 28-day reporting-date window; running/failed/partial runs are never a source.
     */
    public static function resolveCurrentState(
        string $datasetId,
        int $digitalAssetId,
        int $externalResourceId,
    ): ?self {
        $materialization = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->first();

        if (! $materialization instanceof DatasetMaterialization) {
            return null;
        }

        if ($materialization->last_successful_dataset_run_id === null
            || $materialization->partial === true
            || $materialization->status === MaterializationStatus::Partial
            || in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true)
        ) {
            return null;
        }

        $datasetRun = CollectionDatasetRun::query()->find($materialization->last_successful_dataset_run_id);
        if (! $datasetRun instanceof CollectionDatasetRun || $datasetRun->status !== CollectionRunStatus::Completed) {
            return null;
        }

        $collectedAt = $materialization->last_collected_at?->toDateString() ?? '';

        return new self(
            periodStart: $collectedAt,
            periodEnd: $collectedAt,
            datasetRunId: (int) $datasetRun->id,
            collectionRunId: $materialization->last_successful_collection_run_id !== null
                ? (int) $materialization->last_successful_collection_run_id
                : null,
            materializationId: (int) $materialization->id,
            datasetId: $datasetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
        );
    }

    /**
     * Restrict warehouse facts to the requested window and rows last written by a completed DatasetRun
     * for this asset/dataset. Incremental refreshes keep older completed days; running/failed rows stay out.
     */
    public function constrainFactsQuery(Builder $query): Builder
    {
        $completedIds = $this->completedDatasetRunIds();
        if ($completedIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->whereBetween('reporting_date', [$this->periodStart, $this->periodEnd])
            ->whereIn('last_dataset_run_id', $completedIds);
    }

    /**
     * Restrict UPSERT_CURRENT_STATE rows to the materialization's latest successful DatasetRun.
     * Historical completed snapshot runs are not current; dated facts still use constrainFactsQuery().
     */
    public function constrainCurrentStateQuery(Builder $query): Builder
    {
        if ($this->datasetRunId <= 0) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('last_dataset_run_id', $this->datasetRunId);
    }

    /**
     * @return list<int>
     */
    private function completedDatasetRunIds(): array
    {
        return CollectionDatasetRun::query()
            ->where('dataset_contract_id', $this->datasetId)
            ->where('status', CollectionRunStatus::Completed)
            ->whereHas('resourceRun', function (EloquentBuilder $resourceRun): void {
                $resourceRun
                    ->where('digital_asset_id', $this->digitalAssetId)
                    ->where('external_resource_id', $this->externalResourceId);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function successfulCoverageDates(DatasetMaterialization $materialization): array
    {
        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        $dates = [];
        if (isset($meta['successful_coverage_dates']) && is_array($meta['successful_coverage_dates'])) {
            $dates = array_values(array_filter($meta['successful_coverage_dates'], 'is_string'));
        }
        if (isset($meta['zero_row_success_dates']) && is_array($meta['zero_row_success_dates'])) {
            $dates = array_merge($dates, array_values(array_filter($meta['zero_row_success_dates'], 'is_string')));
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    private static function verifiedWatermark(
        DatasetMaterialization $materialization,
        CoverageIntervalSet $set,
    ): ?string {
        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        $stored = $meta['verified_contiguous_watermark'] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $set->verifiedContiguousWatermark();
    }
}
