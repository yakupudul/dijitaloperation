<?php

namespace App\Jobs\Async;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use MoxDop\Website\Discovery\PublicDiscoveryService;
use Throwable;

class PublicDiscoveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(AsyncOperationService $async, PublicDiscoveryService $discovery): void
    {
        $run = Run::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        try {
            $async->markRunning($run, 'discovering', 'Inspecting public website context');
            $asset = DigitalAsset::query()->findOrFail($run->digital_asset_id);
            $result = $discovery->discover($asset);

            $status = match ($result['status'] ?? 'failed') {
                'succeeded', 'completed' => 'completed',
                'partial' => 'partial',
                default => 'failed',
            };

            $childId = data_get($result, 'run_id') ?? data_get($result, 'run.id');

            $async->markFinished($run->fresh() ?? $run, $status, $status === 'completed' ? 'Completed' : ($status === 'partial' ? 'Completed with gaps' : 'Failed'), [
                'result_summary' => (string) ($result['message'] ?? 'Public discovery finished.'),
                'child_run_ids' => is_numeric($childId) ? [(int) $childId] : [],
                'retryable' => $status !== 'completed',
                'failure_summary' => $status === 'failed' ? (string) ($result['message'] ?? 'Discovery failed') : null,
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
