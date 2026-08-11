<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\WebsiteDiagnosisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class WebsiteDiagnosisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(AsyncOperationService $async, WebsiteDiagnosisService $diagnosis): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'diagnosing', 'Running technical checks');
            $asset = DigitalAsset::query()->findOrFail($run->digital_asset_id);
            $child = $diagnosis->diagnose($asset);

            $async->markFinished($run->fresh() ?? $run, $child->status === 'failed' ? 'failed' : 'completed', 'Completed', [
                'result_summary' => 'Website diagnosis finished with status '.$child->status.'.',
                'child_run_ids' => [$child->id],
                'retryable' => $child->status === 'failed',
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
