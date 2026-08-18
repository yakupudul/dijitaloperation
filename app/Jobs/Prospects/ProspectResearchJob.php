<?php

namespace App\Jobs\Prospects;

use App\Models\ProspectResearchRun;
use App\Services\Prospects\ProspectResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProspectResearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $runId) {}

    public function handle(ProspectResearchService $research): void
    {
        $run = ProspectResearchRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $research->execute($run);
    }

    public function failed(?Throwable $exception): void
    {
        // Job failure is handled inside ProspectResearchService::execute exception path.
    }
}
