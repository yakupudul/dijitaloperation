<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandQueryPortfolioItem;
use App\Models\CoreAssetBinding;
use App\Services\Brain\Clustering\QueryIntent;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Services\SearchDemand\QueryExclusionService;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the brand query hub (brand_demand_queries): every query the brand sees, from stored data only (no provider
 * call, no AI, no paid call) — Search Console queries per website, Google Ads search terms, Business Profile search
 * keywords over the window, plus the brand's query portfolio (library queries with stored DataForSEO volume),
 * competitor queries and area SERP keywords. Rows are merged by the folded query (SeoText::fold).
 *
 * Each query is joined with the brand's service (BrandQueryServiceResolver: rules → portfolio / library mapping →
 * cached embeddings → "belirsiz"; place names are ignored for matching), branded flag (BrandedQueryMatcher), intent
 * (QueryIntent), sector and relevance ("alakasız" = out of the brand's sectors or an excluded expression;
 * flagged, never deleted). Queries are single-type: a query naming a place is one ordinary row — no per-query service
 * area / region split (location content comes from the brand's service areas in the content phase). Operator decisions are never overwritten; rows are never deleted (gold data) — queries not
 * seen in this window keep their row with zero window metrics. Only operational brands are built (ServiceScope).
 */
final class BrandDemandBuilder
{
    private const array SOURCES = ['search_console', 'google_ads', 'google_business_profile', 'portfolio', 'competitor', 'area_serp'];

    public function __construct(
        private readonly BrandQueryServiceResolver $resolver,
        private readonly SearchTermNormalizer $normalizer,
        private readonly QueryExclusionService $exclusions,
        private readonly ServiceScope $scope,
    ) {}

    /**
     * @return array{queries: int, assigned: int, branded: int, unclear: int, irrelevant: int, skipped: bool}
     */
    public function build(Brand $brand): array
    {
        $stats = ['queries' => 0, 'assigned' => 0, 'branded' => 0, 'unclear' => 0, 'irrelevant' => 0, 'skipped' => false];
        if (! $this->scope->isBrandOperational($brand->id)) {
            return [...$stats, 'skipped' => true];
        }
        $now = now()->startOfSecond();
        $rows = $this->collect($brand);
        $this->attachLibrary($rows);
        $resolved = $this->resolver->resolve($brand, $rows->map(fn (array $row): array => [
            'text' => $row['query'], 'library_item_id' => $row['library_item_id'], 'portfolio_services' => $row['portfolio_services'],
        ])->all());
        $offeringSectors = BrandOffering::query()->with('catalogItem:id,sector')->where('brand_id', $brand->id)->get()
            ->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => $o->catalogItem?->sector]);
        $branded = BrandedQueryMatcher::for($brand);
        $exclusionRules = $this->exclusions->rules();
        $existing = BrandDemandQuery::query()->where('brand_id', $brand->id)->get()->keyBy('query_key');
        $weights = (array) config('moxdop-demand.value_weights', []);

        DB::transaction(function () use ($brand, $rows, $resolved, $offeringSectors, $branded, $exclusionRules, $existing, $weights, $now, &$stats): void {
            $assetRows = [];
            foreach ($rows as $key => $row) {
                $value = round(array_sum(array_map(
                    fn (string $metric): float => (float) ($row[$metric] ?? 0) * (float) ($weights[$metric] ?? 0),
                    array_keys($weights),
                )), 2);
                $model = $existing->get($key) ?? new BrandDemandQuery(['brand_id' => $brand->id, 'query_key' => $key, 'first_seen_at' => $now]);
                $sources = array_keys(array_filter($row['sources']));
                $isBranded = $branded->isBranded($row['query']);
                $resolution = $resolved[$key];
                $attributes = [
                    'query' => $row['query'],
                    'gsc_clicks' => $row['gsc_clicks'], 'gsc_impressions' => $row['gsc_impressions'],
                    'gsc_position' => $row['pos_impressions'] > 0 ? round($row['pos_weighted'] / $row['pos_impressions'], 2) : null,
                    'ads_impressions' => $row['ads_impressions'], 'ads_clicks' => $row['ads_clicks'],
                    'ads_cost' => $row['ads_cost'], 'ads_conversions' => $row['ads_conversions'],
                    'gbp_impressions' => $row['gbp_impressions'],
                    'recent_impressions' => $row['recent_impressions'], 'previous_impressions' => $row['previous_impressions'],
                    'recent_clicks' => $row['recent_clicks'], 'previous_clicks' => $row['previous_clicks'],
                    'search_volume' => $row['search_volume'],
                    'serp_rank' => $row['serp_rank'],
                    'competitor_count' => $row['competitor_count'],
                    'first_observed_on' => $row['first_date'],
                    'last_observed_on' => $row['last_date'],
                    'sources' => $sources,
                    'source_mask' => BrandDemandQuery::maskOf($sources),
                    'search_query_library_item_id' => $row['library_item_id'],
                    'brand_query_portfolio_item_id' => $row['portfolio_item_id'],
                    'value_score' => $value,
                    'is_branded' => $isBranded,
                    'intent' => QueryIntent::of($row['query']),
                    'last_seen_at' => $now,
                    'built_at' => $now,
                ];
                $operatorAssigned = $model->exists && $model->assignment_source === BrandDemandQuery::SOURCE_OPERATOR;
                if (! $operatorAssigned) {
                    $attributes['brand_offering_id'] = $resolution['offering_id'];
                    $attributes['assignment_method'] = $resolution['method'];
                    $attributes['assignment_confidence'] = $resolution['confidence'];
                }
                $offeringId = $operatorAssigned ? $model->brand_offering_id : $resolution['offering_id'];
                $attributes['sector'] = $offeringId !== null ? ($offeringSectors[$offeringId] ?? $resolution['sector']) : $resolution['sector'];
                if (! ($model->exists && $model->relevance_source === BrandDemandQuery::SOURCE_OPERATOR)) {
                    $attributes['relevance'] = $this->relevance($offeringId, $isBranded, $resolution['out_of_sector'], $row['query'], $exclusionRules);
                }
                $model->fill($attributes)->save();

                foreach ($row['assets'] as $assetId => $metrics) {
                    $assetRows[] = [
                        'brand_demand_query_id' => $model->id, 'digital_asset_id' => $assetId,
                        'gsc_clicks' => $metrics['clicks'], 'gsc_impressions' => $metrics['impressions'],
                        'gsc_position' => $metrics['pos_impressions'] > 0 ? round($metrics['pos_weighted'] / $metrics['pos_impressions'], 2) : null,
                        'recent_impressions' => $metrics['recent_impressions'], 'previous_impressions' => $metrics['previous_impressions'],
                        'first_observed_on' => $metrics['first_date'], 'last_observed_on' => $metrics['last_date'],
                        'built_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                $stats['queries']++;
                $stats['assigned'] += $model->brand_offering_id !== null ? 1 : 0;
                $stats['branded'] += $model->is_branded ? 1 : 0;
                $stats['unclear'] += $model->relevance === BrandDemandQuery::UNCLEAR ? 1 : 0;
                $stats['irrelevant'] += $model->relevance === BrandDemandQuery::IRRELEVANT ? 1 : 0;
            }
            foreach (array_chunk($assetRows, 500) as $chunk) {
                DB::table('brand_demand_query_assets')->upsert($chunk, ['brand_demand_query_id', 'digital_asset_id'], [
                    'gsc_clicks', 'gsc_impressions', 'gsc_position', 'recent_impressions', 'previous_impressions',
                    'first_observed_on', 'last_observed_on', 'built_at', 'updated_at',
                ]);
            }

            // Not seen in this window: keep the row (gold), clear window metrics.
            BrandDemandQuery::query()->where('brand_id', $brand->id)
                ->where(fn ($query) => $query->whereNull('built_at')->orWhere('built_at', '<', $now))
                ->update([
                    'gsc_clicks' => 0, 'gsc_impressions' => 0, 'gsc_position' => null, 'ads_impressions' => 0, 'ads_clicks' => 0,
                    'ads_cost' => 0, 'ads_conversions' => 0, 'gbp_impressions' => 0, 'value_score' => 0,
                    'recent_impressions' => 0, 'previous_impressions' => 0, 'recent_clicks' => 0, 'previous_clicks' => 0, 'built_at' => $now,
                ]);
            DB::table('brand_demand_query_assets')
                ->whereIn('brand_demand_query_id', BrandDemandQuery::query()->where('brand_id', $brand->id)->select('id'))
                ->where(fn ($query) => $query->whereNull('built_at')->orWhere('built_at', '<', $now))
                ->update(['gsc_clicks' => 0, 'gsc_impressions' => 0, 'gsc_position' => null, 'recent_impressions' => 0, 'previous_impressions' => 0, 'built_at' => $now]);
        });

        return $stats;
    }

    /**
     * Window metrics per folded query across every source, keyed by the query key (sha256 of the folded text).
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function collect(Brand $brand): Collection
    {
        $assetIds = $brand->digitalAssets()->pluck('id')->map('intval')->all();
        $resourceAssets = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('digital_asset_id', 'external_resource_id')->map('intval')->all();
        $resourceIds = array_keys($resourceAssets);
        $windowDays = max(7, (int) config('moxdop-demand.window_days', 90));
        $from = now()->subDays($windowDays)->toDateString();
        $recentFrom = now()->subDays(28)->toDateString();
        $previousFrom = now()->subDays(56)->toDateString();
        $limit = max(100, (int) config('moxdop-demand.max_queries_per_source', 5000));
        $rows = collect();
        $add = function (string $text, string $source, array $metrics = []) use (&$rows): ?string {
            $text = trim($text);
            $folded = SeoText::fold($text);
            if ($folded === '' || mb_strlen($folded) > 500) {
                return null;
            }
            $key = hash('sha256', $folded);
            $row = $rows->get($key) ?? [
                'query' => $text, 'gsc_clicks' => 0, 'gsc_impressions' => 0, 'ads_impressions' => 0, 'ads_clicks' => 0,
                'ads_cost' => 0.0, 'ads_conversions' => 0.0, 'gbp_impressions' => 0,
                'recent_impressions' => 0, 'previous_impressions' => 0, 'recent_clicks' => 0, 'previous_clicks' => 0,
                'pos_weighted' => 0.0, 'pos_impressions' => 0, 'first_date' => null, 'last_date' => null,
                'search_volume' => null, 'serp_rank' => null, 'competitor_count' => 0,
                'library_item_id' => null, 'portfolio_item_id' => null, 'portfolio_services' => [], 'assets' => [],
                'sources' => array_fill_keys(self::SOURCES, false),
            ];
            foreach ($metrics as $metric => $value) {
                if ($metric === 'first_date' || $metric === 'last_date') {
                    $value = $value !== null ? substr((string) $value, 0, 10) : null;
                    if ($value !== null && ($row[$metric] === null || ($metric === 'first_date' ? $value < $row[$metric] : $value > $row[$metric]))) {
                        $row[$metric] = $value;
                    }

                    continue;
                }
                $row[$metric] += $value;
            }
            $row['sources'][$source] = true;
            $rows->put($key, $row);

            return $key;
        };
        $scope = function ($query) use ($assetIds, $resourceIds): void {
            $query->whereIn('digital_asset_id', $assetIds)->orWhereIn('external_resource_id', $resourceIds);
        };
        $trend = 'sum(case when reporting_date >= ? then impressions else 0 end) as recent_impressions, '
            .'sum(case when reporting_date >= ? and reporting_date < ? then impressions else 0 end) as previous_impressions, '
            .'sum(case when reporting_date >= ? then clicks else 0 end) as recent_clicks, '
            .'sum(case when reporting_date >= ? and reporting_date < ? then clicks else 0 end) as previous_clicks, '
            .'min(reporting_date) as first_date, max(reporting_date) as last_date';
        $trendBindings = [$recentFrom, $previousFrom, $recentFrom, $recentFrom, $previousFrom, $recentFrom];

        if (Schema::hasTable('gsc_query_daily')) {
            $position = DB::getDriverName() === 'pgsql'
                ? "(nullif(metadata->>'provider_average_position', ''))::numeric"
                : "json_extract(metadata, '$.provider_average_position')";
            DB::table('gsc_query_daily')->where('reporting_date', '>=', $from)->where($scope)
                ->groupBy('query', 'digital_asset_id', 'external_resource_id')
                ->selectRaw('query, digital_asset_id, external_resource_id, sum(clicks) as clicks, sum(impressions) as impressions, '.$trend
                    .", sum(case when {$position} is not null then {$position} * impressions else 0 end) as pos_weighted"
                    .", sum(case when {$position} is not null then impressions else 0 end) as pos_impressions", $trendBindings)
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(function ($r) use ($add, &$rows, $resourceAssets): void {
                    $metrics = [
                        'gsc_clicks' => (int) $r->clicks, 'gsc_impressions' => (int) $r->impressions,
                        'recent_impressions' => (int) $r->recent_impressions, 'previous_impressions' => (int) $r->previous_impressions,
                        'recent_clicks' => (int) $r->recent_clicks, 'previous_clicks' => (int) $r->previous_clicks,
                        'pos_weighted' => (float) $r->pos_weighted, 'pos_impressions' => (int) $r->pos_impressions,
                        'first_date' => $r->first_date, 'last_date' => $r->last_date,
                    ];
                    $key = $add((string) $r->query, 'search_console', $metrics);
                    $assetId = $r->digital_asset_id !== null ? (int) $r->digital_asset_id : ($resourceAssets[(int) $r->external_resource_id] ?? null);
                    if ($key === null || $assetId === null) {
                        return;
                    }
                    $row = $rows->get($key);
                    $site = $row['assets'][$assetId] ?? ['clicks' => 0, 'impressions' => 0, 'recent_impressions' => 0, 'previous_impressions' => 0, 'pos_weighted' => 0.0, 'pos_impressions' => 0, 'first_date' => null, 'last_date' => null];
                    foreach (['clicks' => 'gsc_clicks', 'impressions' => 'gsc_impressions', 'recent_impressions' => 'recent_impressions', 'previous_impressions' => 'previous_impressions', 'pos_weighted' => 'pos_weighted', 'pos_impressions' => 'pos_impressions'] as $field => $metric) {
                        $site[$field] += $metrics[$metric];
                    }
                    $first = substr((string) $r->first_date, 0, 10);
                    $last = substr((string) $r->last_date, 0, 10);
                    $site['first_date'] = $site['first_date'] === null || $first < $site['first_date'] ? $first : $site['first_date'];
                    $site['last_date'] = $site['last_date'] === null || $last > $site['last_date'] ? $last : $site['last_date'];
                    $row['assets'][$assetId] = $site;
                    $rows->put($key, $row);
                });
        }
        if (Schema::hasTable('google_ads_search_term_daily')) {
            DB::table('google_ads_search_term_daily')->where('reporting_date', '>=', $from)->where($scope)
                ->groupBy('search_term')
                ->selectRaw('search_term, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost_amount) as cost, sum(conversions) as conversions, '.$trend, $trendBindings)
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(fn ($r) => $add((string) $r->search_term, 'google_ads', [
                    'ads_impressions' => (int) $r->impressions, 'ads_clicks' => (int) $r->clicks,
                    'ads_cost' => round((float) $r->cost, 2), 'ads_conversions' => round((float) $r->conversions, 2),
                    'recent_impressions' => (int) $r->recent_impressions, 'previous_impressions' => (int) $r->previous_impressions,
                    'recent_clicks' => (int) $r->recent_clicks, 'previous_clicks' => (int) $r->previous_clicks,
                    'first_date' => $r->first_date, 'last_date' => $r->last_date,
                ]));
        }
        if (Schema::hasTable('gbp_search_keywords_monthly')) {
            $gbpFrom = now()->startOfMonth()->subMonths(max(1, (int) config('moxdop-demand.gbp_months', 3)))->toDateString();
            DB::table('gbp_search_keywords_monthly')->where('month_start', '>=', $gbpFrom)->where($scope)
                ->groupBy('search_keyword')
                ->selectRaw('search_keyword, sum(coalesce(impressions, threshold, 0)) as impressions, min(month_start) as first_date, max(month_start) as last_date')
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(fn ($r) => $add((string) $r->search_keyword, 'google_business_profile', [
                    'gbp_impressions' => (int) $r->impressions, 'first_date' => $r->first_date, 'last_date' => $r->last_date,
                ]));
        }
        $this->collectPortfolio($brand, $add, $rows, $limit);
        $this->collectAreaSerp($brand, $add, $rows);

        return $rows;
    }

    /**
     * The brand's active query portfolio: library / custom queries with their services, the latest stored DataForSEO
     * volume and how many competitors were seen on them.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function collectPortfolio(Brand $brand, callable $add, Collection $rows, int $limit): void
    {
        if (! Schema::hasTable('brand_query_portfolio_items')) {
            return;
        }
        $items = BrandQueryPortfolioItem::query()->with(['libraryItem', 'services:id'])
            ->where('brand_id', $brand->id)->where('status', 'active')->orderBy('id')->limit($limit)->get();
        if ($items->isEmpty()) {
            return;
        }
        $ids = $items->pluck('id')->all();
        $volumes = [];
        $competitors = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('search_demand_keyword_metric_snapshots')->whereIn('brand_query_portfolio_item_id', $chunk)->whereNotNull('search_volume')
                ->orderByDesc('retrieved_at')->get(['brand_query_portfolio_item_id', 'search_volume'])
                ->each(function ($r) use (&$volumes): void {
                    $volumes[(int) $r->brand_query_portfolio_item_id] ??= (int) $r->search_volume;
                });
            DB::table('search_demand_competitor_queries')->whereIn('brand_query_portfolio_item_id', $chunk)
                ->groupBy('brand_query_portfolio_item_id')->selectRaw('brand_query_portfolio_item_id, count(distinct search_demand_competitor_id) as competitors')
                ->get()->each(function ($r) use (&$competitors): void {
                    $competitors[(int) $r->brand_query_portfolio_item_id] = (int) $r->competitors;
                });
        }
        foreach ($items as $item) {
            $key = $add($item->effectiveQueryText(), 'portfolio');
            if ($key === null) {
                continue;
            }
            $row = $rows->get($key);
            $row['portfolio_item_id'] ??= (int) $item->id;
            $row['library_item_id'] ??= $item->search_query_library_item_id !== null ? (int) $item->search_query_library_item_id : null;
            $row['portfolio_services'] = array_values(array_unique([...$row['portfolio_services'], ...$item->services->pluck('id')->map('intval')->all()]));
            if (isset($volumes[$item->id])) {
                $row['search_volume'] = max((int) ($row['search_volume'] ?? 0), $volumes[$item->id]);
            }
            if (($competitors[$item->id] ?? 0) > 0) {
                $row['competitor_count'] = max($row['competitor_count'], $competitors[$item->id]);
                $row['sources']['competitor'] = true;
            }
            $rows->put($key, $row);
        }
    }

    /**
     * Keywords checked in the brand's service areas (stored results only): best rank of the latest check per location.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function collectAreaSerp(Brand $brand, callable $add, Collection $rows): void
    {
        if (! Schema::hasTable('demand_serp_checks')) {
            return;
        }
        DB::table('demand_serp_checks')->where('brand_id', $brand->id)->whereIn('status', ['completed', 'reused'])
            ->orderByDesc('checked_at')->limit(2000)->get(['keyword', 'location_code', 'our_rank'])
            ->unique(fn ($r): string => SeoText::fold((string) $r->keyword).'|'.$r->location_code)
            ->each(function ($r) use ($add, &$rows): void {
                $key = $add((string) $r->keyword, 'area_serp');
                if ($key === null || $r->our_rank === null) {
                    return;
                }
                $row = $rows->get($key);
                $row['serp_rank'] = $row['serp_rank'] === null ? (int) $r->our_rank : min($row['serp_rank'], (int) $r->our_rank);
                $rows->put($key, $row);
            });
    }

    /**
     * Links rows to their global library query (location-free identity, as the library stores it) and fills search
     * volume from the library's source records when the query itself names no place.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     */
    private function attachLibrary(Collection $rows): void
    {
        $hashes = [];
        foreach ($rows as $key => $row) {
            if ($row['library_item_id'] === null) {
                $stripped = LocationOptions::strip($row['query'])['text'];
                if (trim($stripped) === '') {
                    continue;
                }
                $hashes[$key] = hash('sha256', 'library-location-free-v2|'.$this->normalizer->normalize($stripped, 'tr')->canonicalText);
            }
        }
        $byHash = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            DB::table('search_query_library_items')->whereIn('identity_hash', $chunk)->whereNull('deleted_at')->where('status', 'active')
                ->pluck('id', 'identity_hash')->each(function ($id, $hash) use (&$byHash): void {
                    $byHash[$hash] = (int) $id;
                });
            if (Schema::hasTable('library_query_aliases')) {
                DB::table('library_query_aliases')->whereIn('identity_hash', $chunk)->pluck('query_id', 'identity_hash')
                    ->each(function ($id, $hash) use (&$byHash): void {
                        $byHash[$hash] ??= (int) $id;
                    });
            }
        }
        $volumeless = [];
        foreach ($rows as $key => $row) {
            if ($row['library_item_id'] === null && isset($hashes[$key], $byHash[$hashes[$key]])) {
                $row['library_item_id'] = $byHash[$hashes[$key]];
                $rows->put($key, $row);
            }
            if ($row['library_item_id'] !== null && $row['search_volume'] === null && LocationOptions::strip($row['query'])['removed'] === []) {
                $volumeless[$row['library_item_id']][] = $key;
            }
        }
        foreach (array_chunk(array_keys($volumeless), 500) as $chunk) {
            DB::table('search_query_library_source_records')->whereIn('search_query_library_item_id', $chunk)->whereNotNull('search_volume')
                ->groupBy('search_query_library_item_id')->selectRaw('search_query_library_item_id, max(search_volume) as volume')->get()
                ->each(function ($r) use ($volumeless, $rows): void {
                    foreach ($volumeless[(int) $r->search_query_library_item_id] ?? [] as $key) {
                        $row = $rows->get($key);
                        $row['search_volume'] = (int) round((float) $r->volume);
                        $rows->put($key, $row);
                    }
                });
        }
    }

    /**
     * @param  list<array<string, mixed>>  $exclusionRules
     */
    private function relevance(?int $offeringId, bool $isBranded, bool $outOfSector, string $query, array $exclusionRules): string
    {
        if ($offeringId !== null) {
            return BrandDemandQuery::RELEVANT;
        }
        if ($exclusionRules !== [] && $this->exclusions->matches($query, $exclusionRules) !== [] && ! $this->exclusions->isProtected($query)) {
            return BrandDemandQuery::IRRELEVANT;
        }
        if ($isBranded) {
            return BrandDemandQuery::RELEVANT;
        }

        return $outOfSector ? BrandDemandQuery::IRRELEVANT : BrandDemandQuery::UNCLEAR;
    }
}
