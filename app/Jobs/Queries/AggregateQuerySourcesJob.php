<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QuerySourceAggregator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Recomputes `query_sources` months of one account after a query pull (Search Console query × page, Google Ads
 * search terms, Business Profile keywords). Runs on the heavy queue; one account at a time.
 */
final class AggregateQuerySourcesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        public readonly int $externalResourceId,
        public readonly string $from,
        public readonly string $to,
    ) {
        $this->onQueue((string) config('queue.heavy_queue', 'heavy'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(QuerySourceAggregator $aggregator): void
    {
        // A running aggregation of the same account: try again later instead of failing with LockTimeoutException.
        try {
            Cache::lock('query-sources:'.$this->externalResourceId, $this->timeout)
                ->block(60, fn () => $aggregator->aggregate($this->externalResourceId, $this->from, $this->to));
        } catch (LockTimeoutException) {
            $this->release(300);

            return;
        }
        // Faz 3: the normalized query layer follows every aggregation (queued requests collapse into one pass).
        ProcessQueriesJob::dispatch();
    }
}
