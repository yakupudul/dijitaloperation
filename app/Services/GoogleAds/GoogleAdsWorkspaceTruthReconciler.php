<?php

namespace App\Services\GoogleAds;

use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reconciles the operator workspace with durable typed Google Ads rows.
 *
 * Dataset materialization/freshness metadata remains useful for diagnostics, but it
 * must not hide typed provider rows that are already present in the Data Pool. This
 * class is deliberately read-only and never calls Google Ads during page render.
 */
final class GoogleAdsWorkspaceTruthReconciler
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
        private readonly GoogleAdsPoolReadRepository $pool,
    ) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function reconcile(string $assetId, string $start, string $end, array $data): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->customerId === null) {
            return $data;
        }

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $currency = (string) ($binding->currency ?: data_get($data, 'identity.currency', 'XXX'));

        $account = $this->pool->accountDailySums($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        $accountSeries = [];
        $accountSource = 'google_ads_account_daily';

        if ((int) ($account['rows'] ?? 0) > 0) {
            $accountSeries = $this->pool->accountDailySeries($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        } else {
            $fallback = $this->networkAccountFallback($digitalAssetId, $externalResourceId, $customerId, $start, $end);
            if ($fallback !== null) {
                $account = $fallback['sums'];
                $accountSeries = $fallback['series'];
                $accountSource = 'google_ads_network_daily';
            }
        }

        if ((int) ($account['rows'] ?? 0) > 0) {
            $data['glance']['spend'] = [
                'value' => $this->formatMoney((float) ($account['cost_amount'] ?? 0), $currency),
                'raw' => round((float) ($account['cost_amount'] ?? 0), 2),
                'secondary' => 'Data Pool · selected period',
                'tone' => 'neutral',
                'note' => $accountSource === 'google_ads_network_daily'
                    ? 'Account daily was empty; total is reconciled from the provider-backed network partition for the same period.'
                    : 'Read from durable typed Google Ads account rows for the selected period.',
            ];
            $data['glance']['conversions'] = [
                'value' => number_format((float) ($account['conversions'] ?? 0), 1),
                'raw' => (float) ($account['conversions'] ?? 0),
                'secondary' => 'Data Pool · selected period',
                'tone' => 'neutral',
                'note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
            ];

            $data['performance_trend'] = [
                'labels' => array_map(static fn (array $row): string => CarbonImmutable::parse((string) $row['date'])->format('M j'), $accountSeries),
                'spend' => array_map(static fn (array $row): float => round((float) $row['cost_amount'], 2), $accountSeries),
                'leads' => array_map(static fn (array $row): float => (float) $row['conversions'], $accountSeries),
                'compare_label' => 'vs prior period',
                'note' => 'Spend + Google Ads provider conversions · durable typed Data Pool rows.',
            ];
            $data['data_provenance']['glance.spend'] = 'REAL';
            $data['data_provenance']['glance.conversions'] = 'REAL';
            $data['data_provenance']['performance_trend'] = 'REAL';
        } else {
            // A successful zero-row materialization is not enough to present a
            // numerical zero when no typed metric row exists for the period.
            foreach (['spend', 'conversions'] as $metric) {
                $data['glance'][$metric] = [
                    'value' => '—',
                    'raw' => null,
                    'secondary' => 'Unavailable',
                    'tone' => 'neutral',
                    'note' => 'No typed Google Ads metric rows exist for the selected period. Missing ≠ zero.',
                ];
            }
            $data['performance_trend'] = [
                'labels' => [],
                'spend' => [],
                'leads' => [],
                'compare_label' => 'vs prior period',
                'note' => 'No typed daily performance rows exist for the selected period.',
            ];
        }

        $campaignRows = $this->pool->campaignPerformance($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        $campaignSource = 'google_ads_campaign_daily';
        if ($campaignRows === []) {
            $campaignRows = $this->campaignFallbackFromAdGroups($digitalAssetId, $externalResourceId, $customerId, $start, $end);
            $campaignSource = 'google_ads_ad_group_daily';
        }
        if ($campaignRows !== []) {
            $data['campaigns'] = array_map(fn (array $row): array => $this->campaignRow($row, $currency), $campaignRows);
            $data['campaigns_source_note'] = $campaignSource === 'google_ads_campaign_daily'
                ? 'Campaign performance read directly from durable typed campaign rows.'
                : 'Campaign daily was empty; rows are a provider-backed partial reconciliation from ad-group daily data.';
            $data['data_provenance']['campaigns'] = $campaignSource === 'google_ads_campaign_daily' ? 'REAL' : 'PARTIAL_REAL';
        }

        $campaignNames = [];
        foreach ($data['campaigns'] ?? [] as $campaign) {
            if (isset($campaign['id'], $campaign['name'])) {
                $campaignNames[(string) $campaign['id']] = (string) $campaign['name'];
            }
        }

        $terms = $this->pool->topSearchTerms($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($terms !== []) {
            $data['search']['terms'] = array_map(function (array $row) use ($campaignNames): array {
                $campaignId = $row['campaign_id'] ?? null;

                return [
                    'term' => (string) $row['search_term'],
                    'campaign' => $campaignId !== null ? ($campaignNames[(string) $campaignId] ?? 'Campaign '.$campaignId) : null,
                    'campaign_id' => $campaignId,
                    'ad_group' => (bool) ($row['is_pmax'] ?? false) ? null : ($row['ad_group_id'] ?? null),
                    'spend' => round((float) ($row['cost_amount'] ?? 0), 2),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'leads' => (float) ($row['conversions'] ?? 0),
                    'currency' => $row['currency'] ?? null,
                    'intent' => '',
                    'fit' => 'Observed',
                    'decision' => '',
                    'is_pmax' => (bool) ($row['is_pmax'] ?? false),
                    'provider_may_omit_terms' => (bool) ($row['provider_may_omit_terms'] ?? true),
                    'completeness' => 'PROVIDER_LIMITED',
                    'search_term_note' => GoogleAdsSpecialistReadService::SEARCH_VOLUME_NOTE,
                    'keyword_distinction_note' => 'Search term ≠ keyword.',
                    'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
                ];
            }, $terms);
            $data['search']['terms_observed'] = count($terms);
            $data['data_provenance']['search.terms'] = 'PROVIDER_LIMITED';
        }

        $keywords = $this->pool->topKeywords($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($keywords !== []) {
            $data['search']['keywords'] = array_map(fn (array $row): array => [
                'ad_group_id' => $row['ad_group_id'] ?? null,
                'criterion_id' => (string) $row['criterion_id'],
                'keyword' => (string) $row['keyword'],
                'match' => $this->humanize((string) ($row['match_type'] ?? 'UNKNOWN')),
                'spend' => round((float) ($row['cost_amount'] ?? 0), 2),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'leads' => (float) ($row['conversions'] ?? 0),
                'currency' => $row['currency'] ?? null,
                'keyword_neq_search_term' => true,
                'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
            ], $keywords);
            $data['data_provenance']['search.keywords'] = 'PROVIDER_LIMITED';
        }

        $landing = $this->pool->topLandingPages($digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($landing !== []) {
            $formatted = array_map(static fn (array $row): array => [
                'id' => 'lp-'.Str::slug((string) $row['landing_page']),
                'url' => (string) $row['landing_page'],
                'title' => '',
                'spend' => round((float) ($row['cost_amount'] ?? 0), 2),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'leads' => (float) ($row['conversions'] ?? 0),
                'currency' => $row['currency'] ?? null,
                'campaigns' => [],
                'technical' => 'Unavailable',
                'mobile' => 'Unavailable',
                'measurement' => 'Unavailable',
                'message' => 'Unavailable',
                'language' => null,
                'attention' => null,
                'website_finding' => null,
                'query_themes' => [],
                'ad_themes' => [],
                'message_reason' => null,
                'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
            ], $landing);
            $data['landing_pages'] = [
                'subtitle' => 'Provider-backed Google Ads landing-page performance for the selected period.',
                'active' => count($formatted),
                'need_review' => null,
                'exposure_attention' => null,
                'rows' => $formatted,
            ];
            $data['data_provenance']['landing_pages'] = 'REAL';
        }

        return $data;
    }

    /** @return array{sums:array<string,mixed>,series:list<array<string,mixed>>}|null */
    private function networkAccountFallback(int $digitalAssetId, int $externalResourceId, string $customerId, string $start, string $end): ?array
    {
        $query = $this->typedDailyScope('google_ads_network_daily', $digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($query === null) {
            return null;
        }

        $series = $query
            ->select('reporting_date')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(cost_amount) as cost_amount')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('MAX(currency) as currency')
            ->groupBy('reporting_date')
            ->orderBy('reporting_date')
            ->get()
            ->map(static fn (object $row): array => [
                'date' => (string) $row->reporting_date,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ])->all();

        if ($series === []) {
            return null;
        }

        return [
            'sums' => [
                'impressions' => array_sum(array_column($series, 'impressions')),
                'clicks' => array_sum(array_column($series, 'clicks')),
                'cost_amount' => array_sum(array_column($series, 'cost_amount')),
                'conversions' => array_sum(array_column($series, 'conversions')),
                'currency' => $series[0]['currency'] ?? null,
                'rows' => count($series),
            ],
            'series' => $series,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function campaignFallbackFromAdGroups(int $digitalAssetId, int $externalResourceId, string $customerId, string $start, string $end): array
    {
        $query = $this->typedDailyScope('google_ads_ad_group_daily', $digitalAssetId, $externalResourceId, $customerId, $start, $end);
        if ($query === null) {
            return [];
        }

        $rows = $query
            ->select('campaign_id')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(cost_amount) as cost_amount')
            ->selectRaw('SUM(conversions) as conversions')
            ->selectRaw('MAX(currency) as currency')
            ->groupBy('campaign_id')
            ->orderByDesc(DB::raw('SUM(cost_amount)'))
            ->limit(100)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('campaign_id')->map(static fn ($id): string => (string) $id)->all();
        $meta = $this->campaignMetadata($digitalAssetId, $externalResourceId, $customerId, $ids);

        return $rows->map(function (object $row) use ($meta): array {
            $id = (string) $row->campaign_id;
            $m = $meta[$id] ?? [];

            return [
                'campaign_id' => $id,
                'name' => (string) ($m['name'] ?? $m['campaign_name'] ?? ('Campaign '.$id)),
                'status' => (string) ($m['status'] ?? $m['campaign_status'] ?? 'UNKNOWN'),
                'channel_type' => (string) ($m['advertising_channel_type'] ?? 'UNKNOWN'),
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'cost_amount' => (float) $row->cost_amount,
                'conversions' => (float) $row->conversions,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'search_impression_share' => null,
                'lost_is_budget' => null,
                'lost_is_rank' => null,
                'budget_amount' => null,
                'budget_id' => null,
                'bidding_strategy_type' => null,
                'is_pmax' => strtoupper((string) ($m['advertising_channel_type'] ?? '')) === 'PERFORMANCE_MAX',
            ];
        })->all();
    }

    /** @param list<string> $ids @return array<string,array<string,mixed>> */
    private function campaignMetadata(int $digitalAssetId, int $externalResourceId, string $customerId, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('google_ads_campaign_snapshot')) {
            return [];
        }

        $base = DB::table('google_ads_campaign_snapshot')
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereIn('campaign_id', $ids);
        $central = Schema::hasColumn('google_ads_campaign_snapshot', 'digital_asset_id')
            && (clone $base)->whereNull('digital_asset_id')->exists();
        if (Schema::hasColumn('google_ads_campaign_snapshot', 'digital_asset_id')) {
            $base = $central ? $base->whereNull('digital_asset_id') : $base->where('digital_asset_id', $digitalAssetId);
        }

        return $base->get(['campaign_id', 'metadata'])->mapWithKeys(function (object $row): array {
            $meta = is_array($row->metadata)
                ? $row->metadata
                : (json_decode((string) $row->metadata, true) ?: []);

            return [(string) $row->campaign_id => is_array($meta) ? $meta : []];
        })->all();
    }

    private function typedDailyScope(string $table, int $digitalAssetId, int $externalResourceId, string $customerId, string $start, string $end): ?Builder
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reporting_date')) {
            return null;
        }

        $base = DB::table($table)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId)
            ->whereBetween('reporting_date', [$start, $end]);

        if (! Schema::hasColumn($table, 'digital_asset_id')) {
            return $base;
        }

        $central = (clone $base)->whereNull('digital_asset_id')->exists();

        return $central ? $base->whereNull('digital_asset_id') : $base->where('digital_asset_id', $digitalAssetId);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function campaignRow(array $row, string $currency): array
    {
        return [
            'id' => (string) $row['campaign_id'],
            'name' => (string) $row['name'],
            'type' => $this->humanize((string) ($row['channel_type'] ?? 'UNKNOWN')),
            'status' => (string) ($row['status'] ?? 'UNKNOWN'),
            'budget' => isset($row['budget_amount']) ? (float) $row['budget_amount'] : null,
            'spend' => round((float) ($row['cost_amount'] ?? 0), 2),
            'leads' => (float) ($row['conversions'] ?? 0),
            'cpa' => null,
            'pacing' => 'Unavailable',
            'impr_share' => isset($row['search_impression_share']) && $row['search_impression_share'] !== null ? round((float) $row['search_impression_share'] * 100, 1) : null,
            'lost_is_budget' => isset($row['lost_is_budget']) && $row['lost_is_budget'] !== null ? round((float) $row['lost_is_budget'] * 100, 1) : null,
            'lost_is_rank' => isset($row['lost_is_rank']) && $row['lost_is_rank'] !== null ? round((float) $row['lost_is_rank'] * 100, 1) : null,
            'attention' => [],
            'attention_primary' => null,
            'currency' => $row['currency'] ?? $currency,
            'is_pmax' => (bool) ($row['is_pmax'] ?? false),
            'bidding_strategy_type' => $row['bidding_strategy_type'] ?? null,
            'leads_note' => GoogleAdsSpecialistReadService::CONVERSION_NOTE,
        ];
    }

    private function humanize(string $value): string
    {
        return match (strtoupper($value)) {
            'PERFORMANCE_MAX' => 'Performance Max',
            'DEMAND_GEN', 'DISCOVERY' => 'Demand Gen',
            'SEARCH' => 'Search',
            'DISPLAY' => 'Display',
            'SHOPPING' => 'Shopping',
            'VIDEO' => 'Video',
            'EXACT' => 'Exact',
            'PHRASE' => 'Phrase',
            'BROAD' => 'Broad',
            'UNKNOWN', '' => 'Unknown',
            default => Str::title(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $code = trim($currency) !== '' && strtoupper($currency) !== 'XXX' ? strtoupper($currency) : 'N/A';

        return $code.' '.number_format($amount, 2);
    }
}
