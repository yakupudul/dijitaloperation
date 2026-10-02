<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\SiteOperations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One website-screen operation (categorize, service ↔ page, cluster ↔ page, summaries, URL analysis, AI ile yap,
 * standard proposal, weekly content, discovery, article) on the heavy queue. Unique per site × operation × subject;
 * each operation is idempotent and bounded. The screen reads the stored result and the status line only.
 * Eşleştir (cluster ↔ page) of a large site runs in parts: a part that ran out of time queues the next one
 * (params part = n), at most MAX_PARTS; one Eşleştir runs per site at a time whatever started it.
 */
final class RunSiteOperationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /** Eşleştir parts of one pass at most (each part makes progress; this only stops a runaway chain). */
    public const int MAX_PARTS = 30;

    /** @param  array<string, mixed>  $params */
    public function __construct(public int $siteId, public string $operation, public array $params = [])
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->operation.':'.md5((string) json_encode($this->params));
    }

    public function handle(SiteOperations $operations): void
    {
        $site = DigitalAsset::query()->where('type', 'website')->find($this->siteId);
        if ($site === null) {
            SiteOperations::putStatus($this->siteId, $this->operation, ['status' => 'no_site'], $this->params);

            return;
        }
        if ($this->operation !== SiteOperations::CLUSTER_AUDIT) {
            SiteOperations::putStatus($this->siteId, $this->operation, $operations->run($site, $this->operation, $this->params), $this->params);

            return;
        }
        $lock = Cache::lock('site-op-lock:'.$this->siteId.':'.SiteOperations::CLUSTER_AUDIT, $this->timeout + 60);
        if (! $lock->get()) {
            return; // another Eşleştir of this site runs: it keeps the status
        }
        try {
            $result = $operations->run($site, $this->operation, $this->params);
        } finally {
            $lock->release();
        }
        $part = (int) ($this->params['part'] ?? 1);
        if (($result['status'] ?? null) === 'partial') {
            if ($part >= self::MAX_PARTS) {
                SiteOperations::putStatus($this->siteId, $this->operation, ['status' => 'stalled', 'part' => $part]);

                return;
            }
            SiteOperations::dispatch($this->siteId, $this->operation, ['part' => $part + 1]);

            return;
        }
        SiteOperations::putStatus($this->siteId, $this->operation, $result + ['part' => $part], $this->params);
    }

    public function failed(?Throwable $exception): void
    {
        SiteOperations::putStatus($this->siteId, $this->operation, ['status' => $exception instanceof TimeoutExceededException ? 'timeout' : 'error'], $this->params);
    }
}
