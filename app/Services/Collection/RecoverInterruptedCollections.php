<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetAttempt;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Recovers expired execution leases; never restarts completed datasets or clears checkpoints. */
final class RecoverInterruptedCollections
{
    public function tick(): void
    {
        $lock = Cache::lock('collection-interruption-recovery', 55);
        if (! $lock->get()) {
            return;
        }
        try {
            $cutoff = now()->subSeconds(max(1800, (int) config('moxdop-collection.stale_running_seconds', 1800),
                (int) config('moxdop-collection.job_timeout_seconds', 300) + 900));
            $candidates = CollectionDatasetRun::query()->where('status', 'running')
                ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', ['queued', 'running', 'retrying']))
                ->whereRaw('COALESCE(last_activity_at, started_at, created_at) < ?', [$cutoff])
                ->where(fn ($q) => $q->whereNull('dispatch_locked_at')->orWhere('dispatch_locked_at', '<', $cutoff))
                ->orderBy('last_activity_at')->limit(50)->pluck('id');
            foreach ($candidates as $id) {
                DB::transaction(function () use ($id, $cutoff): void {
                    $dataset = CollectionDatasetRun::query()->lockForUpdate()->find($id);
                    if (! $dataset || $dataset->status !== CollectionRunStatus::Running
                        || ! ($dataset->last_activity_at ?? $dataset->started_at ?? $dataset->created_at)?->lt($cutoff)
                        || ($dataset->dispatch_locked_at && ! $dataset->dispatch_locked_at->lt($cutoff))) {
                        return;
                    }
                    $run = $dataset->collectionRun()->lockForUpdate()->first();
                    if (! $run || ! in_array($run->status, [CollectionRunStatus::Queued, CollectionRunStatus::Running, CollectionRunStatus::Retrying], true)) {
                        return;
                    }
                    $metadata = $dataset->metadata ?? [];
                    $fingerprint = hash('sha256', json_encode($dataset->checkpoint ?? [], JSON_THROW_ON_ERROR));
                    $previous = $metadata['interruption_recovery'] ?? [];
                    $attempts = ($previous['checkpoint_hash'] ?? null) === $fingerprint ? (int) ($previous['attempts'] ?? 0) + 1 : 1;
                    $exhausted = $attempts > 3;
                    $metadata['interruption_recovery'] = ['checkpoint_hash' => $fingerprint, 'attempts' => $attempts, 'at' => now()->toIso8601String()];
                    $dataset->forceFill([
                        'metadata' => $metadata, 'dispatch_lock_token' => null, 'dispatch_locked_at' => null,
                        'retry_at' => $exhausted ? null : now(),
                        'error_code' => 'INTERRUPTED_WORKER',
                        'error_message' => $exhausted ? 'Worker repeatedly stopped at the same checkpoint; inspect worker logs.' : 'Expired worker lease; resuming saved checkpoint.',
                    ])->save();
                    CollectionDatasetAttempt::query()->where('collection_dataset_run_id', $dataset->id)
                        ->where('status', 'running')->update([
                            'status' => 'failed', 'finished_at' => now(), 'error_code' => 'INTERRUPTED_WORKER',
                            'error_message' => 'Execution lease expired without a completed attempt.',
                        ]);
                    app(CollectionStateMachine::class)->transition($dataset, $exhausted ? CollectionRunStatus::Failed : CollectionRunStatus::Retrying);
                    app(CollectionStatusAggregator::class)->refreshFromDataset($dataset);
                    if (! $exhausted) {
                        DB::afterCommit(fn () => app(StartCollectionService::class)->dispatchDatasetJob($dataset->fresh()));
                    }
                });
            }
            CollectionDatasetRun::query()->where('status', 'queued')
                ->whereHas('collectionRun', fn ($q) => $q->whereIn('status', ['queued', 'running', 'retrying']))
                ->whereJsonLength('depends_on_dataset_run_ids', '>', 0)->orderBy('id')->limit(100)->get()
                ->each(function ($candidate): void {
                    DB::transaction(function () use ($candidate): void {
                        $dataset = CollectionDatasetRun::query()->lockForUpdate()->find($candidate->id);
                        if (! $dataset || $dataset->status !== CollectionRunStatus::Queued) {
                            return;
                        }
                        if (! $dataset->collectionRun || $dataset->collectionRun->status->isTerminal()
                            || $dataset->collectionRun->status === CollectionRunStatus::CancellationRequested) {
                            return;
                        }
                        $parents = CollectionDatasetRun::query()->whereIn('id', $dataset->depends_on_dataset_run_ids ?? [])->get();
                        if (! $parents->contains(fn ($parent) => $parent->status->isTerminal() && $parent->status !== CollectionRunStatus::Completed)) {
                            return;
                        }
                        $dataset->forceFill(['error_code' => 'DEPENDENCY_FAILED',
                            'error_message' => 'A required dataset did not complete; dependent work cannot run.'])->save();
                        app(CollectionStateMachine::class)->transition($dataset, CollectionRunStatus::Failed);
                        app(CollectionStatusAggregator::class)->refreshFromDataset($dataset);
                    });
                });
            // A worker may have saved the final dataset but died before aggregating its parent.
            CollectionRun::query()->whereIn('status', ['queued', 'running', 'retrying', 'cancellation_requested'])
                ->whereHas('datasetRuns')->whereDoesntHave('datasetRuns', fn ($q) => $q->whereIn('status', ['queued', 'running', 'retrying', 'cancellation_requested']))
                ->orderBy('id')->limit(50)->get()->each(function ($run): void {
                    $aggregator = app(CollectionStatusAggregator::class);
                    foreach ($run->resourceRuns as $resource) {
                        $aggregator->aggregateResource($resource);
                    }
                    $aggregator->aggregateCollection($run);
                });
        } finally {
            $lock->release();
        }
    }
}
