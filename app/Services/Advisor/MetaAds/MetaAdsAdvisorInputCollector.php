<?php

namespace App\Services\Advisor\MetaAds;

use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads already collected Meta Ads data (normalized meta_* tables, all per asset) for one Meta Ads asset
 * into one package for the rule engine. No provider calls; missing sections say so.
 *
 * "Results" are resolved per campaign from its ad sets' optimization goal (else the campaign objective)
 * using config('moxdop-advisor.meta_ads.result_actions'): the first listed action type with data wins.
 */
final class MetaAdsAdvisorInputCollector
{
    private int $assetId = 0;

    private string $accountId = '';

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindings,
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
        $this->assetId = (int) $asset->id;
        $this->accountId = (string) $binding->accountId;

        $cfg = (array) config('moxdop-advisor.meta_ads', []);
        $tz = $binding->timezone ?: config('app.timezone');
        $days = (int) ($cfg['window_days'] ?? 30);
        $end = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $window = [$end->subDays($days - 1)->toDateString(), $end->toDateString()];
        $history = [$end->subDays(59)->toDateString(), $end->toDateString()];
        $last7 = $end->subDays(6)->toDateString();

        $adsets = $this->adsets();
        $ads = $this->ads($history);
        $actions = $this->actions($history);
        $campaigns = $this->campaigns($adsets);

        // Resolve result action type per campaign, then roll ad-level actions up to ads, ad sets and campaigns.
        $adCampaign = array_map(static fn (array $ad): ?string => $ad['campaign_id'], $ads);
        $typeTotals = [];
        foreach ($actions['rows'] as $row) {
            $campaignId = $actions['level'] === 'ad' ? ($adCampaign[$row['entity_id']] ?? null) : $row['entity_id'];
            if ($campaignId !== null && $row['date'] >= $window[0]) {
                $typeTotals[$campaignId][$row['action_type']] = ($typeTotals[$campaignId][$row['action_type']] ?? 0.0) + $row['value'];
            }
        }
        $map = (array) ($cfg['result_actions'] ?? []);
        foreach ($campaigns as $id => $campaign) {
            $candidates = $map[$campaign['optimization_goal'] ?? ''] ?? $map[$campaign['objective'] ?? ''] ?? [];
            $chosen = null;
            foreach ($candidates as $type) {
                if (($typeTotals[$id][$type] ?? 0) > 0) {
                    $chosen = $type;
                    break;
                }
            }
            $campaigns[$id]['result_type'] = $chosen ?? ($candidates[0] ?? null);
            $campaigns[$id]['conversion'] = in_array($campaign['objective'], (array) ($cfg['conversion_objectives'] ?? []), true)
                || in_array($campaign['optimization_goal'], (array) ($cfg['conversion_goals'] ?? []), true);
        }

        $campaignDaily = [];
        $adResults = [];
        foreach ($actions['rows'] as $row) {
            $campaignId = $actions['level'] === 'ad' ? ($adCampaign[$row['entity_id']] ?? null) : $row['entity_id'];
            if ($campaignId === null || ! isset($campaigns[$campaignId]) || $row['action_type'] !== $campaigns[$campaignId]['result_type']) {
                continue;
            }
            $campaignDaily[$campaignId][$row['date']]['conversions'] = ($campaignDaily[$campaignId][$row['date']]['conversions'] ?? 0.0) + $row['value'];
            if ($actions['level'] === 'ad') {
                $adResults[$row['entity_id']][$row['date']] = ($adResults[$row['entity_id']][$row['date']] ?? 0.0) + $row['value'];
            }
        }

