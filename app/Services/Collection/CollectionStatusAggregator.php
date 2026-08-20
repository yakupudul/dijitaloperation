<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\RequirementLevel;
use App\Events\Collection\CollectionRunCancelled;
use App\Events\Collection\CollectionRunCompleted;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;

final class CollectionStatusAggregator
{
    public function __construct(
        private readonly CollectionStateMachine $stateMachine,
    ) {}

    public function refreshFromDataset(CollectionDatasetRun $datasetRun): void
    {
        $resource = $datasetRun->resourceRun()->first();
        if ($resource !== null) {
            $this->aggregateResource($resource);
        }

        $run = $datasetRun->collectionRun()->first();
        if ($run !== null) {
            $this->aggregateCollection($run);
        }
    }

    public function aggregateResource(CollectionResourceRun $resource): void
    {
        if ($resource->status->isTerminal() && $resource->status !== CollectionRunStatus::CancellationRequested) {
            // Still refresh counters, but do not re-open terminal resource states.
            $datasets = $resource->datasetRuns()->get();
            $resource->forceFill([
                'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
                'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
                'last_activity_at' => now(),
            ])->save();

            return;
        }

        $datasets = $resource->datasetRuns()->get();
        $completed = $datasets->where('status', CollectionRunStatus::Completed)->count();
        $failedRequired = $datasets->filter(function (CollectionDatasetRun $d): bool {
            return $d->status === CollectionRunStatus::Failed
                && $d->requirement_level === RequirementLevel::Required;
        })->count();
        $failedAny = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $cancelled = $datasets->where('status', CollectionRunStatus::Cancelled)->count();
        $notEligible = $datasets->where('status', CollectionRunStatus::NotEligible)->count();
        $active = $datasets->filter(fn (CollectionDatasetRun $d): bool => ! $d->status->isTerminal())->count();

        $resource->forceFill([
            'datasets_completed' => $completed,
            'datasets_failed' => $failedAny,
            'last_activity_at' => now(),
        ])->save();

        if ($active > 0) {
            if ($resource->status === CollectionRunStatus::Queued) {
                $this->stateMachine->transition($resource, CollectionRunStatus::Running);
            }

            return;
        }

        $relevant = $datasets->filter(fn (CollectionDatasetRun $d): bool => $d->status !== CollectionRunStatus::NotEligible
            && $d->status !== CollectionRunStatus::Skipped);

        if ($cancelled > 0 && $completed === 0 && $failedRequired === 0) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Cancelled);

