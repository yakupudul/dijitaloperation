<?php

namespace App\Services\GoogleAds;

use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate SQL over the Google Ads normalized data pool.
 * Account KPIs come from google_ads_account_daily ONLY — never summed from
 * campaign/search-term/keyword rows. Uses cost_amount (already normalized).
 */
class GoogleAdsPoolReadRepository
{
    /**
     * @return array{impressions: int, clicks: int, cost_amount: float, conversions: float, currency: ?string, rows: int}
     */
    public function accountDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
    ): array {
        $row = DB::table('google_ads_account_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(impressions),0) as impressions')
            ->selectRaw('COALESCE(SUM(clicks),0) as clicks')
            ->selectRaw('COALESCE(SUM(cost_amount),0) as cost_amount')
            ->selectRaw('COALESCE(SUM(conversions),0) as conversions')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('MAX(currency) as currency')
            ->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'cost_amount' => (float) ($row->cost_amount ?? 0),
            'conversions' => (float) ($row->conversions ?? 0),
            'currency' => $row->currency !== null ? (string) $row->currency : null,
            'rows' => (int) ($row->rows ?? 0),
        ];
    }

    /**
     * @return list<array{date: string, impressions: int, clicks: int, cost_amount: float, conversions: float, currency: ?string}>
     */
    public function accountDailySeries(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
    ): array {
        return DB::table('google_ads_account_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->orderBy('reporting_date')
            ->get(['reporting_date', 'impressions', 'clicks', 'cost_amount', 'conversions', 'currency'])
            ->map(static fn ($row): array => [
                'date' => (string) $row->reporting_date,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ])
            ->all();
    }

    /**
     * Campaign performance aggregates for the range — independent of typed conversion joins.
     *
     * @return list<array<string, mixed>>
     */
    public function campaignPerformance(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 100,
    ): array {
        $perf = DB::table('google_ads_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('campaign_id')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get([
                'campaign_id',
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(cost_amount) as cost_amount'),
                DB::raw('SUM(conversions) as conversions'),
                DB::raw('AVG(search_impression_share) as search_impression_share'),
                DB::raw('MAX(currency) as currency'),
            ]);

        $campaignIds = $perf->pluck('campaign_id')->all();
        $snapshots = [];
        if ($campaignIds !== []) {
            $snapshots = DB::table('google_ads_campaign_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('customer_id', $customerId)
                ->whereIn('campaign_id', $campaignIds)
                ->get(['campaign_id', 'metadata'])
                ->keyBy('campaign_id')
                ->all();
        }

        $budgets = DB::table('google_ads_campaign_budget_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('customer_id', $customerId)
            ->get(['budget_id', 'metadata'])
            ->keyBy('budget_id');

        $rows = [];
        foreach ($perf as $row) {
            $meta = $this->decodeMetadata($snapshots[$row->campaign_id]->metadata ?? null);
            $budgetId = isset($meta['campaign_budget']) ? $this->resourceIdTail((string) $meta['campaign_budget']) : null;
            $budgetMeta = $budgetId !== null && isset($budgets[$budgetId])
                ? $this->decodeMetadata($budgets[$budgetId]->metadata)
                : [];

            $lostBudget = $this->metadataFloat($meta, 'search_budget_lost_impression_share');
            $lostRank = $this->metadataFloat($meta, 'search_rank_lost_impression_share');

            $rows[] = [
                'campaign_id' => (string) $row->campaign_id,
                'name' => (string) ($meta['name'] ?? $meta['campaign_name'] ?? ('Campaign '.$row->campaign_id)),
                'status' => (string) ($meta['status'] ?? $meta['campaign_status'] ?? 'UNKNOWN'),
                'channel_type' => (string) ($meta['advertising_channel_type'] ?? $meta['advertisingChannelType'] ?? 'UNKNOWN'),
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'search_impression_share' => $row->search_impression_share !== null ? (float) $row->search_impression_share : null,
                'lost_is_budget' => $lostBudget,
                'lost_is_rank' => $lostRank,
                'budget_amount' => isset($budgetMeta['amount']) ? (float) $budgetMeta['amount'] : null,
                'budget_id' => $budgetId,
                'bidding_strategy_type' => $meta['bidding_strategy_type'] ?? $meta['biddingStrategyType'] ?? null,
                'is_pmax' => strtoupper((string) ($meta['advertising_channel_type'] ?? $meta['advertisingChannelType'] ?? '')) === 'PERFORMANCE_MAX',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topSearchTerms(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 100,
    ): array {
        return DB::table('google_ads_search_term_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('search_term')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get([
                'search_term',
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(cost_amount) as cost_amount'),
                DB::raw('SUM(conversions) as conversions'),
                DB::raw('MAX(currency) as currency'),
                DB::raw('MAX(metadata) as metadata'),
            ])
            ->map(function ($row): array {
                $meta = $this->decodeMetadata($row->metadata);
                $contexts = is_array($meta['contexts'] ?? null) ? $meta['contexts'] : [];
                $first = $contexts[0] ?? [];

                return [
                    'search_term' => (string) $row->search_term,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'cost_amount' => (float) $row->cost_amount,
                    'conversions' => (float) $row->conversions,
                    'currency' => $row->currency !== null ? (string) $row->currency : null,
                    'source_view' => (string) ($meta['source_view'] ?? 'search_term_view'),
                    'campaign_id' => isset($first['campaign_id']) ? (string) $first['campaign_id'] : null,
                    'ad_group_id' => array_key_exists('ad_group_id', $first) && $first['ad_group_id'] !== null
                        ? (string) $first['ad_group_id']
                        : null,
                    'channel_type' => isset($first['advertising_channel_type'])
                        ? (string) $first['advertising_channel_type']
                        : null,
                    'is_pmax' => ($meta['source_view'] ?? '') === 'campaign_search_term_view'
                        || strtoupper((string) ($first['advertising_channel_type'] ?? '')) === 'PERFORMANCE_MAX',
                    'provider_may_omit_terms' => (bool) ($meta['provider_may_omit_terms'] ?? true),
                    'search_term_neq_keyword' => true,
                    'search_term_neq_market_volume' => true,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topKeywords(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 100,
    ): array {
        $perf = DB::table('google_ads_keyword_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('ad_group_id', 'criterion_id')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get([
                'ad_group_id',
                'criterion_id',
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(cost_amount) as cost_amount'),
                DB::raw('SUM(conversions) as conversions'),
                DB::raw('MAX(currency) as currency'),
            ]);

        $snapshots = [];
        if ($perf->isNotEmpty()) {
            $snapshots = DB::table('google_ads_keyword_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('customer_id', $customerId)
                ->where(function ($query) use ($perf): void {
                    foreach ($perf as $row) {
                        $query->orWhere(function ($inner) use ($row): void {
                            $inner->where('ad_group_id', $row->ad_group_id)
                                ->where('criterion_id', $row->criterion_id);
                        });
                    }
                })
                ->get(['ad_group_id', 'criterion_id', 'metadata'])
                ->keyBy(fn ($row): string => (string) $row->ad_group_id."\0".(string) $row->criterion_id)
                ->all();
        }

        $rows = [];
        foreach ($perf as $row) {
            $snapshotKey = (string) $row->ad_group_id."\0".(string) $row->criterion_id;
            $meta = $this->decodeMetadata($snapshots[$snapshotKey]->metadata ?? null);
            $rows[] = [
                'ad_group_id' => (string) $row->ad_group_id,
                'criterion_id' => (string) $row->criterion_id,
                'keyword' => (string) ($meta['keyword_text'] ?? $meta['text'] ?? ('Keyword '.$row->criterion_id)),
                'match_type' => (string) ($meta['match_type'] ?? $meta['matchType'] ?? 'UNKNOWN'),
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'keyword_neq_search_term' => true,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topLandingPages(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 100,
    ): array {
        return DB::table('google_ads_landing_page_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('landing_page')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit($limit)
            ->get([
                'landing_page',
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('SUM(cost_amount) as cost_amount'),
                DB::raw('SUM(conversions) as conversions'),
                DB::raw('MAX(currency) as currency'),
            ])
            ->map(static fn ($row): array => [
                'landing_page' => (string) $row->landing_page,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'neq_ga4_landing' => true,
                'neq_gsc_page' => true,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conversionActions(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
    ): array {
        return DB::table('google_ads_conversion_action_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->orderBy('conversion_action_id')
            ->get(['conversion_action_id', 'metadata'])
            ->map(function ($row): array {
                $meta = $this->decodeMetadata($row->metadata);

                return [
                    'conversion_action_id' => (string) $row->conversion_action_id,
                    'name' => (string) ($meta['name'] ?? ('Action '.$row->conversion_action_id)),
                    'category' => $meta['category'] ?? null,
                    'type' => $meta['type'] ?? null,
                    'status' => $meta['status'] ?? null,
                    'primary_for_goal' => (bool) ($meta['primary_for_goal'] ?? $meta['primaryForGoal'] ?? false),
                    'include_in_conversions_metric' => (bool) ($meta['include_in_conversions_metric'] ?? $meta['includeInConversionsMetric'] ?? false),
                    'neq_qualified_lead' => true,
                    'neq_business_outcome' => true,
                    'neq_verified_revenue' => true,
                ];
            })
            ->all();
    }

    /**
     * Typed conversion performance — conversions and all_conversions kept distinct.
     * No cost columns (prevents campaign-cost fanout when combining).
     *
     * @return list<array<string, mixed>>
     */
    public function conversionActionDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $customerId,
        string $start,
        string $end,
    ): array {
        return DB::table('google_ads_conversion_action_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('conversion_action_id')
            ->get([
                'conversion_action_id',
                DB::raw('SUM(conversions) as conversions'),
                DB::raw('SUM(all_conversions) as all_conversions'),
                DB::raw('SUM(conversions_value) as conversions_value'),
            ])
            ->map(static fn ($row): array => [
                'conversion_action_id' => (string) $row->conversion_action_id,
                'conversions' => (float) $row->conversions,
                'all_conversions' => (float) $row->all_conversions,
                'conversions_value' => $row->conversions_value !== null ? (float) $row->conversions_value : null,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adSnapshots(int $digitalAssetId, string $customerId, int $limit = 50): array
    {
        return DB::table('google_ads_ad_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('customer_id', $customerId)
            ->orderBy('ad_id')
            ->limit($limit)
            ->get(['ad_id', 'metadata'])
            ->map(function ($row): array {
                $meta = $this->decodeMetadata($row->metadata);

                return [
                    'ad_id' => (string) $row->ad_id,
                    'type' => $meta['type'] ?? $meta['ad_type'] ?? null,
                    'status' => $meta['status'] ?? null,
                    'ad_strength' => $meta['ad_strength'] ?? $meta['adStrength'] ?? null,
                    'final_urls' => $meta['final_urls'] ?? $meta['finalUrls'] ?? [],
                    'ad_neq_asset' => true,
                    'ad_strength_neq_performance_score' => true,
                ];
            })
            ->all();
    }

    /**
     * Flat asset inventory — not PMax AssetGroup hierarchy.
     *
     * @return list<array<string, mixed>>
     */
    public function assetCoverage(int $digitalAssetId, string $customerId, int $limit = 50): array
    {
        return DB::table('google_ads_asset_coverage_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('customer_id', $customerId)
            ->orderBy('asset_id')
            ->limit($limit)
            ->get(['asset_id', 'metadata'])
            ->map(function ($row): array {
                $meta = $this->decodeMetadata($row->metadata);

                return [
                    'asset_id' => (string) $row->asset_id,
                    'type' => $meta['type'] ?? $meta['asset_type'] ?? null,
                    'name' => $meta['name'] ?? null,
                    'asset_neq_ad' => true,
                    'not_asset_group' => true,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
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

    private function metadataFloat(array $meta, string $key): ?float
    {
        if (! array_key_exists($key, $meta) || $meta[$key] === null || $meta[$key] === '') {
            return null;
        }

        return (float) $meta[$key];
    }

    private function resourceIdTail(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return (string) end($parts);
    }
}
