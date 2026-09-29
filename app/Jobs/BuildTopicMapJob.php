<?php

namespace App\Jobs;

use App\Services\ContentStudio\TopicMapBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Faz 3: rebuilds one website's topic map from the brand query hub (stored data only). Runs on the heavy queue
 * (Horizon supervisor-heavy) so it no longer waits behind hour-long jobs on "default"; a timeout or lost worker
 * closes the build row instead of leaving it "queued" forever.
 */
final class BuildTopicMapJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $buildId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(TopicMapBuilder $builder): void
    {
        $builder->run($this->buildId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            app(TopicMapBuilder::class)->markFailed($this->buildId, $exception);
        }
    }
}
