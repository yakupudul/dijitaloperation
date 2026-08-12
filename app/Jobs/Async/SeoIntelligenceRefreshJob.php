<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceRefreshService;
use Throwable;

class SeoIntelligenceRefreshJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(AsyncOperationService $async): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'refreshing', 'Refreshing SEO intelligence');
            $asset = DigitalAsset::query()->findOrFail($run->digital_asset_id);
            $service = app(SeoIntelligenceRefreshService::class);
            $result = $service->refresh($asset);

            if (($result['blocked_reason'] ?? null) !== null) {
                $async->markFinished($run->fresh() ?? $run, 'failed', 'Failed', [
                    'result_summary' => (string) $result['message'],
                    'failure_category' => 'validation',
                    'failure_summary' => (string) $result['message'],
                    'retryable' => false,
                ]);

                return;
            }

            $ok = (bool) ($result['ok'] ?? false);
            $async->markFinished($run->fresh() ?? $run, $ok ? 'completed' : 'partial', $ok ? 'Completed' : 'Completed with gaps', [
                'result_summary' => (string) ($result['message'] ?? 'SEO intelligence refresh finished.'),
                'retryable' => ! $ok,
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
