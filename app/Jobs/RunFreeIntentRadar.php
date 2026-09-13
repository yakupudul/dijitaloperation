<?php

namespace App\Jobs;

use App\Models\SalesIntentRadarRun;
use App\Services\Sales\FreeIntentRadar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunFreeIntentRadar implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId) {}

    public function handle(FreeIntentRadar $radar): void
    {
        $radar->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        SalesIntentRadarRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => json_encode(['message' => 'worker_failed']), 'updated_at' => now()]);
    }
}
