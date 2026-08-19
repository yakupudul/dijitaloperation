<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\Support\StartCollectionRequest;
use InvalidArgumentException;

final class ResumeDatasetRunService
{
    public function __construct(
        private readonly StartCollectionService $starter,
        private readonly CollectionStateMachine $stateMachine,
    ) {}

    public function resume(CollectionDatasetRun $datasetRun): CollectionDatasetRun
    {
        if ($datasetRun->status === CollectionRunStatus::Completed) {
            throw new InvalidArgumentException('Completed DatasetRun cannot be resumed; use replay.');
        }

        if ($datasetRun->status === CollectionRunStatus::Cancelled) {
            throw new InvalidArgumentException('Cancelled DatasetRun cannot be auto-resumed; use explicit replay/retry.');
        }

        if (! in_array($datasetRun->status, [
            CollectionRunStatus::Failed,
            CollectionRunStatus::Partial,
            CollectionRunStatus::Retrying,
        ], true)) {
            throw new InvalidArgumentException('DatasetRun is not resumable from status '.$datasetRun->status->value);
        }

        $this->stateMachine->transition($datasetRun, CollectionRunStatus::Queued);
        $datasetRun->forceFill([
            'retry_at' => null,
            'error_category' => null,
            'error_code' => null,
            'error_message' => null,
            'finished_at' => null,
            'last_activity_at' => now(),
        ])->save();

        $resource = $datasetRun->resourceRun;
        if ($resource !== null && in_array($resource->status, [
            CollectionRunStatus::Failed,
            CollectionRunStatus::Partial,
        ], true)) {
            $this->stateMachine->transition($resource, CollectionRunStatus::Queued);
            $resource->forceFill([
                'finished_at' => null,
                'last_activity_at' => now(),
            ])->save();
        }

        $run = $datasetRun->collectionRun;
        if ($run !== null && in_array($run->status, [
            CollectionRunStatus::Failed,
            CollectionRunStatus::Partial,
        ], true)) {
            $this->stateMachine->transition($run, CollectionRunStatus::Queued);
            $run->forceFill([
                'finished_at' => null,
                'last_activity_at' => now(),
            ])->save();
        }

        $this->starter->dispatchDatasetJob($datasetRun->fresh() ?? $datasetRun);

        return $datasetRun->fresh() ?? $datasetRun;
    }

    public function replay(CollectionRun $source, StartCollectionRequest $request): CollectionRun
    {
        // Replay creates a new CollectionRun identity; never mutates completed history.
        return $this->starter->start(new StartCollectionRequest(
            digitalAsset: $request->digitalAsset,
            triggerType: CollectionTriggerType::Replay,
            requestedBy: $request->requestedBy,
            bindingIds: $request->bindingIds,
            requestFamilyIds: $request->requestFamilyIds,
            providerSources: $request->providerSources,
            dateRange: $request->dateRange,
            idempotencyKey: $request->idempotencyKey,
            forceRefresh: $request->forceRefresh,
            context: array_merge($request->context, [
                'replay_of_collection_run_id' => $source->id,
                'replay_of_collection_run_uuid' => $source->uuid,
            ]),
        ));
    }
}
