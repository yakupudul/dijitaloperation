<?php

namespace App\Jobs;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Repair\RepairAutopilot;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Otomatik onarım: one nightly pass of the AI check over the next prepared desk rows (RepairAutopilot), on the
 * background queue so operator work never waits behind it. Nothing is written here. The check can wait for Claude
 * (MCP): the job then runs again once Claude answered.
 */
final class ReviewRepairRowsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(RepairAutopilot $autopilot, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self, null, 'Otomatik onarım · AI kontrolü');
        try {
            $autopilot->review();
        } finally {
            $tasks->settle();
        }
    }
}
