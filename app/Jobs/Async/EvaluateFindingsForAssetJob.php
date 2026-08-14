<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Services\Findings\FindingEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
    ) {}

    public function handle(FindingEvaluationService $evaluator): void
    {
        $asset = DigitalAsset::query()->find($this->digitalAssetId);
        if ($asset === null) {
            return;
        }

        $evaluator->evaluateAsset($asset, ruleIds: $this->ruleIds, definitionIds: $this->definitionIds);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
