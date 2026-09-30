<?php

namespace App\Services\Site\Analysis;

use App\Models\BrandOffering;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Queries\QuerySourceAggregator;
use App\Services\Site\SiteScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfalar (one row per URL path of one website): inventory (`pages` = WordPress + sitemap, `website_url` = page
 * collection) × Search Console / GA4 (SiteAnalysisReader::pages, period vs previous period) × Google Ads landing pages
 * (brand's Google Ads accounts, same period) × health (latest HTTP / metadata snapshot and crawl issues of each URL) ×
 * brand service / cluster mapping. The merged list is built once per site × period × last data day and cached; the
 * screen filters, sorts and paginates the cached list. Read only.
 *
 * @phpstan-type PageRow array{path: string, url: string, page_id: ?int, title: ?string, category: ?string, sources: list<string>,
 *     services: list<string>, clusters: list<string>, is_main: bool, clicks: int, impressions: int, position: ?float, prev_clicks: int,
 *     delta: ?int, sessions: int, key_events: float, ads_clicks: ?int, ads_cost: ?float, status_code: ?int, indexable: ?bool, issues: int,
 *     serious: int, problem: bool, declining: bool, no_traffic: bool, wp_post_id: ?int}
 */
final class SitePagesReader
{
    public const array PERIODS = [28 => '28 gün', 90 => '90 gün'];

    public const array FILTERS = ['ana' => 'Ana hizmet sayfaları', 'tum' => 'Tüm sayfalar', 'trafiksiz' => 'Trafik almayan', 'sorunlu' => 'Sorunlu', 'dususte' => 'Düşüşte'];

    public const array SORTS = ['clicks', 'impressions', 'position', 'delta', 'sessions', 'key_events', 'ads_clicks', 'issues', 'path'];

    /** Düşüşte: at least this many clicks in the previous period and a drop of 20 % or more. */
    private const int DECLINE_MIN_PREVIOUS = 5;

    private const float DECLINE_RATIO = 0.8;

    private const array SERIOUS = ['critical', 'high'];

    private const int CACHE_MINUTES = 30;

    public function __construct(private readonly SiteAnalysisReader $analysis) {}

    public static function period(int $days): int
    {
        return array_key_exists($days, self::PERIODS) ? $days : 28;
    }

    /**
     * Every URL of the site, merged (cached).
     *
     * @return array<string, PageRow> path => row
     */
    public function rows(DigitalAsset $site, int $days): array
    {
        $days = self::period($days);
        $w = $this->analysis->window($site, $days);
        $inventory = Page::query()->where('website_asset_id', $site->id)->selectRaw('count(*) as n, max(updated_at) as at')->first();
        $key = 'site:pages:'.$site->id.':'.$days.':'.$w['end'].':'.md5(json_encode([$w['gsc'], $w['ga4'], $inventory?->n, (string) $inventory?->at]));

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->build($site, $days, $w));
    }

    /** @return LengthAwarePaginator<int, PageRow> */
    public function list(DigitalAsset $site, int $days, string $filter, string $search, string $sort, bool $desc, int $page, int $perPage = 50): LengthAwarePaginator
    {
        $rows = array_values(array_filter($this->rows($site, $days), fn (array $row): bool => self::matches($row, $filter)));
        $needle = self::fold(trim($search));
        if ($needle !== '') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_contains(self::fold($row['path'].' '.($row['title'] ?? '').' '.implode(' ', [...$row['services'], ...$row['clusters']])), $needle)));
        }
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'clicks';
        usort($rows, function (array $a, array $b) use ($sort, $desc): int {
            if ($sort === 'path') {
                $cmp = strcmp($a['path'], $b['path']);

                return $desc ? -$cmp : $cmp;
            }
            // Empty values (no position, no Ads data…) always last.
            $x = $a[$sort];
            $y = $b[$sort];
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null) ?: strcmp($a['path'], $b['path']);
            }
            $cmp = $desc ? $y <=> $x : $x <=> $y;

            return $cmp !== 0 ? $cmp : ([$b['impressions'], $a['path']] <=> [$a['impressions'], $b['path']]);
        });
        $page = max(1, $page);

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page, ['pageName' => 'p']);
    }

    /** @return array<string, int> filter => row count */
    public function counts(DigitalAsset $site, int $days): array
    {
        $rows = $this->rows($site, $days);
        $out = [];
        foreach (array_keys(self::FILTERS) as $filter) {
            $out[$filter] = count(array_filter($rows, fn (array $row): bool => self::matches($row, $filter)));
        }

        return $out;
    }

    /**
     * State of the main service pages: sorunlu (HTTP ≥ 400, noindex or a critical / high issue) → düşüşte → iyi.
     *
     * @return array{iyi: int, dususte: int, sorunlu: int, rows: list<array{row: PageRow, state: string}>}
     */
    public function serviceState(DigitalAsset $site, int $days): array
    {
        $out = ['iyi' => 0, 'dususte' => 0, 'sorunlu' => 0, 'rows' => []];
        foreach ($this->rows($site, $days) as $row) {
            if (! $row['is_main']) {
                continue;
            }
            $state = $row['problem'] ? 'sorunlu' : ($row['declining'] ? 'dususte' : 'iyi');
            $out[$state]++;
            $out['rows'][] = ['row' => $row, 'state' => $state];
        }
        $rank = ['sorunlu' => 0, 'dususte' => 1, 'iyi' => 2];
        usort($out['rows'], fn (array $a, array $b): int => [$rank[$a['state']], $b['row']['clicks'], $a['row']['path']] <=> [$rank[$b['state']], $a['row']['clicks'], $b['row']['path']]);

        return $out;
    }

    /**
     * Daily traffic of the site: Search Console property totals (they include anonymized queries; query × page facts
     * when the property totals are missing) and GA4 property totals (landing-page facts as fallback).
     *
     * @return array{start: string, end: string, series: list<array{date: string, clicks: int, impressions: int, sessions: int, key_events: float}>,
     *     current: array{clicks: int, impressions: int, sessions: int, key_events: float}, previous: array{clicks: int, impressions: int, sessions: int, key_events: float},
     *     has_gsc: bool, has_ga4: bool}
     */
    public function trend(DigitalAsset $site, int $days): array
    {
        $days = self::period($days);
        $w = $this->analysis->window($site, $days);
        $propertyEnd = $w['gsc'] !== [] ? DB::table('gsc_property_daily')->whereIn('external_resource_id', $w['gsc'])->where('search_type', 'web')->max('reporting_date') : null;
        $propertyEnd ??= $w['ga4'] !== [] ? DB::table('ga4_property_daily')->whereIn('external_resource_id', $w['ga4'])->max('reporting_date') : null;
        $end = CarbonImmutable::parse(max(array_filter([$w['end'], $propertyEnd !== null ? substr((string) $propertyEnd, 0, 10) : null])));
        $start = $end->subDays($days - 1);
        $prevStart = $start->subDays($days);

        return Cache::remember('site:pages:trend:'.$site->id.':'.$days.':'.$end->toDateString().':'.md5(json_encode([$w['gsc'], $w['ga4']])), now()->addMinutes(self::CACHE_MINUTES),
            function () use ($w, $start, $end, $prevStart): array {
                $from = $prevStart->toDateString();
                $to = $end->toDateString();
                $gsc = $this->dailyGsc($w['gsc'], $from, $to);
                $ga4 = $this->dailyGa4($w['ga4'], $from, $to);
                $series = [];
                $zero = ['clicks' => 0, 'impressions' => 0, 'sessions' => 0, 'key_events' => 0.0];
                $totals = ['current' => $zero, 'previous' => $zero];
                for ($day = $prevStart; $day->lte($end); $day = $day->addDay()) {
                    $date = $day->toDateString();
                    $point = ['date' => $date, 'clicks' => $gsc[$date]['clicks'] ?? 0, 'impressions' => $gsc[$date]['impressions'] ?? 0,
                        'sessions' => $ga4[$date]['sessions'] ?? 0, 'key_events' => $ga4[$date]['key_events'] ?? 0.0];
                    $bucket = $day->lt($start) ? 'previous' : 'current';
                    foreach (['clicks', 'impressions', 'sessions', 'key_events'] as $metric) {
                        $totals[$bucket][$metric] += $point[$metric];
                    }
                    if ($bucket === 'current') {
                        $series[] = $point;
                    }
                }
                $totals['current']['key_events'] = round($totals['current']['key_events'], 1);
                $totals['previous']['key_events'] = round($totals['previous']['key_events'], 1);

                return ['start' => $start->toDateString(), 'end' => $to, 'series' => $series, 'current' => $totals['current'], 'previous' => $totals['previous'],
                    'has_gsc' => $w['gsc'] !== [], 'has_ga4' => $w['ga4'] !== []];
            });
    }

    /**
     * One page: its row, Search Console queries (period), 90-day daily trend, GA4 channels, technical issues, internal
     * links in / out, related suggestions and whether WordPress fixes can be applied to it.
     *
     * @return array<string, mixed>|null
     */
    public function detail(DigitalAsset $site, string $path, int $days): ?array
    {
        $days = self::period($days);
        $row = $this->rows($site, $days)[$path] ?? null;
        if ($row === null) {
            return null;
        }
        $w = $this->analysis->window($site, $days);
        $urls = $this->urlVariants($site, $row);
        $end = CarbonImmutable::parse($w['end']);
        $trendStart = $end->subDays(89);
        $page = $row['page_id'] !== null ? Page::query()->find($row['page_id']) : null;
        $health = $this->pageHealth($site, $urls);

        return [
            'row' => $row,
            'window' => $w,
            'queries' => $this->pageQueries($w, $urls),
            'trend' => $this->pageTrend($w, $urls, $path, $trendStart->toDateString(), $end->toDateString()),
            'channels' => $this->pageChannels($w, $path),
            'issues' => $health['issues'],
            'links' => $health['links'],
            'suggestions' => $page !== null && $site->brand_id !== null
                ? Suggestion::query()->where('brand_id', $site->brand_id)->where('page_id', $page->id)
                    ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK])->orderBy('priority')->orderByDesc('id')->limit(10)->get()
                : collect(),
            'wordpress' => $page?->wp_post_id !== null && CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->exists(),
        ];
    }

    /** @param  PageRow  $row */
    public static function matches(array $row, string $filter): bool
    {
        return match ($filter) {
            'tum' => true,
            'trafiksiz' => $row['no_traffic'],
            'sorunlu' => $row['problem'],
            'dususte' => $row['declining'],
            default => $row['is_main'],
        };
    }

    /**
     * @param  array{end: string, start: string, prev_start: string, prev_end: string, gsc: list<int>, ga4: list<int>}  $w
     * @return array<string, PageRow>
     */
    private function build(DigitalAsset $site, int $days, array $w): array
    {
        $rows = [];
        $touch = function (string $url) use (&$rows): string {
            $path = SiteAnalysisReader::path($url);
            $rows[$path] ??= self::blank($path, $url);

            return $path;
        };

        // Inventory: pages (WordPress + sitemap) then the collected URL list.
        $pageIds = [];
        foreach (Page::query()->where('website_asset_id', $site->id)->orderBy('id')
            ->get(['id', 'url', 'title', 'category', 'is_indexable', 'wp_post_id']) as $page) {
            $path = $touch((string) $page->url);
            if ($rows[$path]['page_id'] !== null) {
                continue;
            }
            $pageIds[(int) $page->id] = $path;
            $rows[$path] = array_merge($rows[$path], [
                'url' => (string) $page->url, 'page_id' => (int) $page->id, 'title' => $page->title, 'category' => $page->category,
                'indexable' => $page->is_indexable === false ? false : $rows[$path]['indexable'], 'wp_post_id' => $page->wp_post_id,
            ]);
            $rows[$path]['sources'][] = $page->wp_post_id !== null ? 'WordPress' : 'sitemap';
        }
        foreach (DB::table('website_url')->where('digital_asset_id', $site->id)->orderBy('id')->limit(20000)->pluck('normalized_url') as $url) {
            $path = $touch((string) $url);
            if (! in_array('tarama', $rows[$path]['sources'], true)) {
                $rows[$path]['sources'][] = 'tarama';
            }
        }

        $this->mapServices($site, $pageIds, $rows);

        // Search Console + GA4 (period vs previous period).
        foreach ($this->analysis->pages($site, $days) as $metrics) {
            $path = $touch($metrics['url']);
            foreach (['clicks', 'impressions', 'position', 'prev_clicks', 'sessions', 'key_events'] as $field) {
                $rows[$path][$field] = $metrics[$field];
            }
        }

        foreach ($this->adsLanding($site, $w) as $path => $ads) {
            if (isset($rows[$path])) {
                $rows[$path]['ads_clicks'] = $ads['clicks'];
                $rows[$path]['ads_cost'] = $ads['cost'];
            }
        }

        foreach ($this->health($site) as $path => $health) {
            if (! isset($rows[$path])) {
                continue;
            }
            $rows[$path]['status_code'] = $health['status_code'];
            $rows[$path]['issues'] = $health['issues'];
            $rows[$path]['serious'] = $health['serious'];
            if ($health['noindex']) {
                $rows[$path]['indexable'] = false;
            } elseif ($rows[$path]['indexable'] === null && $health['status_code'] !== null) {
                $rows[$path]['indexable'] = $health['status_code'] < 300;
            }
        }

        foreach ($rows as $path => $row) {
            $inventory = $row['sources'] !== [];
            $rows[$path]['sources'] = array_values(array_unique($row['sources']));
            $rows[$path]['is_main'] = $inventory && ($row['services'] !== [] || $row['clusters'] !== [] || $row['category'] === 'hizmet');
            $rows[$path]['delta'] = $row['prev_clicks'] > 0 ? (int) round(($row['clicks'] - $row['prev_clicks']) / $row['prev_clicks'] * 100) : null;
            $rows[$path]['problem'] = ($row['status_code'] !== null && $row['status_code'] >= 400) || $row['indexable'] === false || $row['serious'] > 0;
            $rows[$path]['declining'] = $row['prev_clicks'] >= self::DECLINE_MIN_PREVIOUS && $row['clicks'] < $row['prev_clicks'] * self::DECLINE_RATIO;
            $rows[$path]['no_traffic'] = $inventory && $row['clicks'] === 0 && $row['sessions'] === 0;
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    /** @return PageRow */
    private static function blank(string $path, string $url): array
    {
        return [
            'path' => $path, 'url' => $url, 'page_id' => null, 'title' => null, 'category' => null, 'sources' => [], 'services' => [], 'clusters' => [],
            'is_main' => false, 'clicks' => 0, 'impressions' => 0, 'position' => null, 'prev_clicks' => 0, 'delta' => null, 'sessions' => 0,
            'key_events' => 0.0, 'ads_clicks' => null, 'ads_cost' => null, 'status_code' => null, 'indexable' => null, 'issues' => 0, 'serious' => 0,
            'problem' => false, 'declining' => false, 'no_traffic' => false, 'wp_post_id' => null,
        ];
    }

    /**
     * Brand services (offering_pages) and approved clusters (brand_cluster_pages) of the inventory pages.
     *
     * @param  array<int, string>  $pageIds  page id => path
     * @param  array<string, PageRow>  $rows
     */
    private function mapServices(DigitalAsset $site, array $pageIds, array &$rows): void
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null || $pageIds === []) {
            return;
        }
        $names = SiteScope::offerings($brand)->mapWithKeys(fn (BrandOffering $offering): array => [(int) $offering->id => $offering->displayName()])->all();
        DB::table('offering_pages')->whereNotNull('brand_offering_id')->whereIn('page_id', DB::table('pages')->where('website_asset_id', $site->id)->select('id'))
            ->orderBy('id')->get(['page_id', 'brand_offering_id'])
            ->each(function (object $link) use ($pageIds, $names, &$rows): void {
                $path = $pageIds[(int) $link->page_id] ?? null;
                $name = $names[(int) $link->brand_offering_id] ?? null;
                if ($path !== null && $name !== null && ! in_array($name, $rows[$path]['services'], true)) {
                    $rows[$path]['services'][] = $name;
                }
            });
        DB::table('brand_cluster_pages as m')->join('clusters as c', 'c.id', '=', 'm.cluster_id')
            ->where('m.brand_id', $brand->id)->where('m.website_asset_id', $site->id)->whereNotNull('m.page_id')
            ->orderBy('m.id')->get(['m.page_id', 'c.name'])
            ->each(function (object $link) use ($pageIds, &$rows): void {
                $path = $pageIds[(int) $link->page_id] ?? null;
                if ($path !== null && ! in_array((string) $link->name, $rows[$path]['clusters'], true)) {
                    $rows[$path]['clusters'][] = (string) $link->name;
                }
            });
    }

    /**
     * Google Ads clicks / cost per landing path of this site's host over the period (the brand's Google Ads accounts;
     * central rows when present, otherwise the asset-scoped rows — never both).
     *
     * @param  array{end: string, start: string, gsc: list<int>, ga4: list<int>}  $w
     * @return array<string, array{clicks: int, cost: float}>
     */
    private function adsLanding(DigitalAsset $site, array $w): array
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null) {
            return [];
        }
        $resources = SiteScope::resourceIds($brand, 'google_ads');
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_ads')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($resources === [] && $assets === []) {
            return [];
        }
        $base = fn (): Builder => DB::table('google_ads_landing_page_daily')->whereBetween('reporting_date', [$w['start'], $w['end']]);
        $query = $resources !== [] && $base()->whereIn('external_resource_id', $resources)->whereNull('digital_asset_id')->exists()
            ? $base()->whereIn('external_resource_id', $resources)->whereNull('digital_asset_id')
            : $base()->whereIn('digital_asset_id', $assets ?: [0]);
        $host = self::host((string) ($site->domain ?: $site->primary_url));
        $out = [];
        $query->groupBy('landing_page')->selectRaw('landing_page, sum(clicks) as clicks, sum(cost_amount) as cost')
            ->orderByDesc('clicks')->orderBy('landing_page')->limit(5000)->get()
            ->each(function (object $r) use (&$out, $host): void {
                $landing = (string) $r->landing_page;
                if ($host !== '' && str_contains($landing, '://') && self::host($landing) !== $host) {
                    return;
                }
                $path = SiteAnalysisReader::path($landing);
                $out[$path] = ['clicks' => (int) $r->clicks + ($out[$path]['clicks'] ?? 0), 'cost' => round((float) $r->cost + ($out[$path]['cost'] ?? 0.0), 2)];
            });

        return $out;
    }

    /**
     * Latest HTTP status, noindex and crawl issues (of that same fetch) per path.
     *
     * @return array<string, array{status_code: ?int, noindex: bool, issues: int, serious: int, observed_at: string}>
     */
    private function health(DigitalAsset $site): array
    {
        $out = [];
        if (! Schema::hasTable('website_http_snapshot')) {
            return $out;
        }
        $http = DB::table('website_http_snapshot as s')->joinSub($this->latest('website_http_snapshot', (int) $site->id), 'l', fn ($join) => $join->on('l.url', '=', 's.url')->on('l.observed_at', '=', 's.observed_at'))
            ->where('s.digital_asset_id', $site->id)->orderBy('s.id')->select(['s.url', 's.observed_at', 's.metadata'])->get();
        foreach ($http as $row) {
            $meta = self::json($row->metadata);
            $path = SiteAnalysisReader::path((string) $row->url);
            if (isset($out[$path]) && strcmp($out[$path]['observed_at'], (string) $row->observed_at) > 0) {
                continue;
            }
            $out[$path] = ['status_code' => is_numeric($meta['status_code'] ?? null) ? (int) $meta['status_code'] : null, 'noindex' => false, 'issues' => 0, 'serious' => 0, 'observed_at' => (string) $row->observed_at];
        }
        if (Schema::hasTable('website_metadata_snapshot')) {
            DB::table('website_metadata_snapshot as s')->joinSub($this->latest('website_metadata_snapshot', (int) $site->id), 'l', fn ($join) => $join->on('l.url', '=', 's.url')->on('l.observed_at', '=', 's.observed_at'))
                ->where('s.digital_asset_id', $site->id)->orderBy('s.id')->select(['s.url', 's.metadata'])->get()
                ->each(function (object $row) use (&$out): void {
                    $robots = mb_strtolower((string) (self::json($row->metadata)['meta_robots'] ?? ''));
                    $path = SiteAnalysisReader::path((string) $row->url);
                    if (str_contains($robots, 'noindex')) {
                        $out[$path] ??= ['status_code' => null, 'noindex' => false, 'issues' => 0, 'serious' => 0, 'observed_at' => ''];
                        $out[$path]['noindex'] = true;
                    }
                });
        }
        if (Schema::hasTable('website_crawl_issue_snapshot')) {
            $serious = "'".implode("','", self::SERIOUS)."'";
            DB::table('website_crawl_issue_snapshot as i')->joinSub($this->latest('website_http_snapshot', (int) $site->id), 'l', fn ($join) => $join->on('l.url', '=', 'i.url')->on('l.observed_at', '=', 'i.observed_at'))
                ->where('i.digital_asset_id', $site->id)->groupBy('i.url')->orderBy('i.url')
                ->selectRaw("i.url as url, count(*) as n, sum(CASE WHEN i.severity IN ({$serious}) THEN 1 ELSE 0 END) as serious")->get()
                ->each(function (object $row) use (&$out): void {
                    $path = SiteAnalysisReader::path((string) $row->url);
                    if (isset($out[$path])) {
                        $out[$path]['issues'] += (int) $row->n;
                        $out[$path]['serious'] += (int) $row->serious;
                    }
                });
        }

        return $out;
    }

    /** Latest observation per URL of a snapshot table: (url, observed_at). */
    private function latest(string $table, int $assetId): Builder
    {
        return DB::table($table)->where('digital_asset_id', $assetId)->groupBy('url')->select('url')->selectRaw('max(observed_at) as observed_at');
    }

    /**
     * The URL spellings a path may carry in Search Console / crawl rows (scheme, www, trailing slash).
     *
     * @param  PageRow  $row
     * @return list<string>
     */
    private function urlVariants(DigitalAsset $site, array $row): array
    {
        $host = self::host((string) ($site->domain ?: $site->primary_url));
        $hosts = array_values(array_unique(array_filter([$host, $host !== '' ? 'www.'.$host : null, self::host($row['url'], false)])));
        $path = $row['path'];
        $paths = $path === '/' ? ['/', ''] : [$path, $path.'/'];
        $out = [$row['url']];
        foreach ($hosts as $h) {
            foreach (['https://', 'http://'] as $scheme) {
                foreach ($paths as $p) {
                    $out[] = $scheme.$h.$p;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array{start: string, end: string, prev_start: string, prev_end: string, gsc: list<int>}  $w
     * @param  list<string>  $urls
     * @return list<array{query: string, clicks: int, impressions: int, position: ?float, prev_clicks: int}>
     */
    private function pageQueries(array $w, array $urls): array
    {
        if ($w['gsc'] === []) {
            return [];
        }
        $position = QuerySourceAggregator::positionExpression();
        $metric = "sum(clicks) as clicks, sum(impressions) as impressions, sum(({$position}) * impressions) as weighted, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as weight";
        $base = fn (string $from, string $to): Builder => DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $w['gsc'])->where('search_type', 'web')
            ->whereIn('page', $urls)->whereBetween('reporting_date', [$from, $to])->groupBy('query');
        $previous = $base($w['prev_start'], $w['prev_end'])->selectRaw('query as k, sum(clicks) as clicks')->pluck('clicks', 'k')->all();

        return $base($w['start'], $w['end'])->selectRaw('query as k, '.$metric)->orderByDesc('clicks')->orderByDesc('impressions')->orderBy('k')->limit(50)->get()
            ->map(fn (object $r): array => ['query' => (string) $r->k, 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions,
                'position' => (int) $r->weight > 0 ? round((float) $r->weighted / (int) $r->weight, 1) : null, 'prev_clicks' => (int) ($previous[$r->k] ?? 0)])->all();
    }

    /**
     * @param  array{gsc: list<int>, ga4: list<int>}  $w
     * @param  list<string>  $urls
     * @return list<array{date: string, clicks: int, impressions: int, sessions: int}>
     */
    private function pageTrend(array $w, array $urls, string $path, string $from, string $to): array
    {
        $gsc = $w['gsc'] === [] ? [] : DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $w['gsc'])->where('search_type', 'web')
            ->whereIn('page', $urls)->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')->orderBy('reporting_date')
            ->selectRaw('reporting_date as d, sum(clicks) as clicks, sum(impressions) as impressions')->get()
            ->keyBy(fn (object $r): string => substr((string) $r->d, 0, 10))->all();
        $ga4 = [];
        if ($w['ga4'] !== []) {
            $this->landingScope($w['ga4'], $path)->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date', 'landingPage')
                ->selectRaw('reporting_date as d, "landingPage" as landing, sum(sessions) as sessions')->orderBy('reporting_date')->get()
                ->each(function (object $r) use (&$ga4, $path): void {
                    if (SiteAnalysisReader::path((string) $r->landing) === $path) {
                        $day = substr((string) $r->d, 0, 10);
                        $ga4[$day] = ($ga4[$day] ?? 0) + (int) $r->sessions;
                    }
                });
        }
        $out = [];
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $date = $day->toDateString();
            $out[] = ['date' => $date, 'clicks' => (int) ($gsc[$date]->clicks ?? 0), 'impressions' => (int) ($gsc[$date]->impressions ?? 0), 'sessions' => $ga4[$date] ?? 0];
        }

        return $out;
    }

    /**
     * @param  array{start: string, end: string, ga4: list<int>}  $w
     * @return list<array{source: string, medium: string, sessions: int, key_events: float}>
     */
    private function pageChannels(array $w, string $path): array
    {
        if ($w['ga4'] === []) {
            return [];
        }
        $out = [];
        $this->landingScope($w['ga4'], $path)->whereBetween('reporting_date', [$w['start'], $w['end']])
            ->groupBy('landingPage', 'sessionSource', 'sessionMedium')
            ->selectRaw('"landingPage" as landing, "sessionSource" as source, "sessionMedium" as medium, sum(sessions) as sessions, sum("keyEvents") as key_events')
            ->orderBy('landingPage')->get()
            ->each(function (object $r) use (&$out, $path): void {
                if (SiteAnalysisReader::path((string) $r->landing) !== $path) {
                    return;
                }
                $key = $r->source.' / '.$r->medium;
                $out[$key] = ['source' => (string) $r->source, 'medium' => (string) $r->medium,
                    'sessions' => (int) $r->sessions + ($out[$key]['sessions'] ?? 0), 'key_events' => round((float) $r->key_events + ($out[$key]['key_events'] ?? 0.0), 1)];
            });
        $out = array_values($out);
        usort($out, fn (array $a, array $b): int => [$b['sessions'], $b['key_events'], $a['source']] <=> [$a['sessions'], $a['key_events'], $b['source']]);

        return $out;
    }

    /**
     * GA4 landing rows of one path (exact, with trailing slash, or with a query string).
     *
     * @param  list<int>  $resources
     */
    private function landingScope(array $resources, string $path): Builder
    {
        $base = $path === '/' ? '/' : $path;
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $base);

        return DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $resources)
            ->where(fn (Builder $q) => $q->whereIn('landingPage', array_values(array_unique([$base, rtrim($base, '/').'/'])))
                ->orWhereRaw('"landingPage" LIKE ? ESCAPE \'!\'', [$escaped.'?%'])
                ->orWhereRaw('"landingPage" LIKE ? ESCAPE \'!\'', [rtrim($escaped, '/').'/?%']));
    }

    /**
     * Crawl issues of the latest fetch of the page and internal links in / out of that fetch.
     *
     * @param  list<string>  $urls
     * @return array{issues: list<array{code: string, severity: string, message: string}>, links: ?array{in: int, out: int}}
     */
    private function pageHealth(DigitalAsset $site, array $urls): array
    {
        $issues = [];
        $links = null;
        if (! Schema::hasTable('website_http_snapshot')) {
            return ['issues' => $issues, 'links' => $links];
        }
        $latest = fn (): Builder => $this->latest('website_http_snapshot', (int) $site->id);
        if (Schema::hasTable('website_crawl_issue_snapshot')) {
            $issues = DB::table('website_crawl_issue_snapshot as i')->joinSub($latest(), 'l', fn ($join) => $join->on('l.url', '=', 'i.url')->on('l.observed_at', '=', 'i.observed_at'))
                ->where('i.digital_asset_id', $site->id)->whereIn('i.url', $urls)->orderBy('i.severity')->orderBy('i.issue_code')->limit(50)
                ->get(['i.issue_code', 'i.severity', 'i.message'])
                ->map(fn (object $r): array => ['code' => (string) $r->issue_code, 'severity' => (string) $r->severity, 'message' => (string) $r->message])->all();
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];
            usort($issues, fn (array $a, array $b): int => [$rank[$a['severity']] ?? 9, $a['code']] <=> [$rank[$b['severity']] ?? 9, $b['code']]);
        }
        if (Schema::hasTable('website_link_edge') && DB::table('website_link_edge')->where('digital_asset_id', $site->id)->exists()) {
            $edges = fn (): Builder => DB::table('website_link_edge as e')->joinSub($latest(), 'l', fn ($join) => $join->on('l.url', '=', 'e.source_url')->on('l.observed_at', '=', 'e.observed_at'))
                ->where('e.digital_asset_id', $site->id)->where('e.is_internal', true);
            $links = [
                'out' => (int) $edges()->whereIn('e.source_url', $urls)->count(),
                'in' => (int) $edges()->whereIn('e.normalized_target_url', $urls)->whereNotIn('e.source_url', $urls)->distinct()->count('e.source_url'),
            ];
        }

        return ['issues' => $issues, 'links' => $links];
    }

    /**
     * @param  list<int>  $resources
     * @return array<string, array{clicks: int, impressions: int}>
     */
    private function dailyGsc(array $resources, string $from, string $to): array
    {
        if ($resources === []) {
            return [];
        }
        $daily = fn (string $table): array => DB::table($table)->whereIn('external_resource_id', $resources)->where('search_type', 'web')
            ->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')->orderBy('reporting_date')
            ->selectRaw('reporting_date as d, sum(clicks) as clicks, sum(impressions) as impressions')->get()
            ->mapWithKeys(fn (object $r): array => [substr((string) $r->d, 0, 10) => ['clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions]])->all();
        $property = $daily('gsc_property_daily');

        return $property !== [] ? $property : $daily('gsc_query_page_daily');
    }

    /**
     * @param  list<int>  $resources
     * @return array<string, array{sessions: int, key_events: float}>
     */
    private function dailyGa4(array $resources, string $from, string $to): array
    {
        if ($resources === []) {
            return [];
        }
        $daily = fn (string $table): array => DB::table($table)->whereIn('external_resource_id', $resources)
            ->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')->orderBy('reporting_date')
            ->selectRaw('reporting_date as d, sum(sessions) as sessions, sum("keyEvents") as key_events')->get()
            ->mapWithKeys(fn (object $r): array => [substr((string) $r->d, 0, 10) => ['sessions' => (int) $r->sessions, 'key_events' => (float) $r->key_events]])->all();
        $property = $daily('ga4_property_daily');

        return $property !== [] ? $property : $daily('ga4_landing_source_daily');
    }

    /** Case-folded for search (Turkish İ / I first, then dotless ı and i compared alike). */
    private static function fold(string $value): string
    {
        return str_replace('ı', 'i', mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $value)));
    }

    private static function host(string $url, bool $stripWww = true): string
    {
        $host = mb_strtolower((string) (str_contains($url, '://') ? parse_url($url, PHP_URL_HOST) : strtok($url, '/')));

        return $stripWww && str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return array<string, mixed> */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
