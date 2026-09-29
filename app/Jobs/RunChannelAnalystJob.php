<?php

namespace App\Jobs;

use App\Services\Analyst\AnalystEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Brand workspace: one AI analysis of one brand × channel (heavy queue with Horizon). */
final class RunChannelAnalystJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(AnalystEngine $engine): void
    {
        $engine->run($this->runId);
    }
}
