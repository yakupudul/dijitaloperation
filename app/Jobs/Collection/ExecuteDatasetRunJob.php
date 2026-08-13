<?php

namespace App\Jobs\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Events\Collection\DatasetRunCompleted;
use App\Events\Collection\DatasetRunFailed;
use App\Events\Collection\DatasetRunStarted;
use App\Models\Collection\CollectionDatasetAttempt;
use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\UnimplementedDatasetExecutorException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generic dataset execution unit. Payload is DatasetRun ID only — no credentials.
 */
class ExecuteDatasetRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int $datasetRunId)
    {
        $this->tries = (int) config('moxdop-collection.job_tries', 3);
        $this->timeout = (int) config('moxdop-collection.job_timeout_seconds', 300);
        $this->onConnection((string) config('moxdop-collection.queue_connection', 'redis'));
        $this->onQueue((string) config('moxdop-collection.queue', 'collection'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('collection-dataset-'.$this->datasetRunId))
                ->releaseAfter(30)
                ->expireAfter(900),
        ];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $dataset = CollectionDatasetRun::query()->find($this->datasetRunId);
        if ($dataset === null) {
            return ['collection'];
        }

        return [
            'collection',
            'collection_run:'.$dataset->collection_run_id,
            'dataset_run:'.$dataset->id,
            'provider:'.$dataset->provider_or_source,
            'request_family:'.$dataset->request_family_id,
        ];
    }

    public function handle(
        DatasetExecutorResolver $resolver,
        DataContractRegistryLoader $registry,
        CollectionStateMachine $stateMachine,
        CollectionStatusAggregator $aggregator,
        CollectionErrorRecorder $errors,
        CheckpointManager $checkpoints,
        ProgressReporter $progress,
        RetryPolicy $retryPolicy,
        CancellationService $cancellation,
        StartCollectionService $starter,
    ): void {
        $datasetRun = CollectionDatasetRun::query()->with(['collectionRun', 'resourceRun'])->find($this->datasetRunId);
        if ($datasetRun === null) {
            return;
        }

        $collectionRun = $datasetRun->collectionRun;
        if ($collectionRun === null) {
            return;
        }

        if ($datasetRun->status->isTerminal()) {
            return;
        }

        if ($cancellation->isCancelRequested($collectionRun)) {
            if (! $datasetRun->status->isTerminal()) {
                $stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
                $errors->record($datasetRun, CollectionErrorCategory::Cancelled, 'Cancelled by operator/system');
                $aggregator->refreshFromDataset($datasetRun);
            }

            return;
        }

        if (! $starter->dependenciesSatisfied($datasetRun)) {
            // Dependency not ready — release without burning attempts.
            $this->release(15);

            return;
        }

        $lockToken = (string) Str::uuid();
        $locked = CollectionDatasetRun::query()
            ->whereKey($datasetRun->id)
            ->where(function ($q): void {
                $q->whereNull('dispatch_lock_token')
                    ->orWhere('dispatch_locked_at', '<', now()->subMinutes(15));
            })
            ->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Retrying->value,
                CollectionRunStatus::Running->value,
            ])
            ->update([
                'dispatch_lock_token' => $lockToken,
                'dispatch_locked_at' => now(),
            ]);

        if ($locked !== 1) {
            return;
        }

        $datasetRun->refresh();

        try {
            if ($datasetRun->status === CollectionRunStatus::Queued || $datasetRun->status === CollectionRunStatus::Retrying) {
                $stateMachine->transition($datasetRun, CollectionRunStatus::Running);
            }

            $attemptNumber = (int) $datasetRun->attempt_count + 1;
            $datasetRun->forceFill([
                'attempt_count' => $attemptNumber,
                'last_activity_at' => now(),
            ])->save();

            $attempt = CollectionDatasetAttempt::query()->create([
                'collection_dataset_run_id' => $datasetRun->id,
                'attempt_number' => $attemptNumber,
                'status' => CollectionRunStatus::Running,
                'started_at' => now(),
                'job_uuid' => (string) ($this->job?->uuid() ?? Str::uuid()),
            ]);

            DatasetRunStarted::dispatch($datasetRun);

            Log::info('collection.dataset.started', [
                'collection_run_id' => $collectionRun->id,
                'collection_run_uuid' => $collectionRun->uuid,
                'resource_run_id' => $datasetRun->collection_resource_run_id,
                'dataset_run_id' => $datasetRun->id,
                'provider_or_source' => $datasetRun->provider_or_source,
                'request_family_id' => $datasetRun->request_family_id,
                'attempt' => $attemptNumber,
            ]);

            try {
                $executor = $resolver->resolve($datasetRun);
            } catch (UnimplementedDatasetExecutorException $e) {
                $this->failTerminal($datasetRun, $attempt, $errors, $stateMachine, $aggregator, $e->category, $e->getMessage());

                return;
            } catch (Throwable $e) {
                $this->failTerminal(
                    $datasetRun,
                    $attempt,
                    $errors,
                    $stateMachine,
                    $aggregator,
                    CollectionErrorCategory::UnimplementedCapability,
                    $e->getMessage(),
                );

                return;
            }

            $context = new DatasetExecutionContext(
                collectionRun: $collectionRun,
                resourceRun: $datasetRun->resourceRun,
                datasetRun: $datasetRun,
                checkpoint: $checkpoints->current($datasetRun),
                registryDataset: $registry->dataset($datasetRun->dataset_contract_id) ?? [],
                registryRequestFamily: $registry->requestFamily($datasetRun->request_family_id) ?? [],
                attemptNumber: $attemptNumber,
            );

            $result = $executor->execute($context);

            if ($cancellation->isCancelRequested($collectionRun->fresh() ?? $collectionRun)) {
                if ($result->checkpoint !== null) {
                    $checkpoints->advance($datasetRun, $result->checkpoint);
                }
                $stateMachine->transition($datasetRun, CollectionRunStatus::Cancelled);
                $attempt->forceFill([
                    'status' => CollectionRunStatus::Cancelled,
                    'finished_at' => now(),
                    'error_category' => CollectionErrorCategory::Cancelled,
                    'error_message' => 'Cancelled at safe boundary',
                ])->save();
                $aggregator->refreshFromDataset($datasetRun);

                return;
            }

            match ($result->outcome) {
                DatasetExecutionOutcome::Completed => $this->complete($datasetRun, $attempt, $result, $checkpoints, $progress, $stateMachine, $aggregator),
                DatasetExecutionOutcome::Continue => $this->continueChunk($datasetRun, $attempt, $result, $checkpoints, $progress, $stateMachine, $starter),
                DatasetExecutionOutcome::Retry => $this->scheduleRetry($datasetRun, $attempt, $result, $retryPolicy, $errors, $stateMachine, $aggregator, $starter),
                DatasetExecutionOutcome::Failed => $this->failTerminal($datasetRun, $attempt, $errors, $stateMachine, $aggregator, $result->errorCategory ?? CollectionErrorCategory::Unknown, $result->errorMessage ?? 'Dataset failed', $result->errorCode),
                DatasetExecutionOutcome::Cancelled => $this->failTerminal($datasetRun, $attempt, $errors, $stateMachine, $aggregator, CollectionErrorCategory::Cancelled, $result->errorMessage ?? 'Cancelled'),
            };
        } finally {
            CollectionDatasetRun::query()
                ->whereKey($datasetRun->id)
                ->where('dispatch_lock_token', $lockToken)
                ->update([
                    'dispatch_lock_token' => null,
                    'dispatch_locked_at' => null,
                ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $datasetRun = CollectionDatasetRun::query()->find($this->datasetRunId);
        if ($datasetRun === null || $datasetRun->status->isTerminal()) {
            return;
        }

        $errors = app(CollectionErrorRecorder::class);
        $stateMachine = app(CollectionStateMachine::class);
        $aggregator = app(CollectionStatusAggregator::class);

        $errors->record(
            $datasetRun,
            CollectionErrorCategory::Unknown,
            $exception?->getMessage() ?? 'Queue job failed',
        );
        $stateMachine->transition($datasetRun, CollectionRunStatus::Failed);
        DatasetRunFailed::dispatch($datasetRun);
        $aggregator->refreshFromDataset($datasetRun);
    }

    private function complete(
        CollectionDatasetRun $datasetRun,
        CollectionDatasetAttempt $attempt,
        $result,
        CheckpointManager $checkpoints,
        ProgressReporter $progress,
        CollectionStateMachine $stateMachine,
        CollectionStatusAggregator $aggregator,
    ): void {
        if ($result->checkpoint !== null) {
            $checkpoints->advance($datasetRun, $result->checkpoint);
        }

        if ($result->progressMode !== null) {
            $progress->report(
                $datasetRun,
                $result->progressMode,
                $result->progressCurrent,
                $result->progressTotal,
                $result->stage,
                $result->rowsReceived,
                $result->rowsWritten,
                $result->chunksCompleted,
                $result->pagesCompleted,
            );
        } else {
            $datasetRun->forceFill([
                'rows_received' => (int) $datasetRun->rows_received + $result->rowsReceived,
                'rows_written' => (int) $datasetRun->rows_written + $result->rowsWritten,
                'last_activity_at' => now(),
            ])->save();
        }

        $stateMachine->transition($datasetRun, CollectionRunStatus::Completed);
        $attempt->forceFill([
            'status' => CollectionRunStatus::Completed,
            'finished_at' => now(),
        ])->save();

        DatasetRunCompleted::dispatch($datasetRun);
        $aggregator->refreshFromDataset($datasetRun);

        app(StartCollectionService::class)->dispatchEligibleRootJobs($datasetRun->collectionRun);
    }

    private function continueChunk(
        CollectionDatasetRun $datasetRun,
        CollectionDatasetAttempt $attempt,
        $result,
        CheckpointManager $checkpoints,
        ProgressReporter $progress,
        CollectionStateMachine $stateMachine,
        StartCollectionService $starter,
    ): void {
        if ($result->checkpoint !== null) {
            $checkpoints->advance($datasetRun, $result->checkpoint);
        }

        if ($result->progressMode !== null) {
            $progress->report(
                $datasetRun,
                $result->progressMode,
                $result->progressCurrent,
                $result->progressTotal,
                $result->stage,
                $result->rowsReceived,
                $result->rowsWritten,
                $result->chunksCompleted,
                $result->pagesCompleted,
            );
        }

        $attempt->forceFill([
            'status' => CollectionRunStatus::Completed,
            'finished_at' => now(),
            'metadata' => ['continuation' => true],
        ])->save();

        // Keep DatasetRun running/queued for next chunk — do not mark complete.
        if ($datasetRun->status !== CollectionRunStatus::Running) {
            $stateMachine->transition($datasetRun, CollectionRunStatus::Running);
        }

        $datasetRun->forceFill([
            'status' => CollectionRunStatus::Queued,
            'finished_at' => null,
            'last_activity_at' => now(),
        ])->save();

        $fresh = $datasetRun->fresh() ?? $datasetRun;
        $delaySeconds = max(0, (int) $result->backoffSeconds);
        if ($delaySeconds > 0) {
            // Delayed Continue (e.g. Meta async Insights poll) — no blocking sleep in the worker.
            ExecuteDatasetRunJob::dispatch($fresh->id)
                ->delay(now()->addSeconds($delaySeconds))
                ->onConnection((string) config('moxdop-collection.queue_connection', 'redis'))
                ->onQueue((string) config('moxdop-collection.queue', 'collection'))
                ->afterCommit();

            return;
        }

        $starter->dispatchDatasetJob($fresh);
    }

    private function scheduleRetry(
        CollectionDatasetRun $datasetRun,
        CollectionDatasetAttempt $attempt,
        $result,
        RetryPolicy $retryPolicy,
        CollectionErrorRecorder $errors,
        CollectionStateMachine $stateMachine,
        CollectionStatusAggregator $aggregator,
        StartCollectionService $starter,
    ): void {
        $category = $result->errorCategory ?? CollectionErrorCategory::Unknown;
        $attemptNumber = (int) $datasetRun->attempt_count;

        if (! $retryPolicy->shouldRetry($datasetRun, $category, $attemptNumber)) {
            $this->failTerminal($datasetRun, $attempt, $errors, $stateMachine, $aggregator, $category, $result->errorMessage ?? 'Retry exhausted', $result->errorCode);

            return;
        }

        $backoff = $result->backoffSeconds > 0
            ? $result->backoffSeconds
            : $retryPolicy->backoffSeconds($datasetRun, $attemptNumber);

        $errors->record($datasetRun, $category, $result->errorMessage, $result->errorCode);
        $stateMachine->transition($datasetRun, CollectionRunStatus::Retrying);
        $datasetRun->forceFill([
            'retry_at' => now()->addSeconds($backoff),
            'finished_at' => null,
        ])->save();

        $attempt->forceFill([
            'status' => CollectionRunStatus::Retrying,
            'finished_at' => now(),
            'error_category' => $category,
            'error_code' => $result->errorCode,
            'error_message' => $errors->sanitizeMessage($result->errorMessage),
            'retry_scheduled_at' => now()->addSeconds($backoff),
        ])->save();

        ExecuteDatasetRunJob::dispatch($datasetRun->id)
            ->delay(now()->addSeconds($backoff))
            ->onConnection((string) config('moxdop-collection.queue_connection', 'redis'))
            ->onQueue((string) config('moxdop-collection.queue', 'collection'))
            ->afterCommit();
    }

    private function failTerminal(
        CollectionDatasetRun $datasetRun,
        CollectionDatasetAttempt $attempt,
        CollectionErrorRecorder $errors,
        CollectionStateMachine $stateMachine,
        CollectionStatusAggregator $aggregator,
        CollectionErrorCategory $category,
        string $message,
        ?string $code = null,
    ): void {
        $errors->record($datasetRun, $category, $message, $code);
        $stateMachine->transition($datasetRun, CollectionRunStatus::Failed);
        $attempt->forceFill([
            'status' => CollectionRunStatus::Failed,
            'finished_at' => now(),
            'error_category' => $category,
            'error_code' => $code,
            'error_message' => $errors->sanitizeMessage($message),
        ])->save();

        Log::warning('collection.dataset.failed', [
            'collection_run_id' => $datasetRun->collection_run_id,
            'dataset_run_id' => $datasetRun->id,
            'provider_or_source' => $datasetRun->provider_or_source,
            'request_family_id' => $datasetRun->request_family_id,
            'error_category' => $category->value,
            'attempt' => $datasetRun->attempt_count,
        ]);

        DatasetRunFailed::dispatch($datasetRun);
        $aggregator->refreshFromDataset($datasetRun);
        app(StartCollectionService::class)->dispatchEligibleRootJobs($datasetRun->collectionRun);
    }
}
