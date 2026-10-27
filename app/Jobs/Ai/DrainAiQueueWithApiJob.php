<?php

namespace App\Jobs\Ai;

use App\Services\AiTasks\ApiQueueWorker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Claude API / ChatGPT API round of the AI queue (Ayarlar › AI): does nothing unless an API is the working worker in
 * the order. One round at a time; scheduled every five minutes on the background lane.
 */
final class DrainAiQueueWithApiJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 960;

    public function handle(ApiQueueWorker $worker): void
    {
        $worker->round();
    }
}