        foreach ($this->scoped('meta_campaign_daily')->whereBetween('reporting_date', $history)->get(['campaign_id', 'reporting_date', 'spend', 'impressions', 'clicks', 'reach', 'frequency', 'metadata']) as $row) {
            $id = (string) $row->campaign_id;
            $date = substr((string) $row->reporting_date, 0, 10);
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $entry = ($campaignDaily[$id][$date] ?? []) + ['conversions' => 0.0];
            $entry['cost'] = (float) $row->spend;
            $entry['clicks'] = (int) $row->clicks;
            $campaignDaily[$id][$date] = $entry;
            if (! isset($campaigns[$id])) {
                $campaigns[$id] = $this->emptyCampaign($id, 'Kampanya '.$id);
            }
            if ($date >= $window[0]) {
                $campaigns[$id]['spend'] += (float) $row->spend;
                $campaigns[$id]['impressions'] += (int) $row->impressions;
                $campaigns[$id]['clicks'] += (int) $row->clicks;
                $campaigns[$id]['link_clicks'] += (int) ($meta['inline_link_clicks'] ?? 0);
            }
            if ($date >= $last7) {
                $campaigns[$id]['spend_7d'] += (float) $row->spend;
                $frequency = is_numeric($row->frequency) ? (float) $row->frequency : (is_numeric($meta['frequency'] ?? null) ? (float) $meta['frequency'] : null);
                if ($frequency !== null && (int) $row->impressions > 0) {
                    $campaigns[$id]['_freq_weighted'] += $frequency * (int) $row->impressions;
                    $campaigns[$id]['_freq_weight'] += (int) $row->impressions;
                }
            }
        }
        foreach ($campaignDaily as $id => $dates) {
            foreach ($dates as $date => $entry) {
                $campaignDaily[$id][$date] += ['cost' => 0.0, 'clicks' => 0];
            }
        }

