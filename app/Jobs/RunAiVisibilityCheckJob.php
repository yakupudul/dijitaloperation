<?php

namespace App\Jobs;

use App\Services\Intel\AiVisibilityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs one AI visibility batch (operator clicked "Kontrol et"). */
final class RunAiVisibilityCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public string $batch) {}

    public function handle(AiVisibilityService $service): void
    {
        $service->runBatch($this->batch);
    }
}
