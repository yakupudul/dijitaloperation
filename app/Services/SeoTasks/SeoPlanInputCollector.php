<?php

namespace App\Services\SeoTasks;

use App\Models\BrandOffering;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ServicePageAssignment;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step A — gather the plan package from stored data only. No provider or HTTP calls.
 *
 * Output shape is a plain array so the rule engine and tests stay independent of Eloquent.
 */
final class SeoPlanInputCollector
{
    public function __construct(
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
        private readonly SeoStoredHtmlReader $html,
    ) {}

    private int $excludedNonDocuments = 0;

    /** @return array<string, mixed> */
    public function collect(DigitalAsset $site, ?CarbonImmutable $end = null): array
    {
        $site->loadMissing('brand.customer');
        $end = ($end ?? CarbonImmutable::now())->startOfDay();
        $gscDays = SeoTaskConfig::int('window.gsc_days', 90);
        $ga4Days = SeoTaskConfig::int('window.ga4_days', 90);
        $primaryUrl = $site->primary_url ?: ('https://'.$site->domain);

        $this->excludedNonDocuments = 0;
        $pages = $this->pages($site);
        $gsc = $this->gsc($site, $end->subDays($gscDays), $end);
        $offerings = $this->offerings($site);
        $homeKey = SeoText::urlKey($primaryUrl);
        $htmlStats = $this->readStoredHtml($site, $pages, $gsc['rows'], $homeKey);

        return [
            'site' => [
                'id' => $site->id,
                'brand_id' => $site->brand_id,
                'customer_id' => $site->brand?->customer_id,
                'brand_name' => $site->brand?->name,
                'domain' => $site->domain,
                'primary_url' => $primaryUrl,
                'origin' => SeoText::origin($primaryUrl),
                'home_key' => $homeKey,
                'languages' => is_array($site->languages) ? $site->languages : [],
            ],
            'period' => [
                'start' => $end->subDays($gscDays)->toDateString(),
                'end' => $end->toDateString(),
                'days' => $gscDays,
            ],
            'gsc' => $gsc,
            'pages' => $pages,
            'findings' => $this->findings($site),
            'offerings' => $offerings,
            'service_areas' => $this->serviceAreas($site),
            'service_area_rows' => $site->brand?->serviceAreas()->where('status', 'active')->get(['country_code', 'city_name', 'district_name'])->map(fn ($area): array => $area->only(['country_code', 'city_name', 'district_name']))->all() ?? [],
            'ga4' => $this->ga4($site, $end->subDays($ga4Days), $end),
            'robots' => $this->robots($site),
            'assignments' => $this->assignments($site),
            'html' => $htmlStats + ['excluded_non_documents' => $this->excludedNonDocuments],
            // Faz 2: already collected, previously unused sources. Missing data stays "available: false".
            'traffic' => $this->pageTraffic($site, $end),
            'inspections' => $this->inspections($site),
            'sitemaps' => $this->sitemaps($site),
            'links' => $this->internalLinks($site),
            'performance' => $this->performance($site),
            'gbp' => $this->businessProfile($site),
            // Faz 2b: latest our-page-vs-competitors comparison per service (area SERP + public fetch).
            'competitor_gaps' => $this->competitorGaps($site),
        ];
    }