        $account = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'link_clicks' => 0, 'results' => 0.0, 'conversion_spend' => 0.0];
        foreach ($campaigns as $id => $campaign) {
            $results = 0.0;
            foreach ($campaignDaily[$id] ?? [] as $date => $entry) {
                if ($date >= $window[0]) {
                    $results += $entry['conversions'];
                }
            }
            $campaign['results'] = $results;
            $campaign['cpr'] = $results > 0 ? $campaign['spend'] / $results : null;
            $campaign['frequency_7d'] = $campaign['_freq_weight'] > 0 ? $campaign['_freq_weighted'] / $campaign['_freq_weight'] : null;
            unset($campaign['_freq_weighted'], $campaign['_freq_weight']);
            $campaigns[$id] = $campaign;
            foreach (['spend', 'impressions', 'clicks', 'link_clicks'] as $key) {
                $account[$key] += $campaign[$key];
            }
            if ($campaign['conversion']) {
                $account['results'] += $results;
                $account['conversion_spend'] += $campaign['spend'];
            }
        }
        $account['cpr'] = $account['results'] > 0 ? $account['conversion_spend'] / $account['results'] : null;
        $account['has_data'] = $account['spend'] > 0;
        $account['cost'] = $account['spend'];
        $account['conversions'] = $account['results'];
        $account['cpa'] = $account['cpr'];
        $account['name'] = GoogleAdsAdvisorInputCollector::decode($this->scoped('meta_ad_account_snapshot')->orderByDesc('id')->value('metadata'))['name'] ?? null;

        // Ad-level results and ad-set rollups (last 7 days and window).
        foreach ($ads as $adId => $ad) {
            foreach ($adResults[$adId] ?? [] as $date => $value) {
                if (isset($ads[$adId]['daily'][$date])) {
                    $ads[$adId]['daily'][$date]['results'] = $value;
                }
            }
            $adset = $ad['adset_id'];
            if ($adset === null || ! isset($adsets[$adset])) {
                continue;
            }
            foreach ($ads[$adId]['daily'] as $date => $day) {
                if ($date >= $window[0]) {
                    $adsets[$adset]['spend'] += $day['spend'];
                    $adsets[$adset]['results'] += $day['results'];
                }
                if ($date >= $last7) {
                    $adsets[$adset]['spend_7d'] += $day['spend'];
                    $adsets[$adset]['results_7d'] += $day['results'];
                }
            }
        }

        return $base + [
            'currency' => $binding->currency,
            'timezone' => $tz,
            'period' => ['start' => $window[0], 'end' => $window[1], 'days' => $days, 'last7' => $last7],
            'account' => $account,
            'campaigns' => $campaigns,
            'campaign_daily' => $campaignDaily,
            'adsets' => $adsets,
            'ads' => $ads,
            'actions_level' => $actions['level'],
            'creatives' => $this->creatives(),
            'conversion_sources' => $this->conversionSources(),
            'breakdowns' => $this->breakdowns($window),
            'hourly' => $this->hourly($window),
            'changes' => $this->changes($adsets, $ads),
            'offerings' => $asset->brand_id !== null ? $this->seoInputs->offerings($asset) : [],
            'website' => $this->websiteReader->forBrandOf($asset),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function campaigns(array $adsets): array
    {
        $goals = [];
        foreach ($adsets as $adset) {
            if ($adset['campaign_id'] !== null && $adset['optimization_goal'] !== null) {
                $goals[$adset['campaign_id']] ??= $adset['optimization_goal'];
            }
        }
        $out = [];
        foreach ($this->scoped('meta_campaign_snapshot')->get(['campaign_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $id = (string) $row->campaign_id;
            $out[$id] = [
                'name' => (string) ($meta['name'] ?? ('Kampanya '.$id)),
                'objective' => $meta['objective'] ?? null,
                'status' => $meta['status'] ?? null,
                'effective_status' => $meta['effective_status'] ?? null,
                'daily_budget' => is_numeric($meta['daily_budget'] ?? null) ? (float) $meta['daily_budget'] : null,
                'lifetime_budget' => is_numeric($meta['lifetime_budget'] ?? null) ? (float) $meta['lifetime_budget'] : null,
                'optimization_goal' => $goals[$id] ?? null,
            ] + $this->emptyCampaign($id, (string) ($meta['name'] ?? ''));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function emptyCampaign(string $id, string $name): array
    {
        return [
            'id' => $id, 'name' => $name, 'objective' => null, 'status' => null, 'effective_status' => null, 'daily_budget' => null, 'lifetime_budget' => null,
            'optimization_goal' => null, 'result_type' => null, 'conversion' => false,
            'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'link_clicks' => 0, 'spend_7d' => 0.0, '_freq_weighted' => 0.0, '_freq_weight' => 0,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function adsets(): array
    {
        $targeting = [];
        if (Schema::hasTable('meta_adset_targeting_snapshot')) {
            foreach ($this->scoped('meta_adset_targeting_snapshot')->get(['adset_id', 'optimization_goal', 'bid_strategy', 'promoted_object', 'targeting']) as $row) {
                $targeting[(string) $row->adset_id] = [
                    'optimization_goal' => $row->optimization_goal,
                    'bid_strategy' => $row->bid_strategy,
                    'promoted_object' => GoogleAdsAdvisorInputCollector::decode($row->promoted_object),
                    'targeting' => GoogleAdsAdvisorInputCollector::decode($row->targeting),
                ];
            }
        }
        $out = [];
        foreach ($this->scoped('meta_adset_snapshot')->get(['adset_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $id = (string) $row->adset_id;
            $extra = $targeting[$id] ?? [];
            $out[$id] = [
                'id' => $id,
                'name' => (string) ($meta['name'] ?? ('Reklam seti '.$id)),
                'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                'optimization_goal' => $meta['optimization_goal'] ?? ($extra['optimization_goal'] ?? null),
                'effective_status' => $meta['effective_status'] ?? $meta['status'] ?? null,
                'daily_budget' => is_numeric($meta['daily_budget'] ?? null) ? (float) $meta['daily_budget'] : null,
                'lifetime_budget' => is_numeric($meta['lifetime_budget'] ?? null) ? (float) $meta['lifetime_budget'] : null,
                'bid_strategy' => $extra['bid_strategy'] ?? null,
                'promoted_object' => $extra['promoted_object'] ?? [],
                'spend' => 0.0, 'results' => 0.0, 'spend_7d' => 0.0, 'results_7d' => 0.0,
            ];
        }

        return $out;
    }

    /**
     * @param  array{0: string, 1: string}  $history
     * @return array<string, array<string, mixed>>
     */
    private function ads(array $history): array
    {
        $out = [];
        if (Schema::hasTable('meta_ad_snapshot')) {
            foreach ($this->scoped('meta_ad_snapshot')->get(['ad_id', 'ad_name', 'campaign_id', 'adset_id', 'creative_id', 'status', 'effective_status', 'created_time']) as $row) {
                $out[(string) $row->ad_id] = [
                    'id' => (string) $row->ad_id,
                    'name' => (string) ($row->ad_name ?: 'Reklam '.$row->ad_id),
                    'campaign_id' => $row->campaign_id !== null ? (string) $row->campaign_id : null,
                    'adset_id' => $row->adset_id !== null ? (string) $row->adset_id : null,
                    'creative_id' => $row->creative_id !== null ? (string) $row->creative_id : null,
                    'effective_status' => $row->effective_status ?? $row->status,
                    'created_time' => $row->created_time !== null ? substr((string) $row->created_time, 0, 10) : null,
                    'daily' => [],
                ];
            }
        }
        foreach ($this->scoped('meta_ad_daily')->whereBetween('reporting_date', $history)->get(['ad_id', 'reporting_date', 'spend', 'impressions', 'clicks', 'reach', 'metadata']) as $row) {
            $id = (string) $row->ad_id;
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $out[$id] ??= [
                'id' => $id, 'name' => 'Reklam '.$id,
                'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                'adset_id' => isset($meta['adset_id']) ? (string) $meta['adset_id'] : null,
                'creative_id' => null, 'effective_status' => null, 'created_time' => null, 'daily' => [],
            ];
            $out[$id]['daily'][substr((string) $row->reporting_date, 0, 10)] = [
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'reach' => (int) $row->reach,
                'link_clicks' => (int) ($meta['inline_link_clicks'] ?? 0),
                'frequency' => is_numeric($meta['frequency'] ?? null) ? (float) $meta['frequency'] : ((int) $row->reach > 0 ? (int) $row->impressions / (int) $row->reach : null),
                'results' => 0.0,
            ];
        }

        return $out;
    }

    /**
     * Ad-level typed actions (current collector); campaign level only when no ad rows exist.
     *
     * @param  array{0: string, 1: string}  $history
     * @return array{level: string, rows: list<array{entity_id: string, date: string, action_type: string, value: float}>}
     */
    private function actions(array $history): array
    {
        foreach (['ad', 'campaign'] as $level) {
            $rows = $this->scoped('meta_typed_action_daily')
                ->where('entity_level', $level)
                ->whereBetween('reporting_date', $history)
                ->get(['entity_id', 'reporting_date', 'action_type', 'action_value'])
                ->map(static fn (object $row): array => ['entity_id' => (string) $row->entity_id, 'date' => substr((string) $row->reporting_date, 0, 10), 'action_type' => (string) $row->action_type, 'value' => (float) $row->action_value])
                ->all();
            if ($rows !== []) {
                return ['level' => $level, 'rows' => $rows];
            }
        }

        return ['level' => 'none', 'rows' => []];
    }

    /** @return array<string, array<string, mixed>> */
    private function creatives(): array
    {
        $out = [];
        foreach ($this->scoped('meta_creative_snapshot')->get(['creative_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $out[(string) $row->creative_id] = [
                'name' => $meta['name'] ?? null,
                'title' => $meta['title'] ?? null,
                'body' => $meta['body'] ?? null,
                'cta' => $meta['call_to_action_type'] ?? null,
                'link_url' => $meta['link_url'] ?? null,
                'object_type' => $meta['object_type'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array{available: bool, items: list<array<string, mixed>>} */
    private function conversionSources(): array
    {
        if (! Schema::hasTable('meta_conversion_source_snapshot')) {
            return ['available' => false, 'items' => []];
        }
        $items = $this->scoped('meta_conversion_source_snapshot')
            ->get(['source_type', 'source_id', 'source_name', 'event_type', 'last_fired_time', 'is_archived', 'is_unavailable', 'pixel_id'])
            ->map(static fn (object $row): array => [
                'type' => strtoupper((string) $row->source_type),
                'id' => (string) $row->source_id,
                'name' => $row->source_name,
                'event_type' => $row->event_type,
                'last_fired_time' => $row->last_fired_time !== null ? (string) $row->last_fired_time : null,
                'is_archived' => $row->is_archived === null ? null : (bool) $row->is_archived,
                'is_unavailable' => $row->is_unavailable === null ? null : (bool) $row->is_unavailable,
                'pixel_id' => $row->pixel_id !== null ? (string) $row->pixel_id : null,
            ])
            ->all();

        return ['available' => $items !== [], 'items' => $items];
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array<string, list<array{label: string, spend: float, impressions: int, clicks: int}>>
     */
    private function breakdowns(array $window): array
    {
        if (! Schema::hasTable('meta_analysis_breakdown_daily')) {
            return [];
        }
        $out = [];
        foreach ($this->scoped('meta_analysis_breakdown_daily')->whereIn('breakdown_type', ['placement', 'device'])->whereBetween('reporting_date', $window)->get(['breakdown_type', 'breakdown_key', 'spend', 'impressions', 'clicks']) as $row) {
            $dimensions = GoogleAdsAdvisorInputCollector::decode($row->breakdown_key);
            $label = $dimensions !== [] ? implode(' · ', array_map('strval', array_values($dimensions))) : (string) $row->breakdown_key;
            $type = (string) $row->breakdown_type;
            $entry = $out[$type][$label] ?? ['label' => $label, 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0];
            $entry['spend'] += (float) $row->spend;
            $entry['impressions'] += (int) $row->impressions;
            $entry['clicks'] += (int) $row->clicks;
            $out[$type][$label] = $entry;
        }

        return array_map('array_values', $out);
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return list<array{label: string, spend: float, impressions: int, clicks: int}>
     */
    private function hourly(array $window): array
    {
        if (! Schema::hasTable('meta_hourly_daily')) {
            return [];
        }
        $out = [];
        foreach ($this->scoped('meta_hourly_daily')->whereBetween('reporting_date', $window)->get(['hour_bucket', 'spend', 'impressions', 'clicks']) as $row) {
            $label = substr((string) $row->hour_bucket, 0, 5);
            $entry = $out[$label] ?? ['label' => $label, 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0];
            $entry['spend'] += (float) $row->spend;
            $entry['impressions'] += (int) $row->impressions;
            $entry['clicks'] += (int) $row->clicks;
            $out[$label] = $entry;
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Change history mapped to campaigns (ad set / ad changes through the snapshots).
     *
     * @return array{available: bool, items: list<array<string, mixed>>}
     */
    private function changes(array $adsets, array $ads): array
    {
        if (! Schema::hasTable('meta_change_event')) {
            return ['available' => false, 'items' => []];
        }
        $items = [];
        foreach ($this->scoped('meta_change_event')->where('event_time', '>=', now()->subDays(45))->orderBy('event_time')->limit(2000)->get(['event_time', 'event_type', 'translated_event_type', 'object_id', 'object_name', 'object_type', 'actor_name']) as $row) {
            $type = str_replace('_', '', strtoupper((string) $row->object_type));
            $objectId = (string) $row->object_id;
            $campaignId = match ($type) {
                'CAMPAIGN' => $objectId,
                'ADSET' => $adsets[$objectId]['campaign_id'] ?? null,
                'AD' => $ads[$objectId]['campaign_id'] ?? null,
                default => null,
            };
            $items[] = [
                'date' => substr((string) $row->event_time, 0, 10),
                'campaign_id' => $campaignId,
                'type' => $row->translated_event_type ?: $row->event_type,
                'object' => $row->object_name,
                'object_type' => $type,
                'user' => $row->actor_name,
            ];
        }

        return ['available' => $items !== [], 'items' => $items];
    }

    private function scoped(string $table): Builder
    {
        return DB::table($table)->where('digital_asset_id', $this->assetId)->where('account_id', $this->accountId);
    }
}
