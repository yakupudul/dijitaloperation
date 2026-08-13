<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
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
                if ($datasetRun->status === CollectionRunStatus::Queued) {
                    $this->stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
                } elseif ($datasetRun->status === CollectionRunStatus::Retrying) {
                    $this->stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
                }
            });

        foreach ($run->datasetRuns as $datasetRun) {
            $this->aggregator->refreshFromDataset($datasetRun);
        }

        return $run->fresh(['datasetRuns', 'resourceRuns']) ?? $run;
    }

    public function isCancelRequested(CollectionRun $run): bool
    {
        return $run->cancellationRequested();
    }
}
