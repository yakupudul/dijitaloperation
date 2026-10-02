<?php

namespace App\Jobs\Queries;

use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sorgular pipeline (query_sources → queries → brand_queries): link sources, totals, Bekleyenler. `import` = the
 * approved first import ("AI ile planla" step 3), which notifies the operator when done. Idempotent full pass on the
 * heavy queue; queued requests collapse into one, runs never overlap.
 */
final class ProcessQueriesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const string LOCK = 'queries:process';

    public int $tries = 10;

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

    public function handle(QueryPipeline $pipeline, QueryNotifier $notifier): void
    {
        $lock = Cache::lock(self::LOCK, $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(120);

            return;
        }
        try {
            $stats = $pipeline->run($this->import);
        } finally {
            $lock->release();
        }
        if ($this->import) {
            $notifier->send($this->userId, sprintf('İlk içe aktarma tamamlandı: %d sorgu', $stats['queries']), route('operator.library.queries', [], false));
        }
    }
}
