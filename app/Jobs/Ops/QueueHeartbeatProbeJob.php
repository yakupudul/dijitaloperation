<?php

namespace App\Jobs\Ops;

use App\Services\Observability\WorkerHeartbeatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tiny job dispatched to each queue by the scheduler; when a worker processes it, the queue's heartbeat is
 * written. A stale "queue:<name>" heartbeat means nothing is consuming that queue.
 */
final class QueueHeartbeatProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $queueName) {}

    public function handle(WorkerHeartbeatService $heartbeats): void
    {
        $heartbeats->beat('queue:'.$this->queueName, 'queue:'.$this->queueName, strtoupper($this->queueName), ['dispatched_probe' => true]);
    }
}
