<?php

namespace App\Services\Advisor\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\GoogleAdsBudgetPlan;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the Google Ads data that is already collected (normalized tables) for one Google Ads asset and
 * returns one in-memory package for the rule engine. No provider calls. Missing data stays missing:
 * every section carries `available` so rules can stay silent instead of reading "0".
 */
final class GoogleAdsAdvisorInputCollector
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly AdvisorWebsiteReader $websiteReader,
    ) {}

    /** @return array<string, mixed> */
    public function collect(DigitalAsset $asset): array
    {
        $asset->loadMissing('brand');
        $binding = $this->bindings->resolve((string) $asset->id);
        $base = [
            'asset' => ['id' => $asset->id, 'name' => $asset->name, 'brand_id' => $asset->brand_id, 'customer_id' => $asset->brand?->customer_id, 'brand_name' => $asset->brand?->name],
            'bound' => $binding->isReal(),
            'binding_reason' => $binding->reason,
        ];
        if (! $binding->isReal()) {
            return $base;
        }

        $scope = new GoogleAdsRowScope((int) $asset->id, (int) $binding->externalResourceId, (string) $binding->customerId);
        $tz = $binding->timezone ?: config('app.timezone');
        $days = (int) config('moxdop-advisor.google_ads.window_days', 30);
        $end = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $start = $end->subDays($days - 1);
        $historyStart = $end->subDays(59);
        $window = [$start->toDateString(), $end->toDateString()];

        $campaignDaily = $this->campaignDaily($scope, $historyStart->toDateString(), $end->toDateString());
        $campaigns = $this->campaigns($scope, $campaignDaily, $window);

        return $base + [
            'currency' => $binding->currency,
            'timezone' => $tz,
            'period' => ['start' => $window[0], 'end' => $window[1], 'days' => $days],
            'account' => $this->account($scope, $campaigns),
            'campaigns' => $campaigns,
            'campaign_daily' => $campaignDaily,
            'search_terms' => $this->searchTerms($scope, $window),
            'negatives' => $this->negatives($scope),
            'keywords' => $this->keywords($scope, $window),
            'quality_history' => $this->qualityHistory($scope, $end),
            'ads' => $this->ads($scope, $window),
            'landing_pages' => $this->landingPages($scope, $window),
            'conversion_actions' => $this->conversionActions($scope, $window),
            'asset_library' => $this->assetLibrary($scope),
            'recommendations' => $this->recommendations($scope),
            'changes' => $this->changes($scope),
            'targets' => $this->targets($asset, $window),
            'offerings' => $asset->brand_id !== null ? $this->seoInputs->offerings($asset) : [],
            'website' => $this->websiteReader->forBrandOf($asset),
            'ga4' => $this->ga4($asset, $window),
        ];
    }

    /** @return array<string, mixed> */
    private function account(GoogleAdsRowScope $scope, array $campaigns): array
    {
        $meta = $scope->snapshot('google_ads_account_snapshot')->orderByDesc('id')->value('metadata');
        $meta = self::decode($meta);
        $totals = ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'conversions_value' => 0.0];
        foreach ($campaigns as $campaign) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $campaign[$key];
            }
        }

        return $totals + [
            'name' => $meta['descriptive_name'] ?? null,
            'auto_tagging_enabled' => array_key_exists('auto_tagging_enabled', $meta) ? (bool) $meta['auto_tagging_enabled'] : null,
            'cpa' => $totals['conversions'] > 0 ? $totals['cost'] / $totals['conversions'] : null,
            'has_data' => $campaigns !== [],
        ];
    }

    /** @return array<string, array<string, array{cost: float, clicks: int, impressions: int, conversions: float}>> campaign id => date => metrics */
    private function campaignDaily(GoogleAdsRowScope $scope, string $from, string $to): array
    {
        $out = [];
        $scope->daily('google_ads_campaign_daily', $from, $to)
            ->select(['campaign_id', 'reporting_date', 'cost_amount', 'clicks', 'impressions', 'conversions'])
            ->orderBy('reporting_date')
            ->get()
            ->each(function (object $row) use (&$out): void {
                $date = substr((string) $row->reporting_date, 0, 10);
                $entry = $out[(string) $row->campaign_id][$date] ?? ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0];
                $entry['cost'] += (float) $row->cost_amount;
                $entry['clicks'] += (int) $row->clicks;
                $entry['impressions'] += (int) $row->impressions;
                $entry['conversions'] += (float) $row->conversions;
                $out[(string) $row->campaign_id][$date] = $entry;
            });

        return $out;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $daily
     * @param  array{0: string, 1: string}  $window
     * @return array<string, array<string, mixed>>
     */
    private function campaigns(GoogleAdsRowScope $scope, array $daily, array $window): array
    {
        $budgets = [];
        foreach ($scope->snapshot('google_ads_campaign_budget_snapshot')->get(['budget_id', 'metadata']) as $row) {
            $meta = self::decode($row->metadata);
            $budgets[(string) $row->budget_id] = isset($meta['amount']) && is_numeric($meta['amount']) ? (float) $meta['amount'] : null;
        }
        $snapshots = [];
        foreach ($scope->snapshot('google_ads_campaign_snapshot')->get(['campaign_id', 'metadata']) as $row) {
            $snapshots[(string) $row->campaign_id] = self::decode($row->metadata);
        }

        $out = [];
        $scope->daily('google_ads_campaign_daily', $window[0], $window[1])
            ->select(['campaign_id', 'impressions', 'clicks', 'cost_amount', 'conversions', 'search_impression_share', 'metadata'])
            ->get()
            ->each(function (object $row) use (&$out, $snapshots, $budgets): void {
                $id = (string) $row->campaign_id;
                $meta = self::decode($row->metadata);
                $snap = $snapshots[$id] ?? [];
                $entry = $out[$id] ?? [
                    'id' => $id,
                    'name' => $snap['name'] ?? $meta['campaign_name'] ?? ('Kampanya '.$id),
                    'status' => $snap['status'] ?? $meta['campaign_status'] ?? null,
                    'channel' => $snap['advertising_channel_type'] ?? $meta['advertising_channel_type'] ?? null,
                    'budget_amount' => isset($snap['budget_id']) ? ($budgets[(string) $snap['budget_id']] ?? null) : null,
                    'cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'conversions_value' => 0.0,
                    '_lost_budget_weighted' => 0.0, '_lost_rank_weighted' => 0.0, '_is_weight' => 0,
                ];
                $impressions = (int) $row->impressions;
                $entry['cost'] += (float) $row->cost_amount;
                $entry['clicks'] += (int) $row->clicks;
                $entry['impressions'] += $impressions;
                $entry['conversions'] += (float) $row->conversions;
                $entry['conversions_value'] += is_numeric($meta['conversions_value'] ?? null) ? (float) $meta['conversions_value'] : 0.0;
                if (is_numeric($meta['search_budget_lost_impression_share'] ?? null) && $impressions > 0) {
                    $entry['_lost_budget_weighted'] += (float) $meta['search_budget_lost_impression_share'] * $impressions;
                    $entry['_lost_rank_weighted'] += is_numeric($meta['search_rank_lost_impression_share'] ?? null) ? (float) $meta['search_rank_lost_impression_share'] * $impressions : 0.0;
                    $entry['_is_weight'] += $impressions;
                }
                $out[$id] = $entry;
            });

        foreach ($out as $id => $entry) {
            $weight = $entry['_is_weight'];
            $entry['lost_is_budget'] = $weight > 0 ? $entry['_lost_budget_weighted'] / $weight : null;
            $entry['lost_is_rank'] = $weight > 0 ? $entry['_lost_rank_weighted'] / $weight : null;
            $entry['cpa'] = $entry['conversions'] > 0 ? $entry['cost'] / $entry['conversions'] : null;
            unset($entry['_lost_budget_weighted'], $entry['_lost_rank_weighted'], $entry['_is_weight']);
            $out[$id] = $entry;
        }
        unset($daily);

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array<string, array<string, mixed>> folded term => aggregate
     */
    private function searchTerms(GoogleAdsRowScope $scope, array $window): array
    {
        $out = [];
        $scope->daily('google_ads_search_term_daily', $window[0], $window[1])
            ->select(['id', 'search_term', 'impressions', 'clicks', 'cost_amount', 'conversions', 'metadata'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$out): void {
                foreach ($rows as $row) {
                    $term = trim((string) $row->search_term);
                    if ($term === '') {
                        continue;
                    }
                    $key = mb_strtolower($term);
                    $meta = self::decode($row->metadata);
                    $entry = $out[$key] ?? ['term' => $term, 'cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'statuses' => [], 'campaign_ids' => [], 'ad_group_ids' => [], 'pmax' => false];
                    $entry['cost'] += (float) $row->cost_amount;
                    $entry['clicks'] += (int) $row->clicks;
                    $entry['impressions'] += (int) $row->impressions;
                    $entry['conversions'] += (float) $row->conversions;
                    foreach ((array) ($meta['contexts'] ?? []) as $context) {
                        if (! is_array($context)) {
                            continue;
                        }
                        if (filled($context['status'] ?? null)) {
                            $entry['statuses'][(string) $context['status']] = true;
                        }
                        if (filled($context['campaign_id'] ?? null)) {
                            $entry['campaign_ids'][(string) $context['campaign_id']] = true;
                        }
                        if (filled($context['ad_group_id'] ?? null)) {
                            $entry['ad_group_ids'][(string) $context['ad_group_id']] = true;
                        }
                        if (($context['advertising_channel_type'] ?? null) === 'PERFORMANCE_MAX') {
                            $entry['pmax'] = true;
                        }
                    }
                    if (($meta['source_view'] ?? null) === 'campaign_search_term_view') {
                        $entry['pmax'] = true;
                    }
                    $out[$key] = $entry;
                }
            });

        foreach ($out as $key => $entry) {
            $out[$key]['statuses'] = array_keys($entry['statuses']);
            $out[$key]['campaign_ids'] = array_keys($entry['campaign_ids']);
            $out[$key]['ad_group_ids'] = array_keys($entry['ad_group_ids']);
        }

        return $out;
    }

    /** @return list<array{text: string, match_type: ?string, level: string, campaign_id: ?string, ad_group_id: ?string}> */
    private function negatives(GoogleAdsRowScope $scope): array
    {
        $out = [];
        foreach ($scope->snapshot('google_ads_campaign_negative_keyword_snapshot')->get(['campaign_id', 'keyword_text', 'match_type', 'status']) as $row) {
            if (strtoupper((string) $row->status) === 'REMOVED') {
                continue;
            }
            $out[] = ['text' => (string) $row->keyword_text, 'match_type' => $row->match_type, 'level' => 'campaign', 'campaign_id' => (string) $row->campaign_id, 'ad_group_id' => null];
        }
        foreach ($scope->snapshot('google_ads_ad_group_negative_keyword_snapshot')->get(['campaign_id', 'ad_group_id', 'keyword_text', 'match_type', 'status']) as $row) {
            if (strtoupper((string) $row->status) === 'REMOVED') {
                continue;
            }
            $out[] = ['text' => (string) $row->keyword_text, 'match_type' => $row->match_type, 'level' => 'ad_group', 'campaign_id' => $row->campaign_id !== null ? (string) $row->campaign_id : null, 'ad_group_id' => (string) $row->ad_group_id];
        }

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return list<array<string, mixed>>
     */
    private function keywords(GoogleAdsRowScope $scope, array $window): array
    {
        $metrics = [];
        $scope->daily('google_ads_keyword_daily', $window[0], $window[1])
            ->select(['ad_group_id', 'criterion_id', 'clicks', 'impressions', 'cost_amount', 'conversions'])
            ->get()
            ->each(function (object $row) use (&$metrics): void {
                $key = $row->ad_group_id."\0".$row->criterion_id;
                $entry = $metrics[$key] ?? ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0];
                $entry['cost'] += (float) $row->cost_amount;
                $entry['clicks'] += (int) $row->clicks;
                $entry['impressions'] += (int) $row->impressions;
                $entry['conversions'] += (float) $row->conversions;
                $metrics[$key] = $entry;
            });

        $out = [];
        foreach ($scope->snapshot('google_ads_keyword_snapshot')->get(['ad_group_id', 'criterion_id', 'metadata']) as $row) {
            $meta = self::decode($row->metadata);
            $key = $row->ad_group_id."\0".$row->criterion_id;
            $out[] = [
                'ad_group_id' => (string) $row->ad_group_id,
                'criterion_id' => (string) $row->criterion_id,
                'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                'text' => (string) ($meta['keyword_text'] ?? ''),
                'match_type' => $meta['match_type'] ?? null,
                'status' => $meta['status'] ?? null,
                'quality_score' => is_numeric($meta['quality_score'] ?? null) ? (int) $meta['quality_score'] : null,
                'ad_relevance' => $meta['ad_relevance'] ?? null,
                'landing_page_experience' => $meta['landing_page_experience'] ?? null,
                'expected_ctr' => $meta['expected_ctr'] ?? null,
            ] + ($metrics[$key] ?? ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0]);
        }

        return $out;
    }

    /**
     * Earlier Quality Score per keyword: the latest daily copy that is at least `lookback_days` old
     * (within 60 days). Empty until the recorder has run long enough.
     *
     * @return array<string, array{quality_score: int, observed_on: string, ad_relevance: ?string, landing_page_experience: ?string, expected_ctr: ?string}> "ad_group\0criterion" => earlier value
     */
    private function qualityHistory(GoogleAdsRowScope $scope, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('google_ads_quality_score_history')) {
            return [];
        }
        $lookback = (int) config('moxdop-advisor.google_ads.quality_history.lookback_days', 28);
        $out = [];
        DB::table('google_ads_quality_score_history')
            ->where('customer_id', $scope->customerId)
            ->whereBetween('observed_on', [$end->subDays(60)->toDateString(), $end->subDays($lookback)->toDateString()])
            ->whereNotNull('quality_score')
            ->orderBy('observed_on')
            ->get(['ad_group_id', 'criterion_id', 'observed_on', 'quality_score', 'ad_relevance', 'landing_page_experience', 'expected_ctr'])
            ->each(function (object $row) use (&$out): void {
                $out[$row->ad_group_id."\0".$row->criterion_id] = [
                    'quality_score' => (int) $row->quality_score,
                    'observed_on' => substr((string) $row->observed_on, 0, 10),
                    'ad_relevance' => $row->ad_relevance,
                    'landing_page_experience' => $row->landing_page_experience,
                    'expected_ctr' => $row->expected_ctr,
                ];
            });

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array{available: bool, items: list<array<string, mixed>>, ad_groups: array<string, string>}
     */
    private function ads(GoogleAdsRowScope $scope, array $window): array
    {
        $adGroups = [];
        foreach ($scope->snapshot('google_ads_ad_group_snapshot')->get(['ad_group_id', 'metadata']) as $row) {
            $adGroups[(string) $row->ad_group_id] = (string) (self::decode($row->metadata)['name'] ?? ('Reklam grubu '.$row->ad_group_id));
        }
        $cost = [];
        if (Schema::hasTable('google_ads_ad_daily')) {
            $scope->professional('google_ads_ad_daily')
                ->whereBetween('reporting_date', $window)
                ->select(['ad_id', 'cost_amount', 'clicks', 'conversions'])
                ->get()
                ->each(function (object $row) use (&$cost): void {
                    $entry = $cost[(string) $row->ad_id] ?? ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
                    $entry['cost'] += (float) $row->cost_amount;
                    $entry['clicks'] += (int) $row->clicks;
                    $entry['conversions'] += (float) $row->conversions;
                    $cost[(string) $row->ad_id] = $entry;
                });
        }
        $items = [];
        foreach ($scope->snapshot('google_ads_ad_snapshot')->get(['ad_id', 'metadata']) as $row) {
            $meta = self::decode($row->metadata);
            $items[] = [
                'ad_id' => (string) $row->ad_id,
                'type' => $meta['type'] ?? null,
                'status' => $meta['status'] ?? null,
                'ad_strength' => $meta['ad_strength'] ?? null,
                'final_urls' => array_values(array_filter((array) ($meta['final_urls'] ?? []), 'is_string')),
                'ad_group_id' => isset($meta['ad_group_id']) ? (string) $meta['ad_group_id'] : null,
                'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                'metrics' => $cost[(string) $row->ad_id] ?? null,
            ];
        }

        return ['available' => $items !== [], 'items' => $items, 'ad_groups' => $adGroups, 'metrics_available' => $cost !== []];
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array<string, array<string, mixed>>
     */
    private function landingPages(GoogleAdsRowScope $scope, array $window): array
    {
        $out = [];
        $scope->daily('google_ads_landing_page_daily', $window[0], $window[1])
            ->select(['landing_page', 'reporting_date', 'clicks', 'cost_amount', 'conversions', 'metadata'])
            ->orderBy('reporting_date')
            ->get()
            ->each(function (object $row) use (&$out): void {
                $url = trim((string) $row->landing_page);
                if ($url === '') {
                    return;
                }
                $meta = self::decode($row->metadata);
                $entry = $out[$url] ?? ['url' => $url, 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0, 'speed_score' => null, 'mobile_friendly' => null];
                $entry['cost'] += (float) $row->cost_amount;
                $entry['clicks'] += (int) $row->clicks;
                $entry['conversions'] += (float) $row->conversions;
                if (is_numeric($meta['speed_score'] ?? null)) {
                    $entry['speed_score'] = (int) $meta['speed_score'];
                }
                if (is_numeric($meta['mobile_friendly_clicks_percentage'] ?? null)) {
                    $entry['mobile_friendly'] = (float) $meta['mobile_friendly_clicks_percentage'];
                }
                $out[$url] = $entry;
            });

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array{available: bool, items: list<array<string, mixed>>}
     */
    private function conversionActions(GoogleAdsRowScope $scope, array $window): array
    {
        $daily = [];
        $scope->daily('google_ads_conversion_action_daily', $window[0], $window[1])
            ->select(['conversion_action_id', 'conversions', 'all_conversions'])
            ->get()
            ->each(function (object $row) use (&$daily): void {
                $id = (string) $row->conversion_action_id;
                $daily[$id] = ($daily[$id] ?? 0.0) + (float) $row->all_conversions;
            });
        $items = [];
        foreach ($scope->snapshot('google_ads_conversion_action_snapshot')->get(['conversion_action_id', 'metadata']) as $row) {
            $meta = self::decode($row->metadata);
            $id = (string) $row->conversion_action_id;
            $items[] = [
                'id' => $id,
                'name' => (string) ($meta['name'] ?? ('Dönüşüm '.$id)),
                'status' => $meta['status'] ?? null,
                'category' => $meta['category'] ?? null,
                'type' => $meta['type'] ?? null,
                'origin' => $meta['origin'] ?? null,
                'primary' => (bool) ($meta['primary_for_goal'] ?? false),
                'counting_type' => $meta['counting_type'] ?? null,
                'conversions' => $daily[$id] ?? null,
            ];
        }

        return ['available' => $items !== [], 'items' => $items, 'daily_available' => $daily !== []];
    }

    /** @return array{available: bool, counts: array<string, int>} */
    private function assetLibrary(GoogleAdsRowScope $scope): array
    {
        $counts = [];
        foreach ($scope->snapshot('google_ads_asset_coverage_snapshot')->get(['metadata']) as $row) {
            $type = strtoupper((string) (self::decode($row->metadata)['type'] ?? ''));
            if ($type !== '') {
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }

        return ['available' => $counts !== [], 'counts' => $counts];
    }

    /** @return array{available: bool, observed_date: ?string, items: list<array<string, mixed>>} */
    private function recommendations(GoogleAdsRowScope $scope): array
    {
        if (! Schema::hasTable('google_ads_recommendation_snapshot')) {
            return ['available' => false, 'observed_date' => null, 'items' => []];
        }
        $latest = $scope->professional('google_ads_recommendation_snapshot')->max('observed_date');
        if ($latest === null) {
            return ['available' => false, 'observed_date' => null, 'items' => []];
        }
        $items = $scope->professional('google_ads_recommendation_snapshot')
            ->where('observed_date', $latest)
            ->get(['recommendation_type', 'campaign_resource_name', 'metadata'])
            ->map(fn (object $row): array => [
                'type' => (string) $row->recommendation_type,
                'campaign_id' => self::idFromResource($row->campaign_resource_name, 'campaigns'),
                'impact' => self::decode($row->metadata)['impact'] ?? null,
            ])
            ->all();

        return ['available' => true, 'observed_date' => substr((string) $latest, 0, 10), 'items' => $items];
    }

    /** @return array{available: bool, items: list<array<string, mixed>>} */
    private function changes(GoogleAdsRowScope $scope): array
    {
        if (! Schema::hasTable('google_ads_change_event')) {
            return ['available' => false, 'items' => []];
        }
        $items = $scope->professional('google_ads_change_event')
            ->where('changed_at', '>=', now()->subDays(45))
            ->orderBy('changed_at')
            ->limit(2000)
            ->get(['changed_at', 'change_resource_type', 'operation', 'client_type', 'user_email', 'metadata'])
            ->map(function (object $row): array {
                $meta = self::decode($row->metadata);
                $fields = $meta['changed_fields'] ?? null;

                return [
                    'changed_at' => (string) $row->changed_at,
                    'date' => substr((string) $row->changed_at, 0, 10),
                    'resource_type' => (string) $row->change_resource_type,
                    'operation' => $row->operation,
                    'client_type' => $row->client_type,
                    'user' => $row->user_email,
                    'campaign_id' => self::idFromResource($meta['campaign_resource_name'] ?? null, 'campaigns'),
                    'changed_fields' => is_string($fields) ? $fields : (is_array($fields) ? implode(',', array_map('strval', $fields['paths'] ?? $fields)) : null),
                ];
            })
            ->all();

        return ['available' => $items !== [], 'items' => $items];
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array{target_cpa: ?float, target_roas: ?float}
     */
    private function targets(DigitalAsset $asset, array $window): array
    {
        $plan = GoogleAdsBudgetPlan::query()
            ->where('digital_asset_id', $asset->id)
            ->where('period_start', '<=', $window[1])
            ->where('period_end', '>=', $window[0])
            ->orderByDesc('period_start')
            ->first();

        return [
            'target_cpa' => $plan?->target_cpa !== null ? (float) $plan->target_cpa : null,
            'target_roas' => $plan?->target_roas !== null ? (float) $plan->target_roas : null,
        ];
    }

    /**
     * Google / cpc traffic in GA4 for the brand's website: sessions and key events.
     *
     * @param  array{0: string, 1: string}  $window
     */
    private function ga4(DigitalAsset $asset, array $window): array
    {
        $empty = ['available' => false, 'sessions' => null, 'key_events' => null];
        if ($asset->brand_id === null || ! Schema::hasTable('ga4_source_medium_daily')) {
            return $empty;
        }
        $sites = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->pluck('id');
        foreach ($sites as $siteId) {
            $binding = $this->ga4Bindings->resolve((string) $siteId);
            if (! $binding->isReal() || $binding->externalResourceId === null) {
                continue;
            }
            $query = DB::table('ga4_source_medium_daily')
                ->where('external_resource_id', $binding->externalResourceId)
                ->whereBetween('reporting_date', $window)
                ->whereRaw('LOWER("sessionSource") = ?', ['google'])
                ->whereRaw('LOWER("sessionMedium") = ?', ['cpc']);
            $central = (clone $query)->whereNull('digital_asset_id')->exists();
            $query = $central ? $query->whereNull('digital_asset_id') : $query->where('digital_asset_id', $siteId);
            $row = $query->selectRaw('COUNT(*) as n, SUM("sessions") as sessions, SUM("keyEvents") as key_events')->first();
            if ($row !== null && (int) $row->n > 0) {
                return ['available' => true, 'sessions' => (int) $row->sessions, 'key_events' => (float) $row->key_events, 'website_asset_id' => (int) $siteId];
            }
        }

        return $empty;
    }

    /** @return array<string, mixed> */
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function idFromResource(mixed $resource, string $collection): ?string
    {
        if (! is_string($resource) || $resource === '') {
            return null;
        }

        return preg_match('~/'.$collection.'/(\d+)~', $resource, $match) === 1 ? $match[1] : null;
    }
}
