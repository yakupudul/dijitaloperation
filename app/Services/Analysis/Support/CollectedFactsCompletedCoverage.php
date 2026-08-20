<?php

namespace App\Services\Analysis\Support;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DataPool\DatasetMaterialization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Usable collected-facts window: last successful completed DatasetRun + materialization coverage.
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
            || in_array($materialization->status, [MaterializationStatus::NotCollected, MaterializationStatus::Unavailable], true)
        ) {
            return null;
        }

        $datasetRun = CollectionDatasetRun::query()->find($materialization->last_successful_dataset_run_id);
        if (! $datasetRun instanceof CollectionDatasetRun || $datasetRun->status !== CollectionRunStatus::Completed) {
            return null;
        }

        $periodEnd = $materialization->coverage_end_date->toDateString();
        $periodStart = CarbonImmutable::parse($periodEnd)->subDays($periodDays - 1)->toDateString();

        return new self(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            datasetRunId: (int) $datasetRun->id,
            collectionRunId: $materialization->last_successful_collection_run_id !== null
                ? (int) $materialization->last_successful_collection_run_id
                : null,
            materializationId: (int) $materialization->id,
        );
    }

    /**
     * Restrict warehouse facts to the completed coverage window and the sealed DatasetRun.
     * Rows last written by a later running/failed DatasetRun cannot enter synthesis.
     */
    public function constrainFactsQuery(Builder $query): Builder
    {
        return $query
            ->whereBetween('reporting_date', [$this->periodStart, $this->periodEnd])
            ->where('last_dataset_run_id', $this->datasetRunId);
    }
}
