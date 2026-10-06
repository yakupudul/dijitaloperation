<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Support\ServiceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Background Opportunity evaluation. Failure must not invalidate canonical Findings or Evidence.
 */
class EvaluateOpportunitiesForAssetJob implements ShouldQueue
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
    ) {}

    public function handle(OpportunityEvaluationService $evaluator): void
    {
        $asset = DigitalAsset::query()->find($this->digitalAssetId);
        if ($asset === null || ! app(ServiceScope::class)->isAssetOperational($asset->id)) {
            return;
        }

        $evaluator->evaluateAsset($asset, ruleIds: $this->ruleIds, definitionIds: $this->definitionIds);
    }

    /**
     * The worker reports every exception an attempt throws (Worker::runJob), the last one included, so a final error
     * is not reported again here. A timeout is the exception: the worker fails the job and kills itself without a
     * report (Worker::registerTimeoutHandler), so only this report brings it to the error groups.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof TimeoutExceededException) {
            report($exception);
        }
    }
}
