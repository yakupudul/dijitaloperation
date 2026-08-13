<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate SQL over the Meta Ads normalized data pool (Prompt 31).
 * Account-level additive KPIs come from meta_campaign_daily ONLY — there is no
 * meta_account_daily table. `spend` is already major currency units — NEVER
 * divide by 1e6 (that is a Google Ads micros assumption and does not apply here).
 *
 * Reach is a de-duplicated, non-additive metric: it must never be summed across
 * days or campaigns to produce a period total. Frequency (impressions/reach) is
 * likewise non-additive and must never be averaged into a period value. Both are
 * therefore only ever surfaced here as a per-row/day MAX "observation", never as
 * an additive period rollup.
 */
class MetaAdsPoolReadRepository
{
    /**
     * Delivery breakdown types actually collected by the Meta Ads collector
     * (Prompt 31). Country is intentionally not in this list.
     *
     * @var list<string>
     */
    public const array DELIVERY_BREAKDOWN_TYPES = ['age', 'gender', 'publisher_platform'];

    /**
     * Additive period sums from meta_campaign_daily ONLY. Reach/frequency are
     * intentionally excluded from this period total — see class docblock.
     *
     * @return array{spend: float, impressions: int, clicks: int, link_clicks: ?int, outbound_clicks: ?int, currency: ?string, rows: int}
     */
    public function campaignDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
    ): array {
        $row = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(spend),0) as spend')
            ->selectRaw('COALESCE(SUM(impressions),0) as impressions')
            ->selectRaw('COALESCE(SUM(clicks),0) as clicks')
            ->selectRaw('COUNT(*) as rows')
            ->selectRaw('MAX(currency) as currency')
            ->first();

        $metadataRows = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->pluck('metadata');

        [$linkClicks, $outboundClicks] = $this->sumClickMetadata($metadataRows);

        return [
            'spend' => (float) ($row->spend ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'link_clicks' => $linkClicks,
            'outbound_clicks' => $outboundClicks,
            'currency' => $row->currency !== null ? (string) $row->currency : null,
            'rows' => (int) ($row->rows ?? 0),
        ];
    }

    /**
     * Daily additive series (spend, impressions, clicks) for trend charts.
     * `reach_observed` is a per-day MAX across campaigns — a labeled daily
     * observation only, never a period rollup and never summed across days.
     *
     * @return list<array{date: string, spend: float, impressions: int, clicks: int, reach_observed: ?int, currency: ?string}>
     */
    public function campaignDailySeries(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
    ): array {
        return DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('reporting_date')
            ->orderBy('reporting_date')
            ->get([
                'reporting_date',
                DB::raw('SUM(spend) as spend'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('MAX(reach) as reach_observed'),
                DB::raw('MAX(currency) as currency'),
            ])
            ->map(static fn ($row): array => [
                'date' => (string) $row->reporting_date,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'reach_observed' => $row->reach_observed !== null ? (int) $row->reach_observed : null,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ])
            ->all();
    }

    /**
     * Campaign aggregates for the range + meta_campaign_snapshot metadata join.
     * `reach_observed` is MAX observed daily reach (never summed) — a ceiling
     * observation only, not a period total. `frequency` is always null for a
     * period (non-additive; see class docblock).
     *
     * @return list<array<string, mixed>>
     */
    public function campaignPerformance(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
        int $limit = 200,
    ): array {
        $perf = DB::table('meta_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('campaign_id')
            ->orderByDesc(DB::raw('SUM(spend)'))
            ->limit($limit)
            ->get([
                'campaign_id',
                DB::raw('SUM(spend) as spend'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('MAX(reach) as reach_observed'),
                DB::raw('MAX(currency) as currency'),
                DB::raw('COUNT(*) as rows'),
            ]);

        $campaignIds = $perf->pluck('campaign_id')->all();

        $metadataByCampaign = collect();
        if ($campaignIds !== []) {
            $metadataByCampaign = DB::table('meta_campaign_daily')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('account_id', $accountId)
                ->whereBetween('reporting_date', [$start, $end])
                ->whereIn('campaign_id', $campaignIds)
                ->get(['campaign_id', 'metadata'])
                ->groupBy('campaign_id');
        }

        $snapshots = collect();
        if ($campaignIds !== []) {
            $snapshots = DB::table('meta_campaign_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('account_id', $accountId)
                ->whereIn('campaign_id', $campaignIds)
                ->get(['campaign_id', 'metadata'])
                ->keyBy('campaign_id');
        }

        $rows = [];
        foreach ($perf as $row) {
            $campaignId = (string) $row->campaign_id;
            $rawMetaRows = $metadataByCampaign->get($campaignId, collect())->pluck('metadata');
            [$linkClicks, $outboundClicks] = $this->sumClickMetadata($rawMetaRows);

            $snapMeta = $snapshots->has($campaignId)
                ? $this->decodeMetadata($snapshots->get($campaignId)->metadata)
                : [];

            $rows[] = [
                'campaign_id' => $campaignId,
                'name' => (string) ($snapMeta['name'] ?? ('Campaign '.$campaignId)),
                'objective' => $snapMeta['objective'] ?? null,
                'status' => $snapMeta['status'] ?? 'UNKNOWN',
                'effective_status' => $snapMeta['effective_status'] ?? null,
                'daily_budget' => isset($snapMeta['daily_budget']) && $snapMeta['daily_budget'] !== null ? (float) $snapMeta['daily_budget'] : null,
                'lifetime_budget' => isset($snapMeta['lifetime_budget']) && $snapMeta['lifetime_budget'] !== null ? (float) $snapMeta['lifetime_budget'] : null,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'link_clicks' => $linkClicks,
                'outbound_clicks' => $outboundClicks,
                'reach_observed' => $row->reach_observed !== null ? (int) $row->reach_observed : null,
                'frequency' => null,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'rows' => (int) $row->rows,
            ];
        }

        return $rows;
    }

    /**
     * Ad set aggregates for the range + meta_adset_snapshot metadata join
     * (name, status, optimization_goal, destination_type, campaign linkage).
     * `reach_observed` is MAX observed daily reach — never summed. There is no
     * frequency column at ad set grain.
     *
     * @return list<array<string, mixed>>
     */
    public function adsetPerformance(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
        int $limit = 500,
    ): array {
        $perf = DB::table('meta_adset_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('adset_id')
            ->orderByDesc(DB::raw('SUM(spend)'))
            ->limit($limit)
            ->get([
                'adset_id',
                DB::raw('SUM(spend) as spend'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('MAX(reach) as reach_observed'),
                DB::raw('MAX(currency) as currency'),
            ]);

        $adsetIds = $perf->pluck('adset_id')->all();
        $snapshots = collect();
        if ($adsetIds !== []) {
            $snapshots = DB::table('meta_adset_snapshot')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('account_id', $accountId)
                ->whereIn('adset_id', $adsetIds)
                ->get(['adset_id', 'metadata'])
                ->keyBy('adset_id');
        }

        $rows = [];
        foreach ($perf as $row) {
            $adsetId = (string) $row->adset_id;
            $meta = $snapshots->has($adsetId) ? $this->decodeMetadata($snapshots->get($adsetId)->metadata) : [];

            $rows[] = [
                'adset_id' => $adsetId,
                'campaign_id' => isset($meta['campaign_id']) && $meta['campaign_id'] !== null ? (string) $meta['campaign_id'] : null,
                'name' => (string) ($meta['name'] ?? ('Ad set '.$adsetId)),
                'status' => $meta['status'] ?? 'UNKNOWN',
                'effective_status' => $meta['effective_status'] ?? null,
                'optimization_goal' => $meta['optimization_goal'] ?? null,
                'destination_type' => $meta['destination_type'] ?? null,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'reach_observed' => $row->reach_observed !== null ? (int) $row->reach_observed : null,
                'frequency' => null,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ];
        }

        return $rows;
    }

    /**
     * Ad aggregates from meta_ad_daily + best-effort creative_id linkage from ad
     * metadata (not guaranteed to be populated by the collector). Ads are
     * aggregated by ad_id first, then callers may group by creative_id — this
     * method never fans out spend across multiple creatives for one ad.
     *
     * @return list<array<string, mixed>>
     */
    public function topAdsWithCreatives(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
        int $limit = 500,
    ): array {
        $perf = DB::table('meta_ad_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('ad_id')
            ->orderByDesc(DB::raw('SUM(spend)'))
            ->limit($limit)
            ->get([
                'ad_id',
                DB::raw('SUM(spend) as spend'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('MAX(reach) as reach_observed'),
                DB::raw('MAX(currency) as currency'),
            ]);

        $adIds = $perf->pluck('ad_id')->all();
        $metadataByAd = collect();
        if ($adIds !== []) {
            $metadataByAd = DB::table('meta_ad_daily')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('account_id', $accountId)
                ->whereBetween('reporting_date', [$start, $end])
                ->whereIn('ad_id', $adIds)
                ->get(['ad_id', 'metadata'])
                ->groupBy('ad_id');
        }

        $rows = [];
        foreach ($perf as $row) {
            $adId = (string) $row->ad_id;
            $rawMetaRows = $metadataByAd->get($adId, collect())->pluck('metadata');
            [$linkClicks, $outboundClicks] = $this->sumClickMetadata($rawMetaRows);

            $campaignId = null;
            $adsetId = null;
            $creativeId = null;
            foreach ($rawMetaRows as $rawMeta) {
                $meta = $this->decodeMetadata($rawMeta);
                $campaignId ??= isset($meta['campaign_id']) && $meta['campaign_id'] !== null ? (string) $meta['campaign_id'] : null;
                $adsetId ??= isset($meta['adset_id']) && $meta['adset_id'] !== null ? (string) $meta['adset_id'] : null;
                $creativeId ??= isset($meta['creative_id']) && $meta['creative_id'] !== null ? (string) $meta['creative_id'] : null;
            }

            $rows[] = [
                'ad_id' => $adId,
                'campaign_id' => $campaignId,
                'adset_id' => $adsetId,
                'creative_id' => $creativeId,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'link_clicks' => $linkClicks,
                'outbound_clicks' => $outboundClicks,
                'reach_observed' => $row->reach_observed !== null ? (int) $row->reach_observed : null,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function creativeSnapshots(int $digitalAssetId, string $accountId, int $limit = 200): array
    {
        return DB::table('meta_creative_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('account_id', $accountId)
            ->orderBy('creative_id')
            ->limit($limit)
            ->get(['creative_id', 'metadata'])
            ->map(function ($row): array {
                $meta = $this->decodeMetadata($row->metadata);

                return [
                    'creative_id' => (string) $row->creative_id,
                    'name' => $meta['name'] ?? null,
                    'object_type' => $meta['object_type'] ?? null,
                    'status' => $meta['status'] ?? null,
                    'title' => $meta['title'] ?? null,
                    'body' => $meta['body'] ?? null,
                    'call_to_action_type' => $meta['call_to_action_type'] ?? null,
                    'link_url' => $meta['link_url'] ?? null,
                    'thumbnail_url' => $meta['thumbnail_url'] ?? null,
                    'page_id' => $meta['page_id'] ?? null,
                    'instagram_actor_id' => $meta['instagram_actor_id'] ?? null,
                ];
            })
            ->all();
    }

    /**
     * Delivery breakdown aggregates for one breakdown_type (age|gender|publisher_platform
     * ONLY — those are the only breakdown types the collector materializes in
     * Prompt 31; any other type returns an empty list rather than a partial/incorrect
     * result). `reach_observed` is MAX per breakdown value — never summed.
     *
     * @return list<array{breakdown_value: string, spend: float, impressions: int, clicks: int, reach_observed: ?int, currency: ?string}>
     */
    public function deliveryBreakdowns(
        string $breakdownType,
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
    ): array {
        if (! in_array($breakdownType, self::DELIVERY_BREAKDOWN_TYPES, true)) {
            return [];
        }

        return DB::table('meta_delivery_breakdown_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->where('breakdown_type', $breakdownType)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('breakdown_value')
            ->orderByDesc(DB::raw('SUM(spend)'))
            ->get([
                'breakdown_value',
                DB::raw('SUM(spend) as spend'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(clicks) as clicks'),
                DB::raw('MAX(reach) as reach_observed'),
                DB::raw('MAX(currency) as currency'),
            ])
            ->map(static fn ($row): array => [
                'breakdown_value' => (string) $row->breakdown_value,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'reach_observed' => $row->reach_observed !== null ? (int) $row->reach_observed : null,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
            ])
            ->all();
    }

    /**
     * Typed action totals grouped by action_type. Defaults to entity_level='campaign'
     * to avoid double-counting the same conversion event across campaign/adset/ad
     * grains for an account-level view. action_type is always kept distinct —
     * callers must never sum across action_type into a generic "Results" total.
     *
     * @return list<array{action_type: string, action_value: float, currency: ?string, rows: int}>
     */
    public function typedActions(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        string $start,
        string $end,
        string $entityLevel = 'campaign',
    ): array {
        return DB::table('meta_typed_action_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->where('entity_level', $entityLevel)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('action_type')
            ->orderByDesc(DB::raw('SUM(action_value)'))
            ->get([
                'action_type',
                DB::raw('SUM(action_value) as action_value'),
                DB::raw('MAX(currency) as currency'),
                DB::raw('COUNT(*) as rows'),
            ])
            ->map(static fn ($row): array => [
                'action_type' => (string) $row->action_type,
                'action_value' => (float) $row->action_value,
                'currency' => $row->currency !== null ? (string) $row->currency : null,
                'rows' => (int) $row->rows,
            ])
            ->all();
    }

    /**
     * Sums `inline_link_clicks` and `outbound_clicks` out of per-row JSON metadata
     * in PHP (never via a database JSON function) so this works identically on
     * SQLite (tests) and PostgreSQL (production). Returns null for a field when
     * no row carried it — missing is never silently treated as zero.
     *
     * @param  Collection<int, mixed>  $metadataRows
     * @return array{0: ?int, 1: ?int} [link_clicks, outbound_clicks]
     */
    private function sumClickMetadata(Collection $metadataRows): array
    {
        $linkClicks = null;
        $outboundClicks = null;

        foreach ($metadataRows as $raw) {
            $meta = $this->decodeMetadata($raw);

            if (array_key_exists('inline_link_clicks', $meta) && $meta['inline_link_clicks'] !== null) {
                $linkClicks = ($linkClicks ?? 0) + (int) $meta['inline_link_clicks'];
            }

            if (array_key_exists('outbound_clicks', $meta) && $meta['outbound_clicks'] !== null) {
                $outboundClicks = ($outboundClicks ?? 0) + (int) $meta['outbound_clicks'];
            }
        }

        return [$linkClicks, $outboundClicks];
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
}