            return;
        }

        if ($failedRequired > 0 && $completed > 0) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Partial);

            return;
        }

        if ($failedRequired > 0 && $completed === 0) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Failed);

            return;
        }

        if ($cancelled > 0 && $completed > 0) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Partial);

            return;
        }

        if ($relevant->every(fn (CollectionDatasetRun $d): bool => $d->status === CollectionRunStatus::Completed)
            || ($relevant->isEmpty() && $notEligible === $datasets->count())) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Completed);

            return;
        }

        // Optional-only failures: still completed at resource level.
        if ($failedRequired === 0 && $active === 0) {
            $this->transitionResourceTerminal($resource, CollectionRunStatus::Completed);
        }
    }

    public function aggregateCollection(CollectionRun $run): void
    {
        if ($run->status->isTerminal()) {
            $datasets = $run->datasetRuns()->get();
            $run->forceFill([
                'datasets_completed' => $datasets->where('status', CollectionRunStatus::Completed)->count(),
                'datasets_failed' => $datasets->where('status', CollectionRunStatus::Failed)->count(),
                'last_activity_at' => now(),
            ])->save();

            return;
        }

        $resources = $run->resourceRuns()->get();
        $datasets = $run->datasetRuns()->get();

        $completedDatasets = $datasets->where('status', CollectionRunStatus::Completed)->count();
        $failedRequired = $datasets->filter(fn (CollectionDatasetRun $d): bool => $d->status === CollectionRunStatus::Failed
            && $d->requirement_level === RequirementLevel::Required)->count();
        $failedAny = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $cancelled = $datasets->where('status', CollectionRunStatus::Cancelled)->count();
        $active = $datasets->filter(fn (CollectionDatasetRun $d): bool => ! $d->status->isTerminal())->count();
        $resourcesCompleted = $resources->filter(fn (CollectionResourceRun $r): bool => $r->status->isTerminal()
            && $r->status !== CollectionRunStatus::Failed
            && $r->status !== CollectionRunStatus::Cancelled)->count();

        $run->forceFill([
            'datasets_completed' => $completedDatasets,
            'datasets_failed' => $failedAny,
            'resources_completed' => $resourcesCompleted,
            'last_activity_at' => now(),
        ])->save();

        if ($run->status === CollectionRunStatus::CancellationRequested && $active === 0) {
            $final = ($completedDatasets > 0) ? CollectionRunStatus::Partial : CollectionRunStatus::Cancelled;
            $this->stateMachine->transition($run, $final);
            $run->forceFill(['cancelled_at' => now()])->save();
            CollectionRunCancelled::dispatch($run->fresh() ?? $run);

            return;
        }

        if ($active > 0) {
            if (in_array($run->status, [CollectionRunStatus::Queued, CollectionRunStatus::Running], true) === false
                && $run->status !== CollectionRunStatus::CancellationRequested
                && $run->status !== CollectionRunStatus::Retrying) {
                return;
            }
            if ($run->status === CollectionRunStatus::Queued) {
                $this->stateMachine->transition($run, CollectionRunStatus::Running);
            }

            return;
        }

        if ($cancelled > 0 && $completedDatasets === 0 && $failedRequired === 0) {
            $this->transitionCollectionTerminal($run, CollectionRunStatus::Cancelled);
            CollectionRunCancelled::dispatch($run->fresh() ?? $run);

            return;
        }

        if ($failedRequired > 0 && $completedDatasets > 0) {
            $this->transitionCollectionTerminal($run, CollectionRunStatus::Partial);
            CollectionRunCompleted::dispatch($run->fresh() ?? $run);

            return;
        }

        if ($failedRequired > 0 && $completedDatasets === 0) {
            $run->forceFill(['failure_summary' => 'All required datasets failed'])->save();
            $this->transitionCollectionTerminal($run, CollectionRunStatus::Failed);
            CollectionRunCompleted::dispatch($run->fresh() ?? $run);

            return;
        }

        if ($cancelled > 0 && $completedDatasets > 0) {
            $this->transitionCollectionTerminal($run, CollectionRunStatus::Partial);
            CollectionRunCompleted::dispatch($run->fresh() ?? $run);

            return;
        }

        $this->transitionCollectionTerminal($run, CollectionRunStatus::Completed);
        CollectionRunCompleted::dispatch($run->fresh() ?? $run);
    }

    /**
     * Resource/collection may still be Queued when the only child finishes in one tick.
     * State machine forbids Queued → Completed/Partial/Failed — step through Running.
     */
    private function transitionResourceTerminal(CollectionResourceRun $resource, CollectionRunStatus $to): void
    {
        if ($resource->status === CollectionRunStatus::Queued) {
            $this->stateMachine->transition($resource, CollectionRunStatus::Running);
        }

        $this->stateMachine->transition($resource, $to);
    }

    private function transitionCollectionTerminal(CollectionRun $run, CollectionRunStatus $to): void
    {
        if ($run->status === CollectionRunStatus::Queued) {
            $this->stateMachine->transition($run, CollectionRunStatus::Running);
        }

        $this->stateMachine->transition($run, $to);

        if (in_array($to, [CollectionRunStatus::Completed, CollectionRunStatus::Partial], true)) {
            $run->forceFill(['failure_summary' => null])->save();
        }
    }
}
