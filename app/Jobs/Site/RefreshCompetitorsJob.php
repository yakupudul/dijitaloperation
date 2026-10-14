<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\Competitors\CompetitorRefresher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Rakipleri güncelle" / monthly: SERP top-10 per approved cluster, domain classes, competitor pages for one site. The
 * domain classes can wait for Claude (MCP): the job then runs again once Claude answered.
 */
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

    public function handle(CompetitorRefresher $refresher, AiTaskQueue $tasks): void
    {
        $site = DigitalAsset::query()->with('brand.customer')->find($this->siteId);
        if ($site === null) {
            Cache::put(CompetitorRefresher::statusKey($this->siteId), ['status' => 'not_operational'], now()->addDay());

            return;
        }
        $tasks->begin(new self($this->siteId), $site->brand_id !== null ? (int) $site->brand_id : null, 'Rakip sınıflandırma · '.$site->name);
        try {
            $result = $refresher->refresh($site);
        } finally {
            $tasks->settle();
        }
        // Waiting for Claude keeps the tab's "güncelleniyor…" until the answers come back.
        Cache::put(CompetitorRefresher::statusKey($this->siteId), $result['status'] === 'queued' ? ['status' => 'running'] : $result, now()->addDay());
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(CompetitorRefresher::statusKey($this->siteId), ['status' => 'error'], now()->addDay());
    }
}