    /**
     * @return array<int, array{our_url: string, our_rank: ?int, gaps: list<array{key: string, text: string}>, competitors: list<array<string, mixed>>, compared_at: string}>
     */
    public function competitorGaps(DigitalAsset $site): array
    {
        if ($site->brand_id === null || ! Schema::hasTable('demand_service_comparisons')) {
            return [];
        }
        $host = mb_strtolower((string) parse_url(SeoText::origin((string) ($site->primary_url ?: 'https://'.$site->domain)), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $out = [];
        foreach (DB::table('demand_service_comparisons')->where('brand_id', $site->brand_id)->where('compared_at', '>=', now()->subDays(60))->get() as $row) {
            $rowHost = preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) $row->our_url, PHP_URL_HOST))) ?? '';
            if ($host !== '' && $rowHost !== $host) {
                continue;
            }
            $out[(int) $row->brand_offering_id] = [
                'our_url' => (string) $row->our_url,
                'our_rank' => $row->our_rank !== null ? (int) $row->our_rank : null,
                'gaps' => (array) json_decode((string) $row->gaps, true),
                'competitors' => (array) json_decode((string) $row->competitors, true),
                'compared_at' => (string) $row->compared_at,
            ];
        }

        return $out;
    }

    /**
     * Scope a GSC data-pool query to this website: central rows (resource + property) or legacy
     * per-asset rows.
     */
    public function scopeGsc(Builder $query, DigitalAsset $site, string $table): Builder
    {
        $binding = $this->gscBindings->resolve((string) $site->id);
        if ($binding->isReal() && $binding->externalResourceId !== null && filled($binding->siteUrl)) {
            $query->where(function ($scope) use ($binding, $site): void {
                $scope->where(function ($bound) use ($binding): void {
                    $bound->where('external_resource_id', $binding->externalResourceId)->where('site_url', $binding->siteUrl);
                })->orWhere('digital_asset_id', $site->id);
            });
        } else {
            $query->where('digital_asset_id', $site->id);
        }
        if (Schema::hasColumn($table, 'search_type')) {
            $query->where(fn ($scope) => $scope->whereNull('search_type')->orWhere('search_type', 'web'));
        }

        return $query;
    }

    /**
     * Per-page clicks/impressions: last 28 days vs the 28 before (content decay) and 90-day
     * impressions (pruning). `history_days` tells whether 90 days of data exist at all.
     *
     * @return array{available: bool, history_days: int, pages: array<string, array<string, mixed>>}
     */
    public function pageTraffic(DigitalAsset $site, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('gsc_page_daily')) {
            return ['available' => false, 'history_days' => 0, 'pages' => []];
        }
        $decay = SeoTaskConfig::int('decay.window_days', 28);
        $curStart = $end->subDays($decay)->toDateString();
        $prevStart = $end->subDays($decay * 2)->toDateString();
        $start90 = $end->subDays(90)->toDateString();
        $from = min($prevStart, $start90);

        $base = fn () => $this->scopeGsc(DB::table('gsc_page_daily'), $site, 'gsc_page_daily');
        $first = $base()->min('reporting_date');
        $rows = $base()
            ->whereBetween('reporting_date', [$from, $end->toDateString()])
            ->selectRaw('page,
                sum(case when reporting_date >= ? then clicks else 0 end) as clicks_cur,
                sum(case when reporting_date >= ? and reporting_date < ? then clicks else 0 end) as clicks_prev,
                sum(case when reporting_date >= ? then impressions else 0 end) as impr_cur,
                sum(case when reporting_date >= ? and reporting_date < ? then impressions else 0 end) as impr_prev,
                sum(case when reporting_date >= ? then impressions else 0 end) as impr_90', [$curStart, $prevStart, $curStart, $curStart, $prevStart, $curStart, $start90])
            ->groupBy('page')
            ->get();

        $pages = [];
        foreach ($rows as $row) {
            $key = SeoText::urlKey((string) $row->page);
            $entry = $pages[$key] ?? ['url' => (string) $row->page, 'clicks_cur' => 0, 'clicks_prev' => 0, 'impr_cur' => 0, 'impr_prev' => 0, 'impr_90' => 0];
            foreach (['clicks_cur', 'clicks_prev', 'impr_cur', 'impr_prev', 'impr_90'] as $field) {
                $entry[$field] += (int) $row->{$field};
            }
            $pages[$key] = $entry;
        }
        $historyDays = $first !== null ? (int) CarbonImmutable::parse((string) $first)->diffInDays($end) : 0;

        return ['available' => $pages !== [], 'history_days' => $historyDays, 'pages' => $pages];
    }

    /**
     * Latest Search Console URL inspection per page.
     *
     * @return array<string, array{url: string, verdict: ?string, coverage_state: ?string, google_canonical: ?string, user_canonical: ?string, inspected_at: ?string}>
     */
    public function inspections(DigitalAsset $site): array
    {
        if (! Schema::hasTable('gsc_url_inspection_snapshot')) {
            return [];
        }
        $out = [];
        $this->scopeGsc(DB::table('gsc_url_inspection_snapshot'), $site, 'gsc_url_inspection_snapshot')
            ->orderByDesc('inspected_at')
            ->limit(2000)
            ->get(['page', 'inspected_at', 'metadata'])
            ->each(function (object $row) use (&$out): void {
                $key = SeoText::urlKey((string) $row->page);
                if (isset($out[$key])) {
                    return; // newest first
                }
                $meta = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: []) : (array) $row->metadata;
                $out[$key] = [
                    'url' => (string) $row->page,
                    'verdict' => is_string($meta['verdict'] ?? null) ? $meta['verdict'] : null,
                    'coverage_state' => is_string($meta['coverage_state'] ?? null) ? $meta['coverage_state'] : null,
                    'google_canonical' => is_string($meta['google_canonical'] ?? null) ? $meta['google_canonical'] : null,
                    'user_canonical' => is_string($meta['user_canonical'] ?? null) ? $meta['user_canonical'] : null,
                    'inspected_at' => (string) $row->inspected_at,
                ];
            });

        return $out;
    }

    /** @return list<array{path: string, errors: int, warnings: int, is_pending: bool, last_downloaded: ?string}> */
    private function sitemaps(DigitalAsset $site): array
    {
        if (! Schema::hasTable('gsc_sitemap_snapshot')) {
            return [];
        }
        $out = [];
        $this->scopeGsc(DB::table('gsc_sitemap_snapshot'), $site, 'gsc_sitemap_snapshot')
            ->orderByDesc('retrieved_at')
            ->limit(500)
            ->get(['sitemap_path', 'metadata'])
            ->each(function (object $row) use (&$out): void {
                $path = (string) $row->sitemap_path;
                if (isset($out[$path])) {
                    return;
                }
                $meta = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: []) : (array) $row->metadata;
                $out[$path] = [
                    'path' => $path,
                    'errors' => (int) ($meta['errors'] ?? 0),
                    'warnings' => (int) ($meta['warnings'] ?? 0),
                    'is_pending' => (bool) ($meta['is_pending'] ?? false),
                    'last_downloaded' => is_string($meta['last_downloaded'] ?? null) ? $meta['last_downloaded'] : null,
                ];
            });

        return array_values($out);
    }

    /**
     * Internal link graph from the latest crawl of each source page: who links to whom.
     *
     * @return array{available: bool, inlinks: array<string, list<string>>, outlinks: array<string, list<string>>}
     */
    private function internalLinks(DigitalAsset $site): array
    {
        if (! Schema::hasTable('website_link_edge')) {
            return ['available' => false, 'inlinks' => [], 'outlinks' => []];
        }
        $latest = DB::table('website_link_edge')
            ->where('digital_asset_id', $site->id)
            ->groupBy('source_url')
            ->selectRaw('source_url, max(observed_at) as observed_at');
        $inlinks = [];
        $outlinks = [];
        DB::table('website_link_edge as e')
            ->joinSub($latest, 'l', fn ($join) => $join->on('l.source_url', '=', 'e.source_url')->on('l.observed_at', '=', 'e.observed_at'))
            ->where('e.digital_asset_id', $site->id)
            ->where('e.is_internal', true)
            ->select(['e.id', 'e.source_url', 'e.normalized_target_url', 'e.target_url'])
            ->orderBy('e.id')
            ->chunk(5000, function ($rows) use (&$inlinks, &$outlinks): void {
                foreach ($rows as $row) {
                    $from = SeoText::urlKey((string) $row->source_url);
                    $to = SeoText::urlKey((string) ($row->normalized_target_url ?: $row->target_url));
                    if ($from === $to) {
                        continue;
                    }
                    $inlinks[$to][$from] = true;
                    $outlinks[$from][$to] = true;
                }
            });

        return [
            'available' => $outlinks !== [],
            'inlinks' => array_map('array_keys', $inlinks),
            'outlinks' => array_map('array_keys', $outlinks),
        ];
    }

    /** @return array<string, array{url: string, lcp_ms: ?int, strategy: ?string, observed_at: string}> latest lab measurement per page */
    public function performance(DigitalAsset $site): array
    {
        if (! Schema::hasTable('website_performance_measurement')) {
            return [];
        }
        $out = [];
        DB::table('website_performance_measurement')
            ->where('digital_asset_id', $site->id)
            ->orderByDesc('observed_at')
            ->limit(500)
            ->get(['url', 'strategy', 'observed_at', 'metadata'])
            ->each(function (object $row) use (&$out): void {
                $key = SeoText::urlKey((string) $row->url);
                if (isset($out[$key])) {
                    return;
                }
                $meta = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: []) : (array) $row->metadata;
                $out[$key] = [
                    'url' => (string) $row->url,
                    'lcp_ms' => is_numeric($meta['lcp_ms'] ?? null) ? (int) round((float) $meta['lcp_ms']) : null,
                    'strategy' => $row->strategy !== null ? (string) $row->strategy : null,
                    'observed_at' => (string) $row->observed_at,
                ];
            });

        return $out;
    }

    /**
     * Latest Business Profile snapshot of the brand's connected location (for site ↔ profile
     * consistency). Null when the brand has no connected profile or nothing was collected.
     *
     * @return array{title: ?string, website_uri: ?string, phones: list<string>, captured_at: ?string}|null
     */
    private function businessProfile(DigitalAsset $site): ?array
    {
        if (! Schema::hasTable('gbp_location_snapshots') || $site->brand_id === null) {
            return null;
        }
        $resourceIds = DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->where('a.brand_id', $site->brand_id)
            ->where('b.capability', 'google_business_profile')
            ->where('b.status', 'active')
            ->pluck('b.external_resource_id')
            ->all();
        if (count($resourceIds) !== 1) {
            return null; // none, or several locations: no single profile to compare against
        }
        $row = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceIds[0])->orderByDesc('captured_at')->first();
        if ($row === null) {
            return null;
        }
        $phones = [];
        $decoded = is_string($row->phone_numbers) ? (json_decode($row->phone_numbers, true) ?: []) : (array) $row->phone_numbers;
        array_walk_recursive($decoded, function ($value) use (&$phones): void {
            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
            if (strlen($digits) >= 7) {
                $phones[] = substr($digits, -10);
            }
        });

        return [
            'title' => $row->title !== null ? (string) $row->title : null,
            'website_uri' => $row->website_uri !== null ? (string) $row->website_uri : null,
            'phones' => array_values(array_unique($phones)),
            'captured_at' => $row->captured_at !== null ? (string) $row->captured_at : null,
        ];
    }

    /**
     * Enrich page rows from already stored HTML snapshots (no HTTP). Home first, then pages by
     * Search Console impressions, then the rest, bounded by config.
     *
     * @param  array<string, array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $gscRows
     * @return array{read: int, candidates: int, limit: int}
     */
    private function readStoredHtml(DigitalAsset $site, array &$pages, array $gscRows, string $homeKey): array
    {
        $limit = SeoTaskConfig::int('html.max_pages', 150);
        $excerptPages = SeoTaskConfig::int('html.excerpt_pages', 8);
        $excerptChars = SeoTaskConfig::int('html.excerpt_chars', 1500);

        $impressions = [];
        foreach ($gscRows as $row) {
            $impressions[$row['url_key']] = ($impressions[$row['url_key']] ?? 0) + (int) $row['impressions'];
        }
        $keys = array_keys(array_filter($pages, static fn (array $p): bool => $p['observed']));
        usort($keys, static function (string $a, string $b) use ($impressions, $homeKey): int {
            if ($a === $homeKey || $b === $homeKey) {
                return $a === $homeKey ? -1 : 1;
            }

            return ($impressions[$b] ?? 0) <=> ($impressions[$a] ?? 0);
        });
        $selected = array_slice($keys, 0, $limit);
        $profiles = WebsitePageProfile::query()
            ->whereIn('id', array_map(static fn (string $key): int => (int) $pages[$key]['profile_id'], $selected))
            ->get()
            ->keyBy('id');

        $read = 0;
        foreach ($selected as $index => $key) {
            $profile = $profiles->get((int) $pages[$key]['profile_id']);
            if ($profile === null) {
                continue;
            }
            $facts = $this->html->inspect($site, $profile, $index < $excerptPages ? $excerptChars : 0);
            if ($facts === null) {
                continue;
            }
            $read++;
            $pages[$key] = array_merge($pages[$key], $facts, ['html_read' => true]);
            if ($pages[$key]['h1'] === null && $facts['h1_texts'] !== []) {
                $pages[$key]['h1'] = $facts['h1_texts'][0];
            }
            $pages[$key]['structured_types'] = array_values(array_unique(array_merge($pages[$key]['structured_types'], $facts['jsonld_types'])));
        }

        return ['read' => $read, 'candidates' => count($keys), 'limit' => $limit];
    }

    /**
     * @return array{available: bool, reason: ?string, rows: list<array<string, mixed>>, query_count: int, page_count: int, truncated: bool}
     */
    public function gsc(DigitalAsset $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $empty = ['available' => false, 'reason' => null, 'rows' => [], 'query_count' => 0, 'page_count' => 0, 'truncated' => false];
        if (! Schema::hasTable('gsc_query_page_daily')) {
            return $empty + ['reason' => 'table_missing'];
        }

        $binding = $this->gscBindings->resolve((string) $site->id);
        $query = DB::table('gsc_query_page_daily')
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->where('impressions', '>', 0);

        if ($binding->isReal() && $binding->externalResourceId !== null && filled($binding->siteUrl)) {
            $query->where(function ($scope) use ($binding, $site): void {
                $scope->where(function ($bound) use ($binding): void {
                    $bound->where('external_resource_id', $binding->externalResourceId)
                        ->where('site_url', $binding->siteUrl);
                })->orWhere('digital_asset_id', $site->id);
            });
        } else {
            $query->where('digital_asset_id', $site->id);
        }

        if (Schema::hasColumn('gsc_query_page_daily', 'search_type')) {
            $query->where(function ($scope): void {
                $scope->whereNull('search_type')->orWhere('search_type', 'web');
            });
        }

        $aggregates = [];
        $query->orderBy('id')->select(['query', 'page', 'clicks', 'impressions', 'metadata'])
            ->chunk(2000, function ($rows) use (&$aggregates): void {
                foreach ($rows as $row) {
                    $text = trim((string) $row->query);
                    $page = trim((string) $row->page);
                    if ($text === '' || $page === '') {
                        continue;
                    }
                    $key = mb_strtolower($text).'|'.SeoText::urlKey($page);
                    $impressions = (int) $row->impressions;
                    $position = $this->metadataFloat($row->metadata, 'provider_average_position');
                    $entry = $aggregates[$key] ?? [
                        'query' => $text,
                        'page' => $page,
                        'url_key' => SeoText::urlKey($page),
                        'clicks' => 0,
                        'impressions' => 0,
                        'position_numerator' => 0.0,
                        'position_impressions' => 0,
                    ];
                    $entry['clicks'] += (int) $row->clicks;
                    $entry['impressions'] += $impressions;
                    if ($position !== null && $impressions > 0) {
                        $entry['position_numerator'] += $position * $impressions;
                        $entry['position_impressions'] += $impressions;
                    }
                    $aggregates[$key] = $entry;
                }
            });

        $rows = [];
        foreach ($aggregates as $entry) {
            $entry['position'] = $entry['position_impressions'] > 0
                ? round($entry['position_numerator'] / $entry['position_impressions'], 2)
                : null;
            unset($entry['position_numerator'], $entry['position_impressions']);
            $rows[] = $entry;
        }
        usort($rows, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $truncated = count($rows) > 5000;
        $rows = array_slice($rows, 0, 5000);

        $queries = [];
        $pages = [];
        foreach ($rows as $row) {
            $queries[mb_strtolower($row['query'])] = true;
            $pages[$row['url_key']] = true;
        }

        return [
            'available' => $rows !== [],
            'reason' => $rows === [] ? ($binding->isReal() ? 'no_rows_in_window' : ($binding->reason ?? 'gsc_not_bound')) : null,
            'rows' => $rows,
            'query_count' => count($queries),
            'page_count' => count($pages),
            'truncated' => $truncated,
        ];
    }

    /** @return array<string, array<string, mixed>> keyed by url key */
    private function pages(DigitalAsset $site): array
    {
        $pages = [];
        WebsitePageProfile::query()
            ->where('website_asset_id', $site->id)
            ->orderBy('id')
            ->chunk(500, function ($profiles) use (&$pages): void {
                foreach ($profiles as $profile) {
                    $states = is_array($profile->source_states) ? $profile->source_states : [];
                    $web = is_array($states['website'] ?? null) ? $states['website'] : [];
                    $wp = is_array($states['wordpress'] ?? null) ? $states['wordpress'] : [];
                    $url = (string) ($web['url'] ?? $profile->preferred_url);
                    $contentType = data_get($web, 'http.content_type');
                    if ($url === '' || ! SeoText::isDocumentUrl($url, is_string($contentType) ? $contentType : null)) {
                        $this->excludedNonDocuments++;

                        continue;
                    }
                    $status = data_get($web, 'http.status_code');
                    $robots = data_get($web, 'document_head.robots');
                    $robots = is_string($robots) ? mb_strtolower($robots) : (is_array($robots) ? mb_strtolower(implode(',', $robots)) : null);
                    $wpSeo = is_array($wp['seo'] ?? null) ? $wp['seo'] : [];
                    $title = data_get($web, 'document_head.title') ?? ($wpSeo['title'] ?? null);
                    $meta = data_get($web, 'document_head.meta_description') ?? ($wpSeo['meta_description'] ?? null);
                    $canonicals = data_get($web, 'document_head.canonical_hrefs');
                    $canonicals = is_array($canonicals) ? array_values(array_filter($canonicals, 'is_string')) : [];
                    if ($canonicals === [] && filled($wpSeo['canonical_url'] ?? null)) {
                        $canonicals = [(string) $wpSeo['canonical_url']];
                    }
                    $issues = data_get($web, 'crawl_issues');
                    $types = data_get($web, 'structured_data.types');
                    $wpStatus = data_get($wp, 'object.status');
                    $pages[SeoText::urlKey($url)] = [
                        'profile_id' => $profile->id,
                        'url' => $url,
                        'url_key' => SeoText::urlKey($url),
                        'path' => SeoText::urlPath($url),
                        'observed' => $web !== [],
                        // Missing ≠ zero: head facts are only judged when the head was actually observed.
                        'head_observed' => is_array(data_get($web, 'document_head')) || $wpSeo !== [],
                        'title_present' => is_bool(data_get($web, 'document_head.title_present'))
                            ? (bool) data_get($web, 'document_head.title_present')
                            : (filled($wpSeo['title'] ?? null) ? true : null),
                        'title' => is_string($title) ? trim($title) : null,
                        'meta_description' => is_string($meta) ? trim($meta) : null,
                        'h1' => is_string(data_get($web, 'headings.h1')) ? trim((string) data_get($web, 'headings.h1')) : null,
                        'h1_present' => data_get($web, 'headings.h1_present'),
                        'word_count' => is_numeric(data_get($web, 'content.word_count')) ? (int) data_get($web, 'content.word_count') : null,
                        'status_code' => is_numeric($status) ? (int) $status : null,
                        'final_url' => data_get($web, 'http.final_url'),
                        'redirect_count' => is_numeric(data_get($web, 'http.redirect_count')) ? (int) data_get($web, 'http.redirect_count') : null,
                        'robots' => $robots,
                        'noindex' => $robots !== null && str_contains($robots, 'noindex'),
                        'canonical_hrefs' => $canonicals,
                        'structured_types' => is_array($types) ? array_values(array_filter($types, 'is_string')) : [],
                        'crawl_issues' => is_array($issues) ? array_values(array_filter($issues, 'is_array')) : [],
                        'internal_links' => is_numeric(data_get($web, 'links.internal')) ? (int) data_get($web, 'links.internal') : null,
                        'cms_type' => data_get($wp, 'object.type'),
                        'cms_status' => is_string($wpStatus) ? $wpStatus : null,
                        'last_observed_at' => $profile->last_observed_at?->toIso8601String(),
                        'html_read' => false,
                        'title_count' => null,
                        'description_count' => null,
                        'h1_count' => null,
                        'h1_texts' => [],
                        'images_total' => null,
                        'images_missing_alt' => null,
                        'jsonld_types' => [],
                        'same_as' => [],
                        'text_excerpt' => '',
                        'lead_words' => null,
                        'tel_numbers' => [],
                    ];
                }
            });

        return $pages;
    }

    /** @return list<array<string, mixed>> */
    private function findings(DigitalAsset $site): array
    {
        return Finding::query()
            ->where('digital_asset_id', $site->id)
            ->whereIn('status', [Finding::STATUS_OPEN, Finding::STATUS_ACKNOWLEDGED])
            ->where(function ($scope): void {
                $scope->where('source_module', 'website-diagnosis')
                    ->orWhere('source_module', 'website')
                    ->orWhere('rule_id', 'like', 'website:%');
            })
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get(['id', 'fingerprint', 'rule_id', 'category', 'severity', 'title', 'summary', 'subject_kind', 'subject_id', 'last_seen_at'])
            ->map(static fn (Finding $finding): array => [
                'id' => $finding->id,
                'fingerprint' => $finding->fingerprint,
                'rule_id' => $finding->rule_id,
                'category' => $finding->category,
                'severity' => mb_strtolower((string) $finding->severity),
                'title' => $finding->title,
                'summary' => $finding->summary,
                'subject_id' => $finding->subject_id,
                'last_seen_at' => $finding->last_seen_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function offerings(DigitalAsset $site): array
    {
        if ($site->brand_id === null) {
            return [];
        }

        $offerings = BrandOffering::query()
            ->with(['names', 'primaryName', 'catalogItem.names', 'catalogItem.primaryName', 'catalogItem.matchingKeywords'])
            ->where('brand_id', $site->brand_id)
            ->where('status', 'active')
            ->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority_rank')
            ->orderBy('id')
            ->get();

        $catalogIds = $offerings->pluck('service_catalog_item_id')->filter()->unique()->values()->all();
        $queriesByService = [];
        if ($catalogIds !== []) {
            BrandQueryPortfolioItem::query()
                ->with(['libraryItem', 'services'])
                ->where('brand_id', $site->brand_id)
                ->where('status', 'active')
                ->whereHas('services', fn ($q) => $q->whereIn('service_catalog_items.id', $catalogIds))
                ->limit(3000)
                ->get()
                ->each(function (BrandQueryPortfolioItem $item) use (&$queriesByService, $catalogIds): void {
                    $text = trim($item->effectiveQueryText());
                    if ($text === '') {
                        return;
                    }
                    foreach ($item->services as $service) {
                        if (in_array($service->id, $catalogIds, true)) {
                            $queriesByService[$service->id][mb_strtolower($text)] = $text;
                        }
                    }
                });
        }

        return $offerings->map(function (BrandOffering $offering) use ($queriesByService): array {
            $names = [];
            foreach ($offering->names as $name) {
                if ($name->is_active && filled($name->raw_label)) {
                    $names[] = (string) $name->raw_label;
                }
            }
            $catalog = $offering->catalogItem;
            if ($catalog !== null) {
                foreach ($catalog->names as $name) {
                    if ($name->is_active && filled($name->raw_label)) {
                        $names[] = (string) $name->raw_label;
                    }
                }
            }
            $names = array_values(array_unique(array_filter($names)));
            $primary = $offering->primaryName?->raw_label
                ?? $catalog?->primaryName?->raw_label
                ?? ($names[0] ?? ('Hizmet #'.$offering->id));
            $keywords = $catalog?->matchingKeywords->pluck('label')->filter()->values()->all() ?? [];

            return [
                'id' => $offering->id,
                'catalog_item_id' => $offering->service_catalog_item_id,
                'name' => (string) $primary,
                'names' => $names,
                'keywords' => array_values(array_unique(array_map('strval', $keywords))),
                'is_priority' => (bool) ($offering->is_priority || $offering->priority_rank !== null),
                'priority_rank' => $offering->priority_rank,
                'queries' => array_values($queriesByService[$offering->service_catalog_item_id] ?? []),
            ];
        })->values()->all();
    }

    /** @return list<string> */
    private function serviceAreas(DigitalAsset $site): array
    {
        $brand = $site->brand;
        if ($brand === null || ! method_exists($brand, 'serviceAreas')) {
            return [];
        }
        $areas = [];
        foreach ($brand->serviceAreas()->where('status', 'active')->get() as $area) {
            foreach ([$area->district_name, $area->city_name] as $name) {
                if (filled($name)) {
                    $areas[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($areas));
    }

    /** Scope a GA4 data-pool query to the property bound to this website, or legacy per-asset rows. */
    public function scopeGa4(Builder $query, DigitalAsset $site): Builder
    {
        $binding = $this->ga4Bindings->resolve((string) $site->id);
        if ($binding->isReal() && $binding->externalResourceId !== null) {
            return $query->where(function ($scope) use ($binding, $site): void {
                $scope->where('external_resource_id', $binding->externalResourceId)->orWhere('digital_asset_id', $site->id);
            });
        }

        return $query->where('digital_asset_id', $site->id);
    }

    /** @return array{available: bool, landing: array<string, array<string, int>>} */
    public function ga4(DigitalAsset $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('ga4_landing_page_daily')) {
            return ['available' => false, 'landing' => []];
        }
        $query = $this->scopeGa4(DB::table('ga4_landing_page_daily'), $site)
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()]);
        $hasKeyEvents = Schema::hasColumn('ga4_landing_page_daily', 'keyEvents');
        $columns = ['landingPage', 'sessions', 'engagedSessions'];
        if ($hasKeyEvents) {
            $columns[] = 'keyEvents';
        }
        $origin = SeoText::origin($site->primary_url ?: ('https://'.$site->domain));
        $landing = [];
        $query->orderBy('id')->select($columns)->chunk(2000, function ($rows) use (&$landing, $origin, $hasKeyEvents): void {
            foreach ($rows as $row) {
                $path = trim((string) $row->landingPage);
                if ($path === '' || $path === '(not set)') {
                    continue;
                }
                $url = str_starts_with($path, 'http') ? $path : $origin.(str_starts_with($path, '/') ? '' : '/').$path;
                $key = SeoText::urlKey($url);
                $entry = $landing[$key] ?? ['sessions' => 0, 'engaged_sessions' => 0, 'key_events' => 0];
                $entry['sessions'] += (int) $row->sessions;
                $entry['engaged_sessions'] += (int) $row->engagedSessions;
                $entry['key_events'] += $hasKeyEvents ? (int) ($row->keyEvents ?? 0) : 0;
                $landing[$key] = $entry;
            }
        });

        return ['available' => $landing !== [], 'landing' => $landing];
    }

    /** @return array{available: bool, body: ?string, observed_at: ?string} */
    private function robots(DigitalAsset $site): array
    {
        $evidence = Evidence::query()
            ->where('digital_asset_id', $site->id)
            ->where('type', 'robots')
            ->latest('observed_at')
            ->latest('id')
            ->first();
        $body = $evidence?->payload['body'] ?? null;

        return [
            'available' => is_string($body) && $body !== '',
            'body' => is_string($body) ? $body : null,
            'observed_at' => $evidence?->observed_at?->toIso8601String(),
        ];
    }

    /** @return array<int, array<string, mixed>> keyed by brand_offering_id */
    private function assignments(DigitalAsset $site): array
    {
        return ServicePageAssignment::query()
            ->where('digital_asset_id', $site->id)
            ->get()
            ->mapWithKeys(static fn (ServicePageAssignment $assignment): array => [
                (int) $assignment->brand_offering_id => [
                    'id' => $assignment->id,
                    'page_url' => $assignment->page_url,
                    'url_key' => $assignment->page_url !== null ? SeoText::urlKey($assignment->page_url) : null,
                    'status' => $assignment->status,
                    'decision_source' => $assignment->decision_source,
                    'score' => $assignment->score,
                ],
            ])
            ->all();
    }

    private function metadataFloat(mixed $metadata, string $key): ?float
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($metadata)) {
            return null;
        }
        $value = $metadata[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
