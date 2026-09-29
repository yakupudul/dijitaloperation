<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Operations\PilotRefresh;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of moxdop:pilot:refresh after the query pipeline and clustering: brand demand hub, topic map, SEO plan,
 * URL karnesi, analysts, finish. Each step runs on the heavy queue, records its outcome for `--status` and is
 * idempotent; see PilotRefresh for the order.
 */
final class PilotRefreshStepJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $step, public int $brandId, public ?int $siteId = null, public ?string $lockOwner = null)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function handle(PilotRefresh $pilot): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand === null) {
            return;
        }
        $site = $this->siteId !== null ? DigitalAsset::query()->where('type', 'website')->find($this->siteId) : null;
        $follow = $pilot->runStep($this->step, $brand, $site, $this->lockOwner);
        // A step may hand a job back to run next (the SEO plan's own RunSeoPlanJob).
        if ($follow !== null) {
            $this->prependToChain($follow);
        }
    }
}
