<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Services\Brand\BrandScope;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read-only stored performance of one brand on Google Ads, Search Console, GA4 and Business Profile (yakup,
 * 2026-10-12: "tüm dijital varlıklarla alakalı buradan sana veri sorabilirim … her şeye erişebilir olmalısın").
 * Each dataset is a fixed aggregation of one collected table, scoped to the brand's assets; nothing is free SQL and
 * nothing is written. Meta lives in `meta-performance`.
 */
#[Description('Reads one brand\'s stored data for a channel dataset (no live provider call), summed over a period and grouped, highest first. Datasets: google_ads.months / campaigns / search_terms / keywords / locations / devices / conversion_actions; gsc.months / queries / pages / countries / query_countries; ga4.months / channels / landing_pages / countries / key_events; gbp.performance / keywords / reviews. Each answer carries the last collected day so stale data is visible. Meta: use meta-performance.')]
#[IsReadOnly]
class ChannelData extends Tool
{
    /**
     * dataset => [table, date column, group columns, metric columns ("count" = rows), name resolver].
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>, 4?: string}>
     */
    public const array DATASETS = [
        'google_ads.months' => ['google_ads_campaign_daily', 'reporting_date', ['month'], ['impressions', 'clicks', 'cost', 'conversions']],
        'google_ads.campaigns' => ['google_ads_campaign_daily', 'reporting_date', ['campaign_id'], ['impressions', 'clicks', 'cost', 'conversions'], 'campaign'],
        'google_ads.search_terms' => ['google_ads_search_term_daily', 'reporting_date', ['search_term'], ['impressions', 'clicks', 'cost', 'conversions']],
        'google_ads.keywords' => ['google_ads_keyword_daily', 'reporting_date', ['ad_group_id', 'criterion_id'], ['impressions', 'clicks', 'cost', 'conversions'], 'keyword'],
        'google_ads.locations' => ['google_ads_geo_daily', 'reporting_date', ['geo_target_region', 'geo_target_city'], ['impressions', 'clicks', 'cost', 'conversions'], 'geo'],
        'google_ads.devices' => ['google_ads_device_daily', 'reporting_date', ['device'], ['impressions', 'clicks', 'cost', 'conversions']],
        'google_ads.conversion_actions' => ['google_ads_conversion_action_daily', 'reporting_date', ['conversion_action_id'], ['conversions', 'conversions_value', 'all_conversions'], 'conversion_action'],
        'gsc.months' => ['gsc_property_daily', 'reporting_date', ['month'], ['clicks', 'impressions']],
        'gsc.queries' => ['gsc_query_page_daily', 'reporting_date', ['query'], ['clicks', 'impressions']],
        'gsc.pages' => ['gsc_page_daily', 'reporting_date', ['page'], ['clicks', 'impressions']],
        'gsc.countries' => ['gsc_country_daily', 'reporting_date', ['country'], ['clicks', 'impressions']],
        'gsc.query_countries' => ['gsc_query_country_daily', 'reporting_date', ['query', 'country'], ['clicks', 'impressions']],
        'ga4.months' => ['ga4_property_daily', 'reporting_date', ['month'], ['sessions', 'engagedSessions', 'totalUsers', 'screenPageViews']],
        'ga4.channels' => ['ga4_source_medium_daily', 'reporting_date', ['sessionSource', 'sessionMedium'], ['sessions', 'engagedSessions', 'totalUsers', 'keyEvents']],
        'ga4.landing_pages' => ['ga4_landing_page_daily', 'reporting_date', ['landingPage'], ['sessions', 'engagedSessions', 'totalUsers', 'keyEvents']],
        'ga4.countries' => ['ga4_geo_country_daily', 'reporting_date', ['country'], ['sessions', 'engagedSessions', 'totalUsers', 'keyEvents']],
        'ga4.key_events' => ['ga4_key_event_daily', 'reporting_date', ['eventName'], ['keyEvents']],
        'gbp.performance' => ['gbp_performance_daily', 'reporting_date', ['location_name', 'metric'], ['value']],
        'gbp.keywords' => ['gbp_search_keywords_monthly', 'month_start', ['search_keyword'], ['impressions']],
        'gbp.reviews' => ['gbp_reviews', 'create_time', ['location_name', 'star_rating'], ['count']],
    ];

