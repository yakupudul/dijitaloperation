<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Queries\QuerySourceAggregator;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Last-28-day numbers of a brand's website from the facts kept in Faz 1: Search Console query × page
 * (`gsc_query_page_daily`, web) and GA4 landing page × source (`ga4_landing_source_daily`). The window ends on the last
 * collected day. Pages are matched by canonical URL key (SeoText::urlKey).
 */
final class SiteMetrics
{
    /** @var array<int, array{start: string, end: string, prev_start: string, prev_end: string}|null> */
    private array $windows = [];

    /** @return array{start: string, end: string, prev_start: string, prev_end: string}|null */
    public function window(Brand $brand): ?array
    {
        if (array_key_exists($brand->id, $this->windows)) {
            return $this->windows[$brand->id];
        }
        $ids = SiteScope::resourceIds($brand, 'search_console');
        $last = $ids === [] ? null : DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $ids)->where('search_type', 'web')->max('reporting_date');
        if ($last === null) {
            return $this->windows[$brand->id] = null;
        }
        $end = CarbonImmutable::parse((string) $last);

        return $this->windows[$brand->id] = [
            'start' => $end->subDays(27)->toDateString(), 'end' => $end->toDateString(),
            'prev_start' => $end->subDays(55)->toDateString(), 'prev_end' => $end->subDays(28)->toDateString(),
        ];
    }

    /**
     * Per page of this site: clicks, impressions, impression-weighted position (28 days, or the given [from, to]).
     *
     * @param  array{0: string, 1: string}|null  $range
     * @return array<string, array{url: string, clicks: int, impressions: int, position: ?float}>
     */
    public function pageTotals(Brand $brand, DigitalAsset $site, ?array $range = null): array
    {
        $window = $range !== null ? ['start' => $range[0], 'end' => $range[1]] : $this->window($brand);
        if ($window === null) {
            return [];
        }
        $host = $this->host($site);
        $position = QuerySourceAggregator::positionExpression();
        $out = [];
        DB::table('gsc_query_page_daily')->whereIn('external_resource_id', SiteScope::resourceIds($brand, 'search_console'))
            ->where('search_type', 'web')->whereBetween('reporting_date', [$window['start'], $window['end']])
            ->groupBy('page')->orderBy('page')
            ->selectRaw("page, sum(clicks) as clicks, sum(impressions) as impressions, sum(({$position}) * impressions) as weighted, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as weight")
            ->get()->each(function (object $row) use (&$out, $host): void {
                $key = SeoText::urlKey((string) $row->page);
                if ($host !== '' && ! str_starts_with($key, $host)) {
                    return;
                }
                $current = $out[$key] ?? ['url' => (string) $row->page, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0];
                $out[$key] = ['url' => $current['url'], 'clicks' => $current['clicks'] + (int) $row->clicks, 'impressions' => $current['impressions'] + (int) $row->impressions,
                    'weighted' => $current['weighted'] + (float) $row->weighted, 'weight' => $current['weight'] + (int) $row->weight];
            });

        return array_map(fn (array $r): array => ['url' => $r['url'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'],
            'position' => $r['weight'] > 0 ? round($r['weighted'] / $r['weight'], 1) : null], $out);
    }

    /** Screen cache (10 minutes) of the site's page totals and organic clicks; cleared by the mapper / weekly refresh. */
    public const int SCREEN_CACHE_SECONDS = 600;

    /** @return array<string, array{url: string, clicks: int, impressions: int, position: ?float}> */
    public function cachedPageTotals(Brand $brand, DigitalAsset $site): array
    {
        return Cache::remember('site:page-totals:'.$site->id, self::SCREEN_CACHE_SECONDS, fn (): array => $this->pageTotals($brand, $site));
    }

    /** @return array{clicks: int, prev: int, end: string}|null */
    public function cachedSiteClicks(Brand $brand, DigitalAsset $site): ?array
    {
        return Cache::remember('site:clicks:'.$site->id, self::SCREEN_CACHE_SECONDS, fn (): ?array => $this->siteClicks($brand, $site));
    }

    public static function forgetPageTotals(int $siteId): void
    {
        Cache::forget('site:page-totals:'.$siteId);
        Cache::forget('site:clicks:'.$siteId);
    }

    /** @return array{clicks: int, impressions: int, position: ?float}|null one page's 28-day totals (null = no data) */
    public function pageTotal(Brand $brand, DigitalAsset $site, string $url): ?array
    {
        if ($this->window($brand) === null) {
            return null;
        }
        $row = $this->pageTotals($brand, $site)[SeoText::urlKey($url)] ?? null;

        return $row === null ? ['clicks' => 0, 'impressions' => 0, 'position' => null] : ['clicks' => $row['clicks'], 'impressions' => $row['impressions'], 'position' => $row['position']];
    }

    /** @return array{clicks: int, prev: int, end: string}|null organic clicks of the site: 28 days and the 28 before */
    public function siteClicks(Brand $brand, DigitalAsset $site): ?array
    {
        // Property totals first: they include the anonymized queries the query × page facts leave out.
        $resources = SiteScope::resourceIds($brand, 'search_console');
        $last = $resources === [] ? null : DB::table('gsc_property_daily')->whereIn('external_resource_id', $resources)->where('search_type', 'web')->max('reporting_date');
        if ($last !== null) {
            $end = CarbonImmutable::parse((string) $last);
            $sum = fn (CarbonImmutable $from, CarbonImmutable $to): int => (int) DB::table('gsc_property_daily')->whereIn('external_resource_id', $resources)
                ->where('search_type', 'web')->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])->sum('clicks');

            return ['clicks' => $sum($end->subDays(27), $end), 'prev' => $sum($end->subDays(55), $end->subDays(28)), 'end' => $end->toDateString()];
        }
        $window = $this->window($brand);
        if ($window === null) {
            return null;
        }
        $host = $this->host($site);
        $sum = function (string $from, string $to) use ($brand, $host): int {
            $total = 0;
            DB::table('gsc_query_page_daily')->whereIn('external_resource_id', SiteScope::resourceIds($brand, 'search_console'))
                ->where('search_type', 'web')->whereBetween('reporting_date', [$from, $to])
                ->groupBy('page')->selectRaw('page, sum(clicks) as clicks')->get()
                ->each(function (object $row) use (&$total, $host): void {
                    if ($host === '' || str_starts_with(SeoText::urlKey((string) $row->page), $host)) {
                        $total += (int) $row->clicks;
                    }
                });

            return $total;
        };

        return ['clicks' => $sum($window['start'], $window['end']), 'prev' => $sum($window['prev_start'], $window['prev_end']), 'end' => $window['end']];
    }

    /**
     * Query × page facts (28 days) of the given normalized queries: raw Search Console queries are linked to them through
     * `query_sources` (Faz 3).
     *
     * @param  list<int>  $queryIds
     * @return list<array{query_id: int, url_key: string, url: string, clicks: int, impressions: int, position: ?float}>
     */
    public function queryPageFacts(Brand $brand, DigitalAsset $site, array $queryIds): array
    {
        $window = $this->window($brand);
        $resources = SiteScope::resourceIds($brand, 'search_console');
        if ($window === null || $queryIds === [] || $resources === []) {
            return [];
        }
        $links = [];
        foreach (array_chunk($queryIds, 500) as $chunk) {
            DB::table('query_sources')->whereIn('external_resource_id', $resources)->whereIn('query_id', $chunk)
                ->select(['raw_query', 'query_id'])->distinct()->get()
                ->each(function (object $row) use (&$links): void {
                    $links[(string) $row->raw_query] = (int) $row->query_id;
                });
        }
        if ($links === []) {
            return [];
        }
        $host = $this->host($site);
        $position = QuerySourceAggregator::positionExpression();
        $out = [];
        foreach (array_chunk(array_keys($links), 500) as $raws) {
            DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $resources)->where('search_type', 'web')
                ->whereBetween('reporting_date', [$window['start'], $window['end']])->whereIn('query', $raws)
                ->groupBy('query', 'page')
                ->selectRaw("query, page, sum(clicks) as clicks, sum(impressions) as impressions, sum(({$position}) * impressions) as weighted, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as weight")
                ->get()->each(function (object $row) use (&$out, $links, $host): void {
                    $key = SeoText::urlKey((string) $row->page);
                    $queryId = $links[QuerySourceAggregator::cleanRaw((string) $row->query)] ?? null;
                    if ($queryId === null || ($host !== '' && ! str_starts_with($key, $host))) {
                        return;
                    }
                    $out[] = ['query_id' => $queryId, 'url_key' => $key, 'url' => (string) $row->page, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                        'position' => (int) $row->weight > 0 ? round((float) $row->weighted / (int) $row->weight, 1) : null];
                });
        }

        return $out;
    }

    /**
     * Top Search Console queries of one URL (28 days).
     *
     * @return list<array{query: string, clicks: int, impressions: int, position: ?float}>
     */
    public function pageQueries(Brand $brand, string $url, int $limit = 15): array
    {
        $window = $this->window($brand);
        if ($window === null) {
            return [];
        }
        $position = QuerySourceAggregator::positionExpression();
        $variants = array_values(array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']));

        return DB::table('gsc_query_page_daily')->whereIn('external_resource_id', SiteScope::resourceIds($brand, 'search_console'))
            ->where('search_type', 'web')->whereBetween('reporting_date', [$window['start'], $window['end']])->whereIn('page', $variants)
            ->groupBy('query')->orderByRaw('sum(impressions) desc')->limit($limit)
            ->selectRaw("query, sum(clicks) as clicks, sum(impressions) as impressions, sum(({$position}) * impressions) as weighted, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as weight")
            ->get()->map(fn (object $row): array => ['query' => (string) $row->query, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                'position' => (int) $row->weight > 0 ? round((float) $row->weighted / (int) $row->weight, 1) : null])
            ->values()->all();
    }

    /** @return array{sessions: int, key_events: float}|null GA4 landing page (28 days, all sources); null = no GA4 data */
    public function ga4Landing(Brand $brand, string $url): ?array
    {
        $ids = SiteScope::resourceIds($brand, 'ga4');
        $last = $ids === [] ? null : DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $ids)->max('reporting_date');
        if ($last === null) {
            return null;
        }
        $end = CarbonImmutable::parse((string) $last);
        $path = SeoText::urlPath($url);
        $paths = array_values(array_unique([$path, rtrim($path, '/') ?: '/', rtrim($path, '/').'/']));
        $row = DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $ids)
            ->whereBetween('reporting_date', [$end->subDays(27)->toDateString(), $end->toDateString()])->whereIn('landingPage', $paths)
            ->selectRaw('sum(sessions) as sessions, sum('.DB::getQueryGrammar()->wrap('keyEvents').') as key_events')->first();

        return ['sessions' => (int) ($row->sessions ?? 0), 'key_events' => round((float) ($row->key_events ?? 0), 1)];
    }

    private function host(DigitalAsset $site): string
    {
        return SeoText::urlKey(SiteScope::origin($site));
    }
}
