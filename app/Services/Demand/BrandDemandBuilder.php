<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the brand demand table from the brand's own accounts: Search Console queries, Google Ads search
 * terms and Business Profile search keywords over the window. Each query is assigned (deterministically) to
 * the most specific matching service (suffix-tolerant), to a service area when it names a place, and flagged
 * branded when it contains the brand or domain name. Operator assignments are never overwritten; rows are
 * never deleted (gold data) — queries not seen in this window keep their row with zero window metrics.
 */
final class BrandDemandBuilder
{
    /**
     * @return array{queries: int, assigned: int, branded: int, in_area: int, out_of_area: int}
     */
    public function build(Brand $brand): array
    {
        $now = now()->startOfSecond();
        $rows = $this->collect($brand);
        $phrases = $this->offeringPhrases($brand);
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderBy('priority_rank')->get();
        $areaRows = $areas->map(fn (BrandServiceArea $area): array => $area->only(['country_code', 'city_name', 'district_name']))->all();
        $branded = BrandedQueryMatcher::for($brand);
        $existing = BrandDemandQuery::query()->where('brand_id', $brand->id)->get()->keyBy('query_key');
        $weights = (array) config('moxdop-demand.value_weights', []);
        $stats = ['queries' => 0, 'assigned' => 0, 'branded' => 0, 'in_area' => 0, 'out_of_area' => 0];

        DB::transaction(function () use ($brand, $rows, $phrases, $areas, $areaRows, $branded, $existing, $weights, $now, &$stats): void {
            foreach ($rows as $key => $row) {
                $row['value_score'] = round(array_sum(array_map(
                    fn (string $metric): float => (float) ($row[$metric] ?? 0) * (float) ($weights[$metric] ?? 0),
                    array_keys($weights),
                )), 2);
                $location = LocationOptions::classify($row['query'], $areaRows);
                $model = $existing->get($key) ?? new BrandDemandQuery(['brand_id' => $brand->id, 'query_key' => $key, 'first_seen_at' => $now]);
                $operator = $model->exists && $model->assignment_source === BrandDemandQuery::SOURCE_OPERATOR;
                $attributes = [
                    'query' => $row['query'],
                    'gsc_clicks' => $row['gsc_clicks'], 'gsc_impressions' => $row['gsc_impressions'],
                    'ads_impressions' => $row['ads_impressions'], 'ads_clicks' => $row['ads_clicks'],
                    'ads_cost' => $row['ads_cost'], 'ads_conversions' => $row['ads_conversions'],
                    'gbp_impressions' => $row['gbp_impressions'],
                    'sources' => array_keys(array_filter($row['sources'])),
                    'value_score' => $row['value_score'],
                    'locations' => $location['removed'] === [] ? null : $location['removed'],
                    'location_status' => $location['out_of_area'] !== [] && $location['in_area'] === [] ? 'out_of_area' : ($location['in_area'] !== [] ? 'in_area' : 'none'),
                    'is_branded' => $branded->isBranded($row['query']),
                    'last_seen_at' => $now,
                    'built_at' => $now,
                ];
                if (! $operator) {
                    $attributes['brand_offering_id'] = $this->matchOffering($location['text'] !== '' ? $location['text'] : $row['query'], $phrases);
                    $attributes['brand_service_area_id'] = $this->matchArea($location['in_area'], $areas);
                }
                $model->fill($attributes)->save();

                $stats['queries']++;
                $stats['assigned'] += $model->brand_offering_id !== null ? 1 : 0;
                $stats['branded'] += $model->is_branded ? 1 : 0;
                $stats['in_area'] += $model->location_status === 'in_area' ? 1 : 0;
                $stats['out_of_area'] += $model->location_status === 'out_of_area' ? 1 : 0;
            }

            // Not seen in this window: keep the row (gold), clear window metrics.
            BrandDemandQuery::query()->where('brand_id', $brand->id)
                ->where(fn ($query) => $query->whereNull('built_at')->orWhere('built_at', '<', $now))
                ->update([
                    'gsc_clicks' => 0, 'gsc_impressions' => 0, 'ads_impressions' => 0, 'ads_clicks' => 0,
                    'ads_cost' => 0, 'ads_conversions' => 0, 'gbp_impressions' => 0, 'value_score' => 0, 'built_at' => $now,
                ]);
        });

        return $stats;
    }