    /** Dataset prefix => the account type its rows are collected under. */
    private const array RESOURCE_TYPES = [
        'google_ads' => 'google_ads',
        'gsc' => 'search_console',
        'ga4' => 'ga4',
        'gbp' => 'google_business_profile',
    ];

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer'],
            'dataset' => ['required', 'string', 'in:'.implode(',', array_keys(self::DATASETS))],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'contains' => ['nullable', 'string', 'max:100'],
        ]);
        $brand = Brand::query()->operational()->find($validated['brand_id']);
        if ($brand === null) {
            return Response::error('Marka bulunamadı ya da aktif müşteri değil.');
        }
        $definition = self::DATASETS[$validated['dataset']];
        [$table, $dateColumn, $groups, $metrics] = $definition;
        if (! SchemaState::hasTable($table) && ! in_array($table, array_column(Schema::getViews(), 'name'), true)) {
            return Response::error('Bu veri tablosu yok: '.$table);
        }

        $from = CarbonImmutable::now()->subDays((int) ($validated['days'] ?? 90))->toDateString();
        $owned = $this->owned((int) $brand->id, $validated['dataset'], $dateColumn, $from);
        $limit = (int) ($validated['limit'] ?? 50);
        $webOnly = str_starts_with($table, 'gsc_') && SchemaState::hasColumn($table, 'search_type');
        $base = fn () => $owned(DB::table($table), $table)->where($dateColumn, '>=', $from)
            ->when($webOnly, fn ($q) => $q->where(fn ($w) => $w->whereNull('search_type')->orWhere('search_type', 'web')));
        $sums = implode(', ', array_map(fn (string $m): string => $this->sum($table, $m), $metrics));

        if ($groups === ['month']) {
            $months = [];
            foreach ($base()->groupBy($dateColumn)->selectRaw($this->wrap($dateColumn).' as day, '.$sums)->get() as $day) {
                $month = substr((string) $day->day, 0, 7);
                foreach ($metrics as $metric) {
                    $months[$month][$metric] = ($months[$month][$metric] ?? 0) + (float) $day->{$metric};
                }
            }
            ksort($months);
            $rows = array_map(fn (string $month, array $values): array => ['month' => $month] + $this->round($values), array_keys($months), $months);
        } else {
            $query = $base()->groupBy(...$groups)->selectRaw(implode(', ', array_map(fn (string $g): string => $this->wrap($g), $groups)).', '.$sums)
                ->orderByRaw($this->sum($table, $metrics[0], false).' desc')->limit($limit);
            if (! empty($validated['contains'])) {
                $query->whereRaw('lower(cast('.$this->wrap($groups[0]).' as text)) like ?', ['%'.mb_strtolower((string) $validated['contains']).'%']);
            }
            $rows = $query->get()->map(function (object $r) use ($groups, $metrics): array {
                $row = [];
                foreach ($groups as $group) {
                    $row[$group] = $r->{$group};
                }

                return $row + $this->round(array_intersect_key((array) $r, array_flip($metrics)));
            })->all();
            $rows = $this->named($definition[4] ?? null, $rows, $owned);
        }

        return Response::json([
            'brand' => ['id' => (int) $brand->id, 'name' => (string) $brand->name],
            'dataset' => $validated['dataset'],
            'from' => $from,
            'last_day' => $base()->max($dateColumn),
            'totals' => $groups === ['month'] ? null : $this->round((array) ($base()->selectRaw($sums)->first() ?? [])),
            'rows' => $rows,
        ] + ($rows === [] ? ['sources' => $this->sources((int) $brand->id, $validated['dataset'], $table, $dateColumn)] : []));
    }

    /**
     * Why a dataset came back empty: per account of the brand, its rows bound to an asset and its central rows, with
     * the last day of each.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(int $brandId, string $dataset, string $table, string $dateColumn): array
    {
        if (! SchemaState::hasColumn($table, 'external_resource_id')) {
            return [];
        }
        $out = [];
        foreach (BrandScope::resources($brandId, self::RESOURCE_TYPES[strstr($dataset, '.', true)]) as $id) {
            $out[] = ['resource_id' => $id,
                'central_last_day' => DB::table($table)->where('external_resource_id', $id)->whereNull('digital_asset_id')->max($dateColumn),
                'asset_bound_last_day' => DB::table($table)->where('external_resource_id', $id)->whereNotNull('digital_asset_id')->max($dateColumn)];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Brand id from list-brands.')->required(),
            'dataset' => $schema->string()->enum(array_keys(self::DATASETS))->description('Channel dataset.')->required(),
            'days' => $schema->integer()->description('Days back (default 90, max 730).'),
            'limit' => $schema->integer()->description('Rows, highest first (default 50, max 500).'),
            'contains' => $schema->string()->description('Only rows whose first grouping contains this text (e.g. a query word, a page path).'),
        ];
    }

    /**
     * Restricts a query to the brand's rows. Collection is resource-first: an account's canonical rows carry its
     * external_resource_id with digital_asset_id empty; older rows are bound to an asset. Like the Google Ads pool
     * reader, each account is read from one source only (central when it has central rows, else asset-bound), so the
     * two are never summed together.
     *
     * @return \Closure(Builder, string): Builder
     */
    private function owned(int $brandId, string $dataset, ?string $dateColumn = null, ?string $from = null): \Closure
    {
        $assetIds = BrandScope::assetIds($brandId) ?: [0];
        $resourceIds = BrandScope::resources($brandId, self::RESOURCE_TYPES[strstr($dataset, '.', true)]);

        return function (Builder $query, string $table) use ($assetIds, $resourceIds, $dateColumn, $from): Builder {
            // Central only where the account has central rows in the period read: old central rows outside it must not
            // hide the asset-bound rows that are inside it.
            $central = $resourceIds !== [] && SchemaState::hasColumn($table, 'external_resource_id')
                ? array_values(array_filter($resourceIds, fn (int $id): bool => DB::table($table)->where('external_resource_id', $id)->whereNull('digital_asset_id')
                    ->when($dateColumn !== null && $from !== null && SchemaState::hasColumn($table, $dateColumn), fn (Builder $q) => $q->where($dateColumn, '>=', $from))->exists()))
                : [];

            return $query->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $legacy) => $legacy->whereIn('digital_asset_id', $assetIds)
                    ->when($central !== [], fn (Builder $q) => $q->where(fn (Builder $other) => $other->whereNotIn('external_resource_id', $central)->orWhereNull('external_resource_id'))))
                ->when($central !== [], fn (Builder $q) => $q->orWhere(fn (Builder $c) => $c->whereIn('external_resource_id', $central)->whereNull('digital_asset_id'))));
        };
    }

    private function sum(string $table, string $metric, bool $alias = true): string
    {
        $expression = match (true) {
            $metric === 'count' => 'count(*)',
            $metric === 'cost' && SchemaState::hasColumn($table, 'cost_amount') => 'sum(coalesce(cost_amount, cost_micros / 1000000.0))',
            $metric === 'cost' => 'sum(cost_micros) / 1000000.0',
            default => 'sum('.$this->wrap($metric).')',
        };

        return $alias ? $expression.' as '.$this->wrap($metric) : $expression;
    }

    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, float|int>
     */
    private function round(array $values): array
    {
        return array_map(fn ($v): float|int => (float) $v == (int) $v ? (int) $v : round((float) $v, 2), $values);
    }

    /**
     * Readable names for ids from the latest snapshots.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  \Closure(Builder, string): Builder  $owned
     * @return list<array<string, mixed>>
     */
    private function named(?string $resolver, array $rows, \Closure $owned): array
    {
        if ($resolver === null || $rows === []) {
            return $rows;
        }
        $meta = fn (string $table, string $key): array => SchemaState::hasTable($table)
            ? $owned(DB::table($table), $table)->get([$key, 'metadata'])
                ->mapWithKeys(fn (object $r): array => [(string) $r->{$key} => is_string($r->metadata) ? (array) json_decode($r->metadata, true) : (array) $r->metadata])->all()
            : [];

        if ($resolver === 'geo') {
            $names = DB::table('google_ads_geo_names')->whereIn('resource_name', array_filter([...array_column($rows, 'geo_target_region'), ...array_column($rows, 'geo_target_city')]))->pluck('name', 'resource_name')->all();

            return array_map(fn (array $r): array => ['region_name' => $names[$r['geo_target_region']] ?? null, 'city_name' => $names[$r['geo_target_city']] ?? null] + $r, $rows);
        }
        if ($resolver === 'keyword') {
            $keywords = [];
            if (SchemaState::hasTable('google_ads_keyword_snapshot')) {
                foreach ($owned(DB::table('google_ads_keyword_snapshot'), 'google_ads_keyword_snapshot')->get(['ad_group_id', 'criterion_id', 'metadata']) as $k) {
                    $keywords[$k->ad_group_id."\0".$k->criterion_id] = is_string($k->metadata) ? (array) json_decode($k->metadata, true) : (array) $k->metadata;
                }
            }

            return array_map(fn (array $r): array => [
                'keyword' => $keywords[$r['ad_group_id']."\0".$r['criterion_id']]['keyword_text'] ?? null,
                'match_type' => $keywords[$r['ad_group_id']."\0".$r['criterion_id']]['match_type'] ?? null,
            ] + $r, $rows);
        }
        [$table, $key] = $resolver === 'campaign' ? ['google_ads_campaign_snapshot', 'campaign_id'] : ['google_ads_conversion_action_snapshot', 'conversion_action_id'];
        $names = $meta($table, $key);

        return array_map(fn (array $r): array => [
            'name' => $names[(string) $r[$key]]['name'] ?? null,
            'status' => $names[(string) $r[$key]]['status'] ?? null,
        ] + $r, $rows);
    }
}
