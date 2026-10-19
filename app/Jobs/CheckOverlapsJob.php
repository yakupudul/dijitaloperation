<?php

namespace App\Jobs;

use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\OverlapCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Yanlış çakışmaları ayıklama: one nightly pass of Claude's check over the open overlaps (OverlapCheck), on the
 * background queue. Nothing is written to a site. The check can wait for Claude (MCP): the job then runs again once
 * Claude answered.
 */
final class CheckOverlapsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(OverlapCheck $check, AiTaskQueue $tasks): void
    {
        $tasks->begin(new self, null, 'Aynı konuyu anlatan sayfalar · Claude kontrolü');
        try {
            $check->run();
        } finally {
            $tasks->settle();
        }
    }
}
