<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryAutopilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgu otomatik pilotu: one tick (triage → Bekleyenler → daily clustering); while triage work is left the job queues
 * itself again. One tick at a time (lock); the 15-minute schedule restarts it after a failure.
 */
final class QueryAutopilotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public bool $nightly = false)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(QueryAutopilot $autopilot): void
    {
        if ($this->nightly) {
            $autopilot->nightlyClean();

            return;
        }
        $lock = Cache::lock('queries:autopilot:tick', $this->timeout);
        if (! $lock->get()) {
            return;
        }
        try {
            $result = $autopilot->tick();
        } finally {
            $lock->release();
        }
        if ($result === 'more') {
            self::dispatch();
        }
    }
}
