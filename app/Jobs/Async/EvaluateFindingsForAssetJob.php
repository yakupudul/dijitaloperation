<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Analysis\CollectedFactsAnalysisService;
use App\Services\Async\AsyncOperationService;
use App\Services\Findings\FindingEvaluationService;
use App\Support\ServiceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Background Finding evaluation. Failure must not invalidate canonical Evidence.
 */
class EvaluateFindingsForAssetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * @param  list<string>|null  $ruleIds
     * @param  list<string>|null  $definitionIds
     */
    public function __construct(
        public int $digitalAssetId,
        public ?array $ruleIds = null,
        public ?array $definitionIds = null,
        public ?int $runId = null,
    ) {}

    public function handle(
        FindingEvaluationService $evaluator,
        CollectedFactsAnalysisService $collectedFacts,
    ): void {
        $async = $this->runId !== null ? app(AsyncOperationService::class) : null;
        $run = $this->runId !== null ? Run::query()->find($this->runId) : null;
        if (! app(ServiceScope::class)->isAssetOperational($this->digitalAssetId)) {
            $async?->skippedOutsideServiceScope($run);

            return;
        }
        if ($run !== null && $async !== null) {
            $async->markRunning($run, 'evaluating', 'Evaluating findings');
        }

        $asset = DigitalAsset::query()->find($this->digitalAssetId);
        if ($asset === null) {
            if ($run !== null && $async !== null) {
                $async->markFinished($run, 'failed', 'Asset missing');
            }

            return;
        }

        try {
            $evaluator->evaluateAsset($asset, ruleIds: $this->ruleIds, definitionIds: $this->definitionIds);
            $collectedFacts->analyze($asset);

            if ((bool) config('moxdop-opportunity-rules.evaluate_after_findings', true)) {
                EvaluateOpportunitiesForAssetJob::dispatch($this->digitalAssetId);
            }

            if ($run !== null && $async !== null) {
                $async->markFinished($run, 'completed', 'Completed');
            }
        } catch (Throwable $exception) {
            if ($run !== null && $async !== null) {
                $async->markFailed($run, $exception);
            }

            throw $exception;
        }
    }

    /**
     * The worker reports every exception an attempt throws (Worker::runJob), the last one included, so a final error
     * is not reported again here. A timeout is the exception: the worker fails the job and kills itself without a
     * report (Worker::registerTimeoutHandler), so only this report brings it to the error groups. A timeout or
     * MaxAttemptsExceeded never reaches the catch in handle(): the operator's run still open is closed as failed.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            report($exception);
        }
        $run = $this->runId !== null ? Run::query()->find($this->runId) : null;
        if ($run !== null && ! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            app(AsyncOperationService::class)->markFailed($run, $exception);
        }
    }
}
