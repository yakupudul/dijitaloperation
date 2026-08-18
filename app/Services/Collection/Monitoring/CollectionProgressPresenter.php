<?php

namespace App\Services\Collection\Monitoring;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;

/**
 * Read-only progress presentation. Does not mutate run state.
 * Distinguishes DATASET_PLAN_COMPLETION from data-transfer progress.
 */
final class CollectionProgressPresenter
{
    /**
     * Dataset-plan completion for a resource: completed / executable planned datasets.
     * Denominator excludes NOT_ELIGIBLE and SKIPPED.
     *
     * @return array{type: string, completed: int, total: int, percentage: ?float, label: string}
     */
    public function resourcePlanCompletion(CollectionResourceRun $resource): array
    {
        $datasets = $resource->relationLoaded('datasetRuns')
            ? $resource->datasetRuns
            : $resource->datasetRuns()->get();

        $executable = $datasets->filter(fn (CollectionDatasetRun $d): bool => ! in_array($d->status, [
            CollectionRunStatus::NotEligible,
            CollectionRunStatus::Skipped,
        ], true));

        $total = $executable->count();
        $completed = $executable->where('status', CollectionRunStatus::Completed)->count();
        $percentage = $total > 0 ? round(($completed / $total) * 100, 1) : null;

        return [
            'type' => 'DATASET_PLAN_COMPLETION',
            'completed' => $completed,
            'total' => $total,
            'percentage' => $percentage,
            'label' => __('operator.collection.progress.dataset_plan_completion'),
        ];
    }

    /**
     * @return array{type: string, completed: int, total: int, percentage: ?float, label: string, success_only: bool}
     */
    public function runPlanCompletion(CollectionRun $run): array
    {
        $datasets = $run->relationLoaded('datasetRuns')
            ? $run->datasetRuns
            : $run->datasetRuns()->get();

        $executable = $datasets->filter(fn (CollectionDatasetRun $d): bool => ! in_array($d->status, [
            CollectionRunStatus::NotEligible,
            CollectionRunStatus::Skipped,
        ], true));

        $total = $executable->count();
        $completed = $executable->where('status', CollectionRunStatus::Completed)->count();

        // Terminal partial/failed/cancelled must never present as green 100% success.
        $percentage = null;
        if ($total > 0) {
            $percentage = round(($completed / $total) * 100, 1);
            if ($run->status === CollectionRunStatus::Partial
                || $run->status === CollectionRunStatus::Failed
                || $run->status === CollectionRunStatus::Cancelled) {
                // Keep rational completion but caller must qualify outcome — not "success 100%".
            }
            if ($run->status === CollectionRunStatus::Completed && $completed === $total) {
                $percentage = 100.0;
            }
        }

        return [
            'type' => 'DATASET_PLAN_COMPLETION',
            'completed' => $completed,
            'total' => $total,
            'percentage' => $percentage,
            'label' => __('operator.collection.progress.dataset_plan_completion'),
            'success_only' => $run->status === CollectionRunStatus::Completed && $completed === $total && $total > 0,
        ];
    }

    /**
     * Connector-level dataset-plan completion (aggregate ResourceRuns by provider_or_source).
     * Denominator is planned executable DatasetRuns — not provider row transfer.
     *
     * @param  iterable<CollectionResourceRun>  $resources
     * @return array{type: string, provider_or_source: string, resources: int, completed: int, total: int, percentage: ?float, retrying: int, label: string}
     */
    public function connectorPlanCompletion(string $providerOrSource, iterable $resources): array
    {
        $matched = collect($resources)->filter(
            fn (CollectionResourceRun $r): bool => $r->provider_or_source === $providerOrSource
        );

        $completed = 0;
        $total = 0;
        $retrying = 0;

        foreach ($matched as $resource) {
            if (! $resource->relationLoaded('datasetRuns')) {
                $resource->load('datasetRuns');
            }
            $plan = $this->resourcePlanCompletion($resource);
            $completed += $plan['completed'];
            $total += $plan['total'];
            $retrying += $resource->datasetRuns->where('status', CollectionRunStatus::Retrying)->count();
        }

        return [
            'type' => 'DATASET_PLAN_COMPLETION',
            'provider_or_source' => $providerOrSource,
            'resources' => $matched->count(),
            'completed' => $completed,
            'total' => $total,
            'percentage' => $total > 0 ? round(($completed / $total) * 100, 1) : null,
            'retrying' => $retrying,
            'label' => __('operator.collection.progress.dataset_plan_completion'),
        ];
    }

    /**
     * Per-dataset transfer/work progress from persisted ProgressMode.
     *
     * @return array<string, mixed>
     */
    public function datasetTransferProgress(CollectionDatasetRun $dataset): array
    {
        $mode = $dataset->progress_mode;
        $percentage = $dataset->percentage();

        return [
            'type' => match ($mode) {
                ProgressMode::Counted => 'COUNTED',
                ProgressMode::PageBased => 'PAGE_BASED',
                ProgressMode::ChunkBased => 'CHUNK_BASED',
                ProgressMode::StageBased => 'STAGE_BASED',
                ProgressMode::Indeterminate => 'INDETERMINATE',
            },
            'mode' => $mode->value,
            'current' => $dataset->progress_current,
            'total' => $dataset->progress_total,
            'percentage' => $percentage,
            'stage' => $dataset->stage,
            'rows_received' => (int) $dataset->rows_received,
            'rows_written' => (int) $dataset->rows_written,
            'chunks_completed' => (int) $dataset->chunks_completed,
            'pages_completed' => (int) $dataset->pages_completed,
            'allows_percentage' => $mode->allowsPercentage() && $dataset->progress_total !== null && $dataset->progress_total > 0,
        ];
    }
}
