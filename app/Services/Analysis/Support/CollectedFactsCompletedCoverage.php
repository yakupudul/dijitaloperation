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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $completedIds = self::completedDatasetRunIdsFor(
            $datasetId,
            $digitalAssetId,
            $externalResourceId,
        );
        if ($completedIds === []) {
            return null;
        }

        $coverageDates = self::successfulCoverageDates($materialization, $completedIds);
        if ($coverageDates === []) {
            return null;
        }

        $set = CoverageIntervalSet::fromSuccessfulDates($coverageDates);
        $watermark = $set->verifiedContiguousWatermark();
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
    public function completedDatasetRunIds(): array
    {
        return self::completedDatasetRunIdsFor(
            $this->datasetId,
            $this->digitalAssetId,
            $this->externalResourceId,
        );
    }

    /**
     * @return list<int>
     */
    public static function completedDatasetRunIdsFor(
        string $datasetId,
        int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        return CollectionDatasetRun::query()
            ->where('dataset_contract_id', $datasetId)
            ->where('status', CollectionRunStatus::Completed)
            ->whereHas('resourceRun', function (EloquentBuilder $resourceRun) use ($digitalAssetId, $externalResourceId): void {
                $resourceRun->where('digital_asset_id', $digitalAssetId);
                if ($externalResourceId === null) {
                    $resourceRun->whereNull('external_resource_id');
                } else {
                    $resourceRun->where('external_resource_id', $externalResourceId);
                }
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Coverage dates attributed to completed DatasetRuns only. Unattributed merged
     * materialization dates (including failed-run slices) never prove the window.
     *
     * @param  list<int>  $completedIds
     * @return list<string>
     */
    private static function successfulCoverageDates(DatasetMaterialization $materialization, array $completedIds): array
    {
        $meta = is_array($materialization->freshness_metadata) ? $materialization->freshness_metadata : [];
        $dates = [];
        $byRun = is_array($meta['coverage_dates_by_dataset_run'] ?? null)
            ? $meta['coverage_dates_by_dataset_run']
            : [];
        foreach ($completedIds as $id) {
            $runDates = $byRun[(string) $id] ?? $byRun[$id] ?? null;
            if (! is_array($runDates)) {
                continue;
            }
            $dates = array_merge($dates, array_values(array_filter($runDates, 'is_string')));
        }

        $dates = array_merge($dates, self::completedWarehouseReportingDates($materialization, $completedIds));
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    /**
     * @param  list<int>  $completedIds
     * @return list<string>
     */
    private static function completedWarehouseReportingDates(
        DatasetMaterialization $materialization,
        array $completedIds,
    ): array {
        if ($completedIds === []) {
            return [];
        }

        $table = $materialization->dataset_id;
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reporting_date')) {
            return [];
        }

        $query = DB::table($table)
            ->where('digital_asset_id', $materialization->digital_asset_id)
            ->whereIn('last_dataset_run_id', $completedIds);
        if ($materialization->external_resource_id === null) {
            $query->whereNull('external_resource_id');
        } else {
            $query->where('external_resource_id', $materialization->external_resource_id);
        }

        return $query
            ->distinct()
            ->pluck('reporting_date')
            ->map(function ($date): ?string {
                if (! is_string($date) && ! $date instanceof \DateTimeInterface) {
                    return null;
                }

                return CarbonImmutable::parse($date)->toDateString();
            })
            ->filter(fn (?string $date): bool => $date !== null)
            ->values()
            ->all();
    }
}
