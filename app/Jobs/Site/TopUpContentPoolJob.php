<?php

namespace App\Jobs\Site;

use App\Services\Site\SiteOperations;
use App\Services\Work\ContentCoverage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A title left the pool (approved and written): the site's pool is filled back to ContentCoverage::POOL with the same
 * engine as the twice-daily fill (yakup, 2026-10-12 "havuz sürekli min 20 kalsın"). Delayed a minute and unique per
 * site, so "Hepsini onayla ve yazdır" asks once for all the titles it took. Since the weekly cycle (2026-10-16) only
 * sites still in their one-off back-dated fill have such a pool; for the rest this does nothing.
 */
final class TopUpContentPoolJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(public int $siteId)
    {
        $this->onQueue((string) config('queue.content_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(ContentCoverage $coverage): void
    {
        foreach ($coverage->needs(false, $this->siteId) as $need) {
            SiteOperations::dispatch($need['site_id'], SiteOperations::WEEKLY_CONTENT, ['wants' => $need['wants']]);
        }
    }
}
