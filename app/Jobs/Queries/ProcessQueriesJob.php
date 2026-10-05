<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sorgular pipeline (query_sources → queries → brand_queries): link sources, totals, Bekleyenler. `import` = the
 * approved first import ("AI ile planla" step 3), which notifies the operator when done. Idempotent full pass on the
 * heavy queue; queued requests collapse into one, runs never overlap.
 *
 * While the lock is busy (another pass, a filter rescan, rules) a request waits by releases bounded by retryUntil(),
 * not attempts: those runs take up to half an hour each. Only one full pass waits (WAITING holds its job uuid): it
 * starts after the current run and sees every request made meanwhile, so further copies end at once. The first import
 * always waits (it notifies the operator).
 */
final class ProcessQueriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string LOCK = 'queries:process';

    /** The full pass waiting for LOCK (its job uuid); the pass that takes the lock clears it. */
    public const string WAITING = 'queries:process:waiting';

    /** A failing pass (error, timeout) is tried 3 times; lock waits are releases, not exceptions. */
    public int $maxExceptions = 3;

    public int $timeout = 1700;

    public int $uniqueFor = 3600;

    public function __construct(public bool $import = false, public ?int $userId = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'heavy'));
    }

    public function uniqueId(): string
    {
        return $this->import ? 'import' : 'all';
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(QueryPipeline $pipeline, QueryNotifier $notifier): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            if ($this->import || $this->waits()) {
                $this->release(120);
            }

            return;
        }
        // This pass sees every request made so far: the next request waits again.
        Cache::forget(self::WAITING);
        try {
            $stats = $pipeline->run($this->import);
        } finally {
            $lock->release();
        }
        if ($this->import) {
            $notifier->send($this->userId, sprintf('İlk içe aktarma tamamlandı: %d sorgu', $stats['queries']), route('operator.library.queries', [], false));
        }
    }

    /** A waiting full pass that gives up frees its place, so the next request waits instead of ending. */
    public function failed(?Throwable $exception): void
    {
        if (! $this->import && Cache::get(self::WAITING) === $this->copy()) {
            Cache::forget(self::WAITING);
        }
    }

    /** Whether this full pass is (or now becomes) the one waiting for the lock. */
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
