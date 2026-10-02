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

/** Website (channel `search`): Search Console clicks, impressions and position of the suggestion's URL. */
final class SearchOutcomeReader implements OutcomeMetricReader
{
    public function scope(Suggestion $suggestion): ?array
    {
        $page = $suggestion->page_id !== null ? Page::query()->find($suggestion->page_id) : null;
        if ($page === null) {
            return null;
        }

        return ['url' => (string) $page->url, 'site_id' => (int) $page->website_asset_id, 'brand_id' => (int) $suggestion->brand_id];
    }

    public function lastDay(array $scope): ?string
    {
        $brand = Brand::query()->find($scope['brand_id'] ?? 0);
        $ids = $brand !== null ? SiteScope::resourceIds($brand, 'search_console') : [];
        $last = $ids === [] ? null : DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->where('search_type', 'web')->max('reporting_date');

        return $last !== null ? substr((string) $last, 0, 10) : null;
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $brand = Brand::query()->find($scope['brand_id'] ?? 0);
        $site = DigitalAsset::query()->find($scope['site_id'] ?? 0);
        if ($brand === null || $site === null) {
            return null;
        }
        $totals = app(SiteMetrics::class)->pageTotals($brand, $site, [$from, $to]);
        if ($totals === []) {
            return null;
        }
        $row = $totals[SeoText::urlKey((string) $scope['url'])] ?? null;

        return ['clicks' => (int) ($row['clicks'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0), 'position' => $row['position'] ?? null];
    }
}
