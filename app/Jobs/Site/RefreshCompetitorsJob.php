<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\Competitors\CompetitorRefresher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "Rakipleri güncelle" / monthly: SERP top-10 per approved cluster, domain classes, competitor pages for one site. */
final class RefreshCompetitorsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 850;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $siteId)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(CompetitorRefresher $refresher): void
    {
        $site = DigitalAsset::query()->with('brand.customer')->find($this->siteId);
        $result = $site !== null ? $refresher->refresh($site) : ['status' => 'not_operational'];
        Cache::put(CompetitorRefresher::statusKey($this->siteId), $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(CompetitorRefresher::statusKey($this->siteId), ['status' => 'error'], now()->addDay());
    }
}
