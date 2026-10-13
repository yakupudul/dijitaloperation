<?php

namespace App\Services\Outcomes\Readers;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeMetricReader;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteScope;
use Illuminate\Support\Facades\DB;

/**
 * Website (channel `search`): Search Console clicks, impressions and position of the suggestion's URL, plus the GA4
 * sessions and key events (conversions) of that landing page when GA4 is collected. The URL is the suggestion's page,
 * else the URL its action names (a 301 merge's main page, a site fix's target) on its site.
 */
final class SearchOutcomeReader implements OutcomeMetricReader
{
    public function scope(Suggestion $suggestion): ?array
    {
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        if ($page !== null) {
            return ['url' => (string) $page->url, 'site_id' => (int) $page->website_asset_id, 'brand_id' => (int) $suggestion->brand_id];
        }
        $action = (array) $suggestion->action;
        $url = collect([$action['main_url'] ?? null, $action['url'] ?? null, $action['target'] ?? null])
            ->first(fn ($value): bool => is_string($value) && preg_match('#^https?://#i', $value) === 1);
        $siteId = (int) ($action['site_id'] ?? ($suggestion->target_type === 'asset' ? $suggestion->target_id : 0));
        if ($url === null || $siteId === 0 || ! DigitalAsset::query()->whereKey($siteId)->exists()) {
            return null;
        }

        return ['url' => (string) $url, 'site_id' => $siteId, 'brand_id' => (int) $suggestion->brand_id];
    }

    public function lastDay(array $scope): ?string
    {
        return $this->edge($scope, 'max');
    }

    public function firstDay(array $scope): ?string
    {
        return $this->edge($scope, 'min');
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $brand = Brand::query()->find($scope['brand_id'] ?? 0);
        $site = DigitalAsset::query()->find($scope['site_id'] ?? 0);
        if ($brand === null || $site === null) {
            return null;
        }
        $metrics = app(SiteMetrics::class);
        $totals = $metrics->pageTotals($brand, $site, [$from, $to]);
        if ($totals === []) {
            return null;
        }
        $row = $totals[SeoText::urlKey((string) $scope['url'])] ?? null;
        $ga4 = $metrics->ga4Landing($brand, (string) $scope['url'], range: [$from, $to]);

        return ['clicks' => (int) ($row['clicks'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0), 'position' => $row['position'] ?? null]
            + ($ga4 !== null ? ['sessions' => $ga4['sessions'], 'conversions' => $ga4['key_events']] : []);
    }

    /** @param  array<string, mixed>  $scope */
    private function edge(array $scope, string $aggregate): ?string
    {
        $brand = Brand::query()->find($scope['brand_id'] ?? 0);
        $ids = $brand !== null ? SiteScope::resourceIds($brand, 'search_console') : [];
        if ($aggregate === 'max') {
            return app(SiteMetrics::class)->lastSearchConsoleDay($ids);
        }
        $day = $ids === [] ? null : DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->where('search_type', 'web')->{$aggregate}('reporting_date');

        return $day !== null ? substr((string) $day, 0, 10) : null;
    }
}
