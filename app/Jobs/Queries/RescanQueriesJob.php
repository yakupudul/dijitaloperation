<?php

namespace App\Jobs\Queries;

use App\Enums\NotificationKind;
use App\Livewire\Operator\Library\QueriesPage;
use App\Models\UserNotification;
use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryRescanner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Filter basket / matching keywords changed: ALL filter terms × ALL library queries + matching keywords re-run → the
 * proposals join the Sorgular › Silinecekler pool, then the notification "Filtre taraması hazır: N silinecek, M hizmet
 * değişikliği" (open totals of the pool) opening that tab.
 * Queued requests collapse into one; never overlaps the pipeline.
 *
 * While ProcessQueriesJob::LOCK is busy (a full pass, another scan, rules) a request waits by releases bounded by
 * retryUntil(), not attempts: those runs take up to half an hour each. Only one scan waits (WAITING holds its job
 * uuid): it starts after the current run and reads the filter basket then, so further copies end at once.
 */
final class RescanQueriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The scan waiting for ProcessQueriesJob::LOCK (its job uuid); the scan that takes the lock clears it. */
    public const string WAITING = 'queries:rescan:waiting';

    /** A failing scan (error, timeout) is tried 3 times; lock waits are releases, not exceptions. */
    public int $maxExceptions = 3;

    public int $timeout = 1700;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $userId = null)
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

    public function handle(QueryRescanner $rescanner, QueryNotifier $notifier): void
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            if ($this->waits()) {
                $this->release(120);
            }

            return;
        }
        // This scan reads the filter basket as it is now: the next request waits again.
        Cache::forget(self::WAITING);
        try {
            $review = $rescanner->scan($this->userId);
        } finally {
            $lock->release();
        }
        $open = QueryRescanner::openCounts();
        $url = QueriesPage::deletionsUrl();
        // One live notice per operator: earlier unread rescan notices point to the same (accumulating) tab.
        UserNotification::query()->where('notification_kind', NotificationKind::QueriesNotice->value)->whereNull('read_at')
            ->when($this->userId !== null, fn ($q) => $q->where('recipient_user_id', $this->userId))
            ->where(fn ($q) => $q->where('presentation->url', $url)->orWhere('presentation->url', 'like', '/library/queries/review/%'))
            ->update(['read_at' => now()]);
        $notifier->send($this->userId, sprintf('Filtre taraması hazır: %d silinecek, %d hizmet değişikliği', $open['delete'], $open['service']), $url, (int) $review->id);
    }

    /** A waiting scan that gives up frees its place, so the next request waits instead of ending. */
    public function failed(?Throwable $exception): void
    {
        if (Cache::get(self::WAITING) === $this->copy()) {
            Cache::forget(self::WAITING);
        }
    }

    /** Whether this scan is (or now becomes) the one waiting for the lock. */
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
