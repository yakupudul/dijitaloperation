<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\GoogleAds\Ai\GoogleAdsAiGuidanceService;
use Throwable;

class GoogleAdsAiGuidanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>|null  $findingIds
     */
    public function __construct(public int $runId, public ?array $findingIds = null) {}

    public function handle(AsyncOperationService $async, GoogleAdsAiGuidanceService $service): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'generating', 'Generating AI guidance');
            $asset = DigitalAsset::query()->findOrFail($run->digital_asset_id);
            $result = $service->analyze($asset, $this->findingIds);

            $child = $result['run'] ?? null;
            $status = ($child instanceof Run && $child->status === 'failed') ? 'failed' : 'completed';

            $async->markFinished($run->fresh() ?? $run, $status, $status === 'completed' ? 'Completed' : 'Failed', [
                'result_summary' => (string) ($result['message'] ?? 'AI guidance finished.'),
                'child_run_ids' => $child instanceof Run ? [$child->id] : [],
                'reused' => (bool) ($result['reused'] ?? false),
                'failure_summary' => $status === 'failed' ? (string) ($result['message'] ?? 'AI guidance failed') : null,
                'retryable' => $status === 'failed',
            ]);
        } catch (Throwable $exception) {
            $async->markFailed($run->fresh() ?? $run, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null || $exception === null) {
            return;
        }
        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }
        app(AsyncOperationService::class)->markFailed($run, $exception);
    }
}
