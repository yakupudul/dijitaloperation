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

/**
 * Filter basket / matching keywords changed: ALL filter terms × ALL library queries + matching keywords re-run → the
 * proposals join the Sorgular › Silinecekler pool, then the notification "Filtre taraması hazır: N silinecek, M hizmet
 * değişikliği" (open totals of the pool) opening that tab.
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
        $open = QueryRescanner::openCounts();
        $url = QueriesPage::deletionsUrl();
        // One live notice per operator: earlier unread rescan notices point to the same (accumulating) tab.
        UserNotification::query()->where('notification_kind', NotificationKind::QueriesNotice->value)->whereNull('read_at')
            ->when($this->userId !== null, fn ($q) => $q->where('recipient_user_id', $this->userId))
            ->where(fn ($q) => $q->where('presentation->url', $url)->orWhere('presentation->url', 'like', '/library/queries/review/%'))
            ->update(['read_at' => now()]);
        $notifier->send($this->userId, sprintf('Filtre taraması hazır: %d silinecek, %d hizmet değişikliği', $open['delete'], $open['service']), $url, (int) $review->id);
    }
}