    /**
     * Window metrics per folded query across the three sources.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function collect(Brand $brand): Collection
    {
        $assetIds = $brand->digitalAssets()->pluck('id')->all();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id')->all();
        $from = now()->subDays(max(7, (int) config('moxdop-demand.window_days', 90)))->toDateString();
        $limit = max(100, (int) config('moxdop-demand.max_queries_per_source', 5000));
        $rows = collect();
        $add = function (string $text, string $source, array $metrics) use (&$rows): void {
            $text = trim($text);
            $folded = SeoText::fold($text);
            if ($folded === '' || mb_strlen($folded) > 500) {
                return;
            }
            $key = hash('sha256', $folded);
            $row = $rows->get($key) ?? [
                'query' => $text, 'gsc_clicks' => 0, 'gsc_impressions' => 0, 'ads_impressions' => 0, 'ads_clicks' => 0,
                'ads_cost' => 0.0, 'ads_conversions' => 0.0, 'gbp_impressions' => 0,
                'sources' => ['search_console' => false, 'google_ads' => false, 'google_business_profile' => false],
            ];
            foreach ($metrics as $metric => $value) {
                $row[$metric] += $value;
            }
            $row['sources'][$source] = true;
            $rows->put($key, $row);
        };
        $scope = function ($query) use ($assetIds, $resourceIds): void {
            $query->whereIn('digital_asset_id', $assetIds)->orWhereIn('external_resource_id', $resourceIds);
        };

        if (Schema::hasTable('gsc_query_daily')) {
            DB::table('gsc_query_daily')->where('reporting_date', '>=', $from)->where($scope)
                ->groupBy('query')->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions')
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(fn ($r) => $add((string) $r->query, 'search_console', ['gsc_clicks' => (int) $r->clicks, 'gsc_impressions' => (int) $r->impressions]));
        }
        if (Schema::hasTable('google_ads_search_term_daily')) {
            DB::table('google_ads_search_term_daily')->where('reporting_date', '>=', $from)->where($scope)
                ->groupBy('search_term')->selectRaw('search_term, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost_amount) as cost, sum(conversions) as conversions')
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(fn ($r) => $add((string) $r->search_term, 'google_ads', [
                    'ads_impressions' => (int) $r->impressions, 'ads_clicks' => (int) $r->clicks,
                    'ads_cost' => round((float) $r->cost, 2), 'ads_conversions' => round((float) $r->conversions, 2),
                ]));
        }
        if (Schema::hasTable('gbp_search_keywords_monthly')) {
            $gbpFrom = now()->startOfMonth()->subMonths(max(1, (int) config('moxdop-demand.gbp_months', 3)))->toDateString();
            DB::table('gbp_search_keywords_monthly')->where('month_start', '>=', $gbpFrom)->where($scope)
                ->groupBy('search_keyword')->selectRaw('search_keyword, sum(coalesce(impressions, threshold, 0)) as impressions')
                ->orderByDesc('impressions')->limit($limit)->get()
                ->each(fn ($r) => $add((string) $r->search_keyword, 'google_business_profile', ['gbp_impressions' => (int) $r->impressions]));
        }

        return $rows;
    }

    /**
     * Matching phrases per active offering: its names, catalog names and matching expressions.
     *
     * @return list<array{id: int, phrase: string}>
     */
    private function offeringPhrases(Brand $brand): array
    {
        $phrases = [];
        BrandOffering::query()
            ->with(['names', 'catalogItem.names', 'catalogItem.matchingKeywords'])
            ->where('brand_id', $brand->id)->where('status', 'active')->get()
            ->each(function (BrandOffering $offering) use (&$phrases): void {
                $labels = $offering->names->where('is_active', true)->pluck('raw_label')
                    ->merge($offering->catalogItem?->names->where('is_active', true)->pluck('raw_label') ?? [])
                    ->merge($offering->catalogItem?->matchingKeywords->pluck('label') ?? []);
                foreach ($labels->filter()->unique() as $label) {
                    $phrase = trim(LocationOptions::strip((string) $label)['text']);
                    if (mb_strlen(SeoText::fold($phrase)) >= 3) {
                        $phrases[] = ['id' => (int) $offering->id, 'phrase' => $phrase];
                    }
                }
            });
        // Longest (most specific) phrase first.
        usort($phrases, static fn (array $a, array $b): int => mb_strlen(SeoText::fold($b['phrase'])) <=> mb_strlen(SeoText::fold($a['phrase'])));

        return $phrases;
    }

    /** @param  list<array{id: int, phrase: string}>  $phrases */
    private function matchOffering(string $query, array $phrases): ?int
    {
        foreach ($phrases as $candidate) {
            if (SeoText::matchesPhrase($query, $candidate['phrase'])) {
                return $candidate['id'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $inArea  place names the query mentions that fall inside the brand's areas
     * @param  Collection<int, BrandServiceArea>  $areas
     */
    private function matchArea(array $inArea, Collection $areas): ?int
    {
        foreach ($inArea as $name) {
            // Most specific area first: district-level areas before city-level ones.
            $sorted = $areas->sortByDesc(fn (BrandServiceArea $area): int => filled($area->district_name) ? 2 : (filled($area->city_name) ? 1 : 0));
            foreach ($sorted as $area) {
                if (LocationOptions::withinAreas($name, [$area->only(['country_code', 'city_name', 'district_name'])]) === true) {
                    return (int) $area->id;
                }
            }
        }

        return null;
    }
}
