<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryRescanner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Filter basket / matching keywords changed: ALL filter terms × ALL library queries + matching keywords re-run → a
 * review waiting for approval, then the notification "Filtre taraması hazır: N silinecek, M hizmet değişikliği".
 * Queued requests collapse into one; never overlaps the pipeline.
 */
final class RescanQueriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

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

    public function handle(QueryRescanner $rescanner, QueryNotifier $notifier): void
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(120);

            return;
        }
        try {
            $review = $rescanner->scan($this->userId);
        } finally {
            $lock->release();
        }
        $notifier->send($this->userId, sprintf('Filtre taraması hazır: %d silinecek, %d hizmet değişikliği', $review->deletions, $review->changes),
            route('operator.library.queries.review', ['review' => $review->id], false), (int) $review->id);
    }
}
