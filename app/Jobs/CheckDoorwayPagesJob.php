<?php

namespace App\Jobs;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\DoorwayCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kapı sayfaları: one pass of Claude's check over the sites' template pages (DoorwayCheck), on the background queue.
 * Risky pages become noindex rows on Onarım masası; nothing is written to a site. The check can wait for Claude (MCP):
 * the job then runs again once Claude answered.
 */
final class CheckDoorwayPagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(DoorwayCheck $check, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self, null, 'Kapı sayfaları · Claude kontrolü');
        try {
            $check->run();
        } finally {
            $tasks->settle();
        }
    }
}
