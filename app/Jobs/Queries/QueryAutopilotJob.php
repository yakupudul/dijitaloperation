<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryAutopilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgu otomatik pilotu: one tick (triage → Bekleyenler → daily clustering); while triage work is left the job queues
 * itself again. One tick and one clean-up at a time (locks); the 15-minute schedule restarts it after a failure. The
 * clean-up runs hourly and at once after 50 new filter terms (QueryAutopilot::cleanIfDue).
 */
final class QueryAutopilotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** A plain property with a default: jobs queued before it existed unserialize without it. */
    public bool $clean = false;

    public function __construct(bool $clean = false)
    {
        $this->clean = $clean;
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(QueryAutopilot $autopilot): void
    {
        if ($this->clean) {
            $lock = Cache::lock('queries:autopilot:clean', $this->timeout);
            if ($lock->get()) {
                try {
                    $autopilot->clean();
                } finally {
                    $lock->release();
                }
            }

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
