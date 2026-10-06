<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryRuleEngine;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Kuralları uygula" and library changes outside the pipeline (review approved, pending imported): recomputes the
 * variant / topic keys of every query (QueryRuleEngine). Queued requests collapse into one; never overlaps the pipeline.
 *
 * While ProcessQueriesJob::LOCK is busy (a full pass, a filter scan, another rules run) a request waits by releases
 * bounded by retryUntil(), not attempts: those runs take up to half an hour each. Only one run waits (WAITING holds its
 * job uuid): it starts after the current run and reads the library then, so further copies end at once.
 */
final class ApplyQueryRulesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The rules run waiting for ProcessQueriesJob::LOCK (its job uuid); the run that takes the lock clears it. */
    public const string WAITING = 'queries:rules:waiting';

    /** A failing run (error, timeout) is tried 3 times; lock waits are releases, not exceptions. */
    public int $maxExceptions = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('queue.heavy_queue', 'heavy'));
    }

    public function uniqueId(): string
    {
        return 'all';
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(QueryRuleEngine $engine): void
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            if ($this->waits()) {
                $this->release(60);
            }

            return;
        }
        // This run reads the library as it is now: the next request waits again.
        Cache::forget(self::WAITING);
        try {
            $engine->apply();
        } finally {
            $lock->release();
        }
    }

    /** A waiting run that gives up frees its place, so the next request waits instead of ending. */
    public function failed(?Throwable $exception): void
    {
        if (Cache::get(self::WAITING) === $this->copy()) {
            Cache::forget(self::WAITING);
        }
    }

    /** Whether this run is (or now becomes) the one waiting for the lock. */
    private function waits(): bool
    {
        $copy = $this->copy();

        return Cache::add(self::WAITING, $copy, 3600) || Cache::get(self::WAITING) === $copy;
    }

    /** The queued job's uuid (the same over its releases). */
    private function copy(): string
    {
        return (string) $this->job?->uuid();
    }
}
