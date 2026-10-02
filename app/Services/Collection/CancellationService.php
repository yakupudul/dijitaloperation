<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use InvalidArgumentException;

final class CancellationService
{
    public function __construct(
        private readonly CollectionStateMachine $stateMachine,
        private readonly CollectionStatusAggregator $aggregator,
    ) {}

    public function requestCancellation(CollectionRun $run): CollectionRun
    {
        if ($run->status->isTerminal()) {
            throw new InvalidArgumentException('Cannot cancel a terminal CollectionRun.');
        }

        if ($run->status === CollectionRunStatus::Queued) {
            // Never started — cancel immediately without CancellationRequested intermediate.
            $this->stateMachine->transition($run, CollectionRunStatus::Cancelled);
            $run->forceFill([
                'cancel_requested_at' => now(),
                'cancelled_at' => now(),
                'last_activity_at' => now(),
            ])->save();
        } else {
            $run->forceFill([
                'cancel_requested_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            if ($run->status !== CollectionRunStatus::CancellationRequested) {
                $this->stateMachine->transition($run, CollectionRunStatus::CancellationRequested);
            }
        }

        $run->datasetRuns()
            ->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Retrying->value,
            ])
            ->each(function (CollectionDatasetRun $datasetRun): void {
                $this->stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
            });

        foreach ($run->datasetRuns()->get() as $datasetRun) {
            $this->aggregator->refreshFromDataset($datasetRun);
        }

        return $run->fresh(['datasetRuns', 'resourceRuns']) ?? $run;
    }

    /**
     * Stop only one provider resource inside a multi-resource CollectionRun.
     * Queued/retrying datasets are cancelled immediately; a running dataset is
     * marked cancellation_requested and the worker stops it at its next safe boundary.
     */
    public function requestResourceCancellation(CollectionResourceRun $resourceRun): CollectionResourceRun
    {
        $resourceRun->loadMissing(['datasetRuns', 'collectionRun']);

        if ($resourceRun->status->isTerminal()) {
            throw new InvalidArgumentException('Cannot cancel a terminal CollectionResourceRun.');
        }

        $meta = is_array($resourceRun->metadata) ? $resourceRun->metadata : [];
        $meta['cancel_requested_at'] = now()->toIso8601String();
        $resourceRun->forceFill(['metadata' => $meta])->save();

        if ($resourceRun->status !== CollectionRunStatus::CancellationRequested) {
            $this->stateMachine->transition($resourceRun, CollectionRunStatus::CancellationRequested);
        }

        foreach ($resourceRun->datasetRuns as $datasetRun) {
            if ($datasetRun->status->isTerminal()) {
                continue;
            }

            if (in_array($datasetRun->status, [CollectionRunStatus::Queued, CollectionRunStatus::Retrying], true)) {
                $this->stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
            } elseif ($datasetRun->status === CollectionRunStatus::Running) {
                $this->stateMachine->transition($datasetRun, CollectionRunStatus::CancellationRequested);
            }

            $this->aggregator->refreshFromDataset($datasetRun);
        }

        return $resourceRun->fresh(['datasetRuns', 'collectionRun']) ?? $resourceRun;
    }

    public function isCancelRequested(CollectionRun $run): bool
    {
        return $run->cancellationRequested();
    }

    public function isResourceCancelRequested(?CollectionResourceRun $resourceRun): bool
    {
        return $resourceRun instanceof CollectionResourceRun
            && $resourceRun->status === CollectionRunStatus::CancellationRequested;
    }
}
