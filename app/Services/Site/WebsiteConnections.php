<?php

namespace App\Services\Site;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A website's four connections (Aşama 6): the WordPress connector, Search Console, GA4 and the last crawl. Web siteleri
 * shows them as columns and filters on them; the site's Bağlantılar shows them as four steps.
 */
final class WebsiteConnections
{
    /** A crawl older than this many days is a problem. */
    public const int CRAWL_STALE_DAYS = 14;

    /**
     * Per site: connector (paired, version, whether a one-click update is waiting), Search Console and GA4 bound, the
     * last crawl (date, failed) and the problems that put it under "Sorun var". Read in a few batch queries.
     *
     * @param  Collection<int, DigitalAsset>  $sites
     * @return array<int, array{paired: bool, version: string, outdated: bool, updatable: bool, gsc: bool, ga4: bool, crawl_at: ?CarbonInterface, crawl_failed: bool, problems: list<string>}>
     */
    public static function for(Collection $sites): array
    {
        $ids = $sites->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $latest = (string) config('moxdop-wordpress.connector_version', '1.0.0');
        $selfUpdate = (string) config('moxdop-wordpress.self_update_min_plugin_version', '1.4.1');
        $connections = CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)->whereIn('digital_asset_id', $ids)->get()->keyBy('digital_asset_id');
        $lastRuns = CollectionRun::query()->whereIn('digital_asset_id', $ids)->whereJsonContains('request_context->provider_sources', 'WEBSITE_DIRECT')
            ->groupBy('digital_asset_id')->selectRaw('max(id) as id')->pluck('id');
        $crawls = CollectionRun::query()->whereIn('id', $lastRuns)->get(['id', 'digital_asset_id', 'status', 'finished_at', 'created_at'])->keyBy('digital_asset_id');
        $out = [];
        foreach ($sites as $site) {
            $connection = $connections->get($site->id);
            $paired = $connection !== null && data_get($connection->config, 'pairing_state') === 'paired';
            $version = $paired ? (string) data_get($connection->config, 'plugin_version', '') : '';
            $outdated = $version !== '' && version_compare($version, $latest, '<');
            $gsc = BrandScope::siteResources($site, 'search_console') !== [];
            $ga4 = BrandScope::siteResources($site, 'ga4') !== [];
            $run = $crawls->get($site->id);
            $crawlAt = $run?->finished_at ?? $run?->created_at;
            $failed = $run !== null && $run->status === CollectionRunStatus::Failed;
            $problems = array_values(array_filter([
                $paired ? null : 'eklenti bağlı değil',
                $gsc ? null : 'Search Console yok',
                $ga4 ? null : 'GA4 yok',
                match (true) {
                    $run === null => 'taranmadı',
                    $failed => 'son tarama başarısız',
                    $crawlAt !== null && $crawlAt->lt(now()->subDays(self::CRAWL_STALE_DAYS)) => 'tarama eski',
                    default => null,
                },
            ]));
            $out[(int) $site->id] = ['paired' => $paired, 'version' => $version, 'outdated' => $outdated,
                'updatable' => $outdated && version_compare($version, $selfUpdate, '>='),
                'gsc' => $gsc, 'ga4' => $ga4, 'crawl_at' => $crawlAt, 'crawl_failed' => $failed, 'problems' => $problems];
        }

        return $out;
    }
}
