<?php

namespace App\Services\MetaAds;

use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Services\MetaAds\Support\MetaAdsDatasetReadiness;
use App\Support\Operator\OperatorReportingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only presentation model for the professional Meta Ads workspace.
 *
 * This service never calls Meta Graph. It reads only already-collected local
 * Data Pool tables and respects the same dataset readiness gate used by the
 * existing Meta Ads specialist workspace.
 */
final class MetaAdsProfessionalWorkspaceReadService
{
    private const string ACCOUNT_DAILY = 'meta_account_daily';
    private const string CAMPAIGN_DAILY = 'meta_campaign_daily';
    private const string ADSET_DAILY = 'meta_adset_daily';
    private const string AD_DAILY = 'meta_ad_daily';
    private const string TYPED_ACTION_DAILY = 'meta_typed_action_daily';
    private const string VIDEO_DAILY = 'meta_video_engagement_daily';
    private const string BREAKDOWN_DAILY = 'meta_analysis_breakdown_daily';
    private const string HOURLY_DAILY = 'meta_hourly_daily';
    private const string AD_SNAPSHOT = 'meta_ad_snapshot';
    private const string TARGETING_SNAPSHOT = 'meta_adset_targeting_snapshot';
    private const string CONVERSION_SOURCE_SNAPSHOT = 'meta_conversion_source_snapshot';
    private const string CHANGE_EVENT = 'meta_change_event';
    private const string CREATIVE_SNAPSHOT = 'meta_creative_snapshot';

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindingResolver,
        private readonly MetaAdsUiDatasetGate $gate,
        private readonly MetaAdsPoolReadRepository $pool,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $previous = OperatorReportingPeriod::previousQueryBounds($preset, $start, $end);

        $empty = $this->emptyWorkspace(
            $bounds['start']->toDateString(),
            $bounds['end']->toDateString(),
            $binding->currency,
            $binding->timezone,
            $binding->accountId,
        );

        if ($binding->mode !== MetaAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->accountId === null
        ) {
            return $empty;
        }

        $digitalAssetId = $binding->digitalAssetId;
        $externalResourceId = $binding->externalResourceId;
        $accountId = $binding->accountId;
        $timezone = $binding->timezone ?? 'UTC';
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prevStart = $previous['start']->toDateString();
        $prevEnd = $previous['end']->toDateString();

        try {
            $gates = $this->datasetGates(
                $digitalAssetId,
                $externalResourceId,
                $timezone,
                $rangeStart,
                $rangeEnd,
            );

            $accountGate = $gates[self::ACCOUNT_DAILY];
            $campaignGate = $gates[self::CAMPAIGN_DAILY];
            $adsetGate = $gates[self::ADSET_DAILY];
            $adGate = $gates[self::AD_DAILY];
            $typedActionGate = $gates[self::TYPED_ACTION_DAILY];
            $videoGate = $gates[self::VIDEO_DAILY];
            $breakdownGate = $gates[self::BREAKDOWN_DAILY];
            $hourlyGate = $gates[self::HOURLY_DAILY];
            $adSnapshotGate = $gates[self::AD_SNAPSHOT];
            $targetingGate = $gates[self::TARGETING_SNAPSHOT];
            $conversionGate = $gates[self::CONVERSION_SOURCE_SNAPSHOT];
            $changeGate = $gates[self::CHANGE_EVENT];
            $creativeGate = $gates[self::CREATIVE_SNAPSHOT];

            $prevAccountGate = $this->safeEvaluate(
                $digitalAssetId,
                $externalResourceId,
                self::ACCOUNT_DAILY,
                $prevStart,
                $prevEnd,
                $timezone,
            );
            $prevCampaignGate = $this->safeEvaluate(
                $digitalAssetId,
                $externalResourceId,
                self::CAMPAIGN_DAILY,
                $prevStart,
                $prevEnd,
                $timezone,
            );

            $sums = null;
            $previousSums = null;
            $metricSource = null;

            if ($accountGate->isUsable() && Schema::hasTable(self::ACCOUNT_DAILY)) {
                $sums = $this->accountDailySums(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $accountGate->effectiveStart ?? $rangeStart,
                    $accountGate->effectiveEnd ?? $rangeEnd,
                );
                $metricSource = self::ACCOUNT_DAILY;

                if ($prevAccountGate->isUsable()) {
                    $previousSums = $this->accountDailySums(
                        $digitalAssetId,
                        $externalResourceId,
                        $accountId,
                        $prevAccountGate->effectiveStart ?? $prevStart,
                        $prevAccountGate->effectiveEnd ?? $prevEnd,
                    );
                }
            } elseif ($campaignGate->isUsable()) {
                $sums = $this->pool->campaignDailySums(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $campaignGate->effectiveStart ?? $rangeStart,
                    $campaignGate->effectiveEnd ?? $rangeEnd,
                );
                $metricSource = self::CAMPAIGN_DAILY;

                if ($prevCampaignGate->isUsable()) {
                    $previousSums = $this->pool->campaignDailySums(
                        $digitalAssetId,
                        $externalResourceId,
                        $accountId,
                        $prevCampaignGate->effectiveStart ?? $prevStart,
                        $prevCampaignGate->effectiveEnd ?? $prevEnd,
                    );
                }
            }

            $currency = strtoupper((string) ($sums['currency'] ?? $binding->currency ?? 'XXX'));

            $campaignRows = $campaignGate->isUsable()
                ? $this->pool->campaignPerformance(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $campaignGate->effectiveStart ?? $rangeStart,
                    $campaignGate->effectiveEnd ?? $rangeEnd,
                )
                : [];

            $adsetRows = $adsetGate->isUsable()
                ? $this->pool->adsetPerformance(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $adsetGate->effectiveStart ?? $rangeStart,
                    $adsetGate->effectiveEnd ?? $rangeEnd,
                )
                : [];

            $adPerformance = $adGate->isUsable()
                ? $this->pool->topAdsWithCreatives(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $adGate->effectiveStart ?? $rangeStart,
                    $adGate->effectiveEnd ?? $rangeEnd,
                )
                : [];

            $campaigns = $this->presentCampaigns($campaignRows, $currency);
            $campaignNames = collect($campaigns)->pluck('name', 'id')->all();
            $adsets = $this->presentAdsets($adsetRows, $campaignNames, $currency);
            $adsetNames = collect($adsets)->pluck('name', 'id')->all();

            $adSnapshots = $adSnapshotGate->isUsable()
                ? $this->adSnapshots($digitalAssetId, $externalResourceId, $accountId)
                : [];
            $ads = $this->presentAds($adPerformance, $adSnapshots, $campaignNames, $adsetNames, $currency);

            $creativeSnapshots = $creativeGate->isUsable()
                ? $this->pool->creativeSnapshots($digitalAssetId, $accountId)
                : [];
            $video = $videoGate->isUsable()
                ? $this->videoMetrics(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $videoGate->effectiveStart ?? $rangeStart,
                    $videoGate->effectiveEnd ?? $rangeEnd,
                )
                : [];
            $creatives = $this->presentCreatives($creativeSnapshots, $ads, $video, $currency);

            $breakdowns = $breakdownGate->isUsable()
                ? $this->breakdowns(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $breakdownGate->effectiveStart ?? $rangeStart,
                    $breakdownGate->effectiveEnd ?? $rangeEnd,
                )
                : $this->emptyBreakdowns();

            $hourly = $hourlyGate->isUsable()
                ? $this->hourly(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $hourlyGate->effectiveStart ?? $rangeStart,
                    $hourlyGate->effectiveEnd ?? $rangeEnd,
                )
                : [];

            $targeting = $targetingGate->isUsable()
                ? $this->targetingSnapshots($digitalAssetId, $externalResourceId, $accountId)
                : [];

            $typedActions = $typedActionGate->isUsable()
                ? $this->pool->typedActions(
                    $digitalAssetId,
                    $externalResourceId,
                    $accountId,
                    $typedActionGate->effectiveStart ?? $rangeStart,
                    $typedActionGate->effectiveEnd ?? $rangeEnd,
                    'ad',
                )
                : [];

            $conversionSources = $conversionGate->isUsable()
                ? $this->conversionSources($digitalAssetId, $externalResourceId, $accountId)
                : [];

            $changes = $changeGate->isUsable()
                ? $this->changeHistory($digitalAssetId, $externalResourceId, $accountId, $rangeStart, $rangeEnd)
                : [];

            $trend = $this->performanceTrend(
                $digitalAssetId,
                $externalResourceId,
                $accountId,
                $accountGate,
                $campaignGate,
                $rangeStart,
                $rangeEnd,
            );

            return [
                'available' => true,
                'account_id' => $accountId,
                'act_id' => $binding->actId,
                'currency' => $currency,
                'timezone' => $timezone,
                'period_start' => $rangeStart,
                'period_end' => $rangeEnd,
                'metric_source' => $metricSource,
                'kpis' => $this->kpis($sums, $previousSums, $currency, $metricSource),
                'trend' => $trend,
                'campaigns' => $campaigns,
                'adsets' => $adsets,
                'ads' => $ads,
                'creatives' => $creatives,
                'breakdowns' => $breakdowns,
                'hourly' => $hourly,
                'targeting' => $targeting,
                'typed_actions' => $this->presentTypedActions($typedActions),
                'conversion_sources' => $conversionSources,
                'change_history' => $changes,
                'datasets' => array_map(static fn (MetaAdsDatasetReadiness $readiness): array => $readiness->toArray(), $gates),
                'health' => $this->health($gates),
                'notes' => [
                    'results' => 'Generic Results, CPL and ROAS are intentionally not calculated until a canonical typed-action → business-outcome mapping exists.',
                    'reach' => 'Period Reach and Frequency are intentionally not aggregated because Meta reach is de-duplicated and frequency is non-additive.',
                ],
            ];
        } catch (Throwable $e) {
            $empty['error'] = $e->getMessage();

            return $empty;
        }
    }

    /** @return array<string, MetaAdsDatasetReadiness> */
    private function datasetGates(
        int $digitalAssetId,
        int $externalResourceId,
        string $timezone,
        string $start,
        string $end,
    ): array {
        return [
            self::ACCOUNT_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::ACCOUNT_DAILY, $start, $end, $timezone),
            self::CAMPAIGN_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::CAMPAIGN_DAILY, $start, $end, $timezone),
            self::ADSET_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::ADSET_DAILY, $start, $end, $timezone),
            self::AD_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::AD_DAILY, $start, $end, $timezone),
            self::TYPED_ACTION_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::TYPED_ACTION_DAILY, $start, $end, $timezone),
            self::VIDEO_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::VIDEO_DAILY, $start, $end, $timezone),
            self::BREAKDOWN_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::BREAKDOWN_DAILY, $start, $end, $timezone),
            self::HOURLY_DAILY => $this->safeEvaluate($digitalAssetId, $externalResourceId, self::HOURLY_DAILY, $start, $end, $timezone),
            self::AD_SNAPSHOT => $this->safeEvaluateSnapshot($digitalAssetId, $externalResourceId, self::AD_SNAPSHOT, $timezone),
            self::TARGETING_SNAPSHOT => $this->safeEvaluateSnapshot($digitalAssetId, $externalResourceId, self::TARGETING_SNAPSHOT, $timezone),
            self::CONVERSION_SOURCE_SNAPSHOT => $this->safeEvaluateSnapshot($digitalAssetId, $externalResourceId, self::CONVERSION_SOURCE_SNAPSHOT, $timezone),
            self::CHANGE_EVENT => $this->safeEvaluateSnapshot($digitalAssetId, $externalResourceId, self::CHANGE_EVENT, $timezone),
            self::CREATIVE_SNAPSHOT => $this->safeEvaluateSnapshot($digitalAssetId, $externalResourceId, self::CREATIVE_SNAPSHOT, $timezone),
        ];
    }

    private function safeEvaluate(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        string $start,
        string $end,
        string $timezone,
    ): MetaAdsDatasetReadiness {
        try {
            return $this->gate->evaluate($digitalAssetId, $externalResourceId, $datasetId, $start, $end, $timezone);
        } catch (Throwable) {
            return $this->unavailableReadiness($datasetId);
        }
    }

    private function safeEvaluateSnapshot(
        int $digitalAssetId,
        int $externalResourceId,
        string $datasetId,
        string $timezone,
    ): MetaAdsDatasetReadiness {
        try {
            return $this->gate->evaluateSnapshot($digitalAssetId, $externalResourceId, $datasetId, $timezone);
        } catch (Throwable) {
            return $this->unavailableReadiness($datasetId);
        }
    }

    private function unavailableReadiness(string $datasetId): MetaAdsDatasetReadiness
    {
        return new MetaAdsDatasetReadiness(
            datasetId: $datasetId,
            integrityReady: false,
            integrityStatus: 'UNAVAILABLE',
            integrityAuditRunUuid: null,
            freshnessState: 'UNKNOWN',
            coverageState: MetaAdsDatasetReadiness::COVERAGE_NOT_COVERED,
            coveredDates: [],
            effectiveStart: null,
            effectiveEnd: null,
            materializationExists: false,
        );
    }

    /** @return array<string, mixed> */
    private function accountDailySums(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable(self::ACCOUNT_DAILY)) {
            return ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'link_clicks' => null, 'outbound_clicks' => null, 'currency' => null, 'rows' => 0];
        }

        $row = DB::table(self::ACCOUNT_DAILY)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->selectRaw('SUM(inline_link_clicks) AS link_clicks')
            ->selectRaw('SUM(outbound_clicks) AS outbound_clicks')
            ->selectRaw('MAX(currency) AS currency')
            ->selectRaw('COUNT(*) AS rows')
            ->first();

        return [
            'spend' => (float) ($row->spend ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'link_clicks' => $row?->link_clicks !== null ? (int) $row->link_clicks : null,
            'outbound_clicks' => $row?->outbound_clicks !== null ? (int) $row->outbound_clicks : null,
            'currency' => $row?->currency !== null ? (string) $row->currency : null,
            'rows' => (int) ($row->rows ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function kpis(?array $sums, ?array $previous, string $currency, ?string $source): array
    {
        if ($sums === null) {
            return [];
        }

        $spend = (float) ($sums['spend'] ?? 0);
        $impressions = (int) ($sums['impressions'] ?? 0);
        $clicks = (int) ($sums['clicks'] ?? 0);
        $linkClicks = isset($sums['link_clicks']) && $sums['link_clicks'] !== null ? (int) $sums['link_clicks'] : null;
        $outboundClicks = isset($sums['outbound_clicks']) && $sums['outbound_clicks'] !== null ? (int) $sums['outbound_clicks'] : null;

        $previousSpend = (float) ($previous['spend'] ?? 0);
        $previousImpressions = (int) ($previous['impressions'] ?? 0);
        $previousClicks = (int) ($previous['clicks'] ?? 0);

        $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : null;
        $cpc = $clicks > 0 ? $spend / $clicks : null;
        $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : null;
        $previousCtr = $previousImpressions > 0 ? ($previousClicks / $previousImpressions) * 100 : null;
        $previousCpc = $previousClicks > 0 ? $previousSpend / $previousClicks : null;
        $previousCpm = $previousImpressions > 0 ? ($previousSpend / $previousImpressions) * 1000 : null;

        return [
            'spend' => $this->metric($spend, $previous !== null ? $previousSpend : null, $this->money($spend, $currency), $source),
            'impressions' => $this->metric($impressions, $previous !== null ? $previousImpressions : null, number_format($impressions), $source),
            'clicks' => $this->metric($clicks, $previous !== null ? $previousClicks : null, number_format($clicks), $source),
            'ctr' => $this->metric($ctr, $previous !== null ? $previousCtr : null, $ctr !== null ? number_format($ctr, 2).'%' : '—', $source),
            'cpc' => $this->metric($cpc, $previous !== null ? $previousCpc : null, $cpc !== null ? $this->money($cpc, $currency) : '—', $source),
            'cpm' => $this->metric($cpm, $previous !== null ? $previousCpm : null, $cpm !== null ? $this->money($cpm, $currency) : '—', $source),
            'link_clicks' => [
                'raw' => $linkClicks,
                'display' => $linkClicks !== null ? number_format($linkClicks) : '—',
                'delta_pct' => null,
                'source' => $source,
            ],
            'outbound_clicks' => [
                'raw' => $outboundClicks,
                'display' => $outboundClicks !== null ? number_format($outboundClicks) : '—',
                'delta_pct' => null,
                'source' => $source,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function metric(int|float|null $value, int|float|null $previous, string $display, ?string $source): array
    {
        return [
            'raw' => $value,
            'display' => $display,
            'delta_pct' => $this->relativeChange($value, $previous),
            'source' => $source,
        ];
    }

    private function relativeChange(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    /** @return list<array<string, mixed>> */
    private function performanceTrend(
        int $digitalAssetId,
        int $externalResourceId,
        string $accountId,
        MetaAdsDatasetReadiness $accountGate,
        MetaAdsDatasetReadiness $campaignGate,
        string $start,
        string $end,
    ): array {
        $rows = [];

        if ($accountGate->isUsable() && Schema::hasTable(self::ACCOUNT_DAILY)) {
            $rows = DB::table(self::ACCOUNT_DAILY)
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('account_id', $accountId)
                ->whereBetween('reporting_date', [$accountGate->effectiveStart ?? $start, $accountGate->effectiveEnd ?? $end])
                ->orderBy('reporting_date')
                ->get(['reporting_date', 'spend', 'impressions', 'clicks'])
                ->map(static fn ($row): array => [
                    'date' => (string) $row->reporting_date,
                    'spend' => (float) $row->spend,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                ])
                ->all();
        } elseif ($campaignGate->isUsable()) {
            $rows = $this->pool->campaignDailySeries(
                $digitalAssetId,
                $externalResourceId,
                $accountId,
                $campaignGate->effectiveStart ?? $start,
                $campaignGate->effectiveEnd ?? $end,
            );
        }

        return array_map(static function (array $row): array {
            $spend = (float) ($row['spend'] ?? 0);
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);

            return [
                'date' => (string) $row['date'],
                'spend' => round($spend, 2),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
                'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            ];
        }, $rows);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function presentCampaigns(array $rows, string $currency): array
    {
        return array_map(function (array $row) use ($currency): array {
            return array_merge($this->performanceMetrics($row, $currency), [
                'id' => (string) $row['campaign_id'],
                'name' => (string) $row['name'],
                'status' => $row['status'] ?? 'UNKNOWN',
                'effective_status' => $row['effective_status'] ?? null,
                'objective' => $row['objective'] ?? null,
                'daily_budget' => $row['daily_budget'] ?? null,
                'lifetime_budget' => $row['lifetime_budget'] ?? null,
            ]);
        }, $rows);
    }

    /** @param list<array<string,mixed>> $rows @param array<string,string> $campaignNames @return list<array<string,mixed>> */
    private function presentAdsets(array $rows, array $campaignNames, string $currency): array
    {
        return array_map(function (array $row) use ($campaignNames, $currency): array {
            $campaignId = $row['campaign_id'] !== null ? (string) $row['campaign_id'] : null;

            return array_merge($this->performanceMetrics($row, $currency), [
                'id' => (string) $row['adset_id'],
                'name' => (string) $row['name'],
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId !== null ? ($campaignNames[$campaignId] ?? 'Campaign '.$campaignId) : null,
                'status' => $row['status'] ?? 'UNKNOWN',
                'effective_status' => $row['effective_status'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'destination_type' => $row['destination_type'] ?? null,
            ]);
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    private function adSnapshots(int $digitalAssetId, int $externalResourceId, string $accountId): array
    {
        if (! Schema::hasTable(self::AD_SNAPSHOT)) {
            return [];
        }

        return DB::table(self::AD_SNAPSHOT)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->get([
                'ad_id', 'ad_name', 'campaign_id', 'adset_id', 'creative_id',
                'status', 'effective_status', 'created_time', 'updated_time',
            ])
            ->map(static fn ($row): array => [
                'ad_id' => (string) $row->ad_id,
                'ad_name' => $row->ad_name !== null ? (string) $row->ad_name : null,
                'campaign_id' => $row->campaign_id !== null ? (string) $row->campaign_id : null,
                'adset_id' => $row->adset_id !== null ? (string) $row->adset_id : null,
                'creative_id' => $row->creative_id !== null ? (string) $row->creative_id : null,
                'status' => $row->status !== null ? (string) $row->status : null,
                'effective_status' => $row->effective_status !== null ? (string) $row->effective_status : null,
                'created_time' => $row->created_time !== null ? (string) $row->created_time : null,
                'updated_time' => $row->updated_time !== null ? (string) $row->updated_time : null,
            ])
            ->all();
    }

    /** @param list<array<string,mixed>> $performance @param list<array<string,mixed>> $snapshots @return list<array<string,mixed>> */
    private function presentAds(array $performance, array $snapshots, array $campaignNames, array $adsetNames, string $currency): array
    {
        $perf = collect($performance)->keyBy('ad_id');
        $snapshotMap = collect($snapshots)->keyBy('ad_id');
        $ids = $snapshotMap->keys()->merge($perf->keys())->unique()->values();

        return $ids->map(function (string $adId) use ($perf, $snapshotMap, $campaignNames, $adsetNames, $currency): array {
            $row = $perf->get($adId, []);
            $snapshot = $snapshotMap->get($adId, []);
            $campaignId = $snapshot['campaign_id'] ?? ($row['campaign_id'] ?? null);
            $adsetId = $snapshot['adset_id'] ?? ($row['adset_id'] ?? null);
            $creativeId = $snapshot['creative_id'] ?? ($row['creative_id'] ?? null);

            return array_merge($this->performanceMetrics($row, $currency), [
                'id' => $adId,
                'name' => $snapshot['ad_name'] ?? ('Ad '.$adId),
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignId !== null ? ($campaignNames[(string) $campaignId] ?? 'Campaign '.$campaignId) : null,
                'adset_id' => $adsetId,
                'adset_name' => $adsetId !== null ? ($adsetNames[(string) $adsetId] ?? 'Ad set '.$adsetId) : null,
                'creative_id' => $creativeId,
                'status' => $snapshot['status'] ?? 'UNKNOWN',
                'effective_status' => $snapshot['effective_status'] ?? null,
                'created_time' => $snapshot['created_time'] ?? null,
                'updated_time' => $snapshot['updated_time'] ?? null,
            ]);
        })->sortByDesc('spend')->values()->all();
    }

    /** @return array<string,mixed> */
    private function performanceMetrics(array $row, string $currency): array
    {
        $spend = (float) ($row['spend'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $linkClicks = isset($row['link_clicks']) && $row['link_clicks'] !== null ? (int) $row['link_clicks'] : null;

        return [
            'spend' => round($spend, 2),
            'spend_display' => $this->money($spend, (string) ($row['currency'] ?? $currency)),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'link_clicks' => $linkClicks,
            'outbound_clicks' => isset($row['outbound_clicks']) && $row['outbound_clicks'] !== null ? (int) $row['outbound_clicks'] : null,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            'currency' => (string) ($row['currency'] ?? $currency),
        ];
    }

    /** @param list<array<string,mixed>> $snapshots @param list<array<string,mixed>> $ads @param array<string,array<string,float>> $video @return list<array<string,mixed>> */
    private function presentCreatives(array $snapshots, array $ads, array $video, string $currency): array
    {
        $adsByCreative = collect($ads)->filter(static fn (array $ad): bool => filled($ad['creative_id'] ?? null))->groupBy('creative_id');

        return collect($snapshots)->map(function (array $snapshot) use ($adsByCreative, $video, $currency): array {
            $creativeId = (string) $snapshot['creative_id'];
            $creativeAds = $adsByCreative->get($creativeId, collect());
            $spend = (float) $creativeAds->sum('spend');
            $impressions = (int) $creativeAds->sum('impressions');
            $clicks = (int) $creativeAds->sum('clicks');
            $campaignNames = $creativeAds->pluck('campaign_name')->filter()->unique()->values()->all();
            $adIds = $creativeAds->pluck('id')->all();
            $videoTotals = [];
            foreach ($adIds as $adId) {
                foreach (($video[(string) $adId] ?? []) as $metric => $value) {
                    $videoTotals[$metric] = ($videoTotals[$metric] ?? 0) + $value;
                }
            }

            $thumbnail = $snapshot['thumbnail_url'] ?? null;
            if (! is_string($thumbnail) || ! preg_match('#^https?://#i', $thumbnail)) {
                $thumbnail = null;
            }

            return [
                'id' => $creativeId,
                'name' => $snapshot['name'] ?? ('Creative '.$creativeId),
                'format' => $snapshot['object_type'] ?? 'Unknown',
                'status' => $snapshot['status'] ?? 'UNKNOWN',
                'title' => $snapshot['title'] ?? null,
                'body' => $snapshot['body'] ?? null,
                'cta' => $snapshot['call_to_action_type'] ?? null,
                'link_url' => $snapshot['link_url'] ?? null,
                'thumbnail_url' => $thumbnail,
                'campaigns' => $campaignNames,
                'ad_count' => $creativeAds->count(),
                'spend' => round($spend, 2),
                'spend_display' => $this->money($spend, $currency),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
                'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
                'video' => $videoTotals,
            ];
        })->sortByDesc('spend')->values()->all();
    }

    /** @return array<string,array<string,float>> */
    private function videoMetrics(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable(self::VIDEO_DAILY)) {
            return [];
        }

        $rows = DB::table(self::VIDEO_DAILY)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('ad_id', 'metric_type')
            ->get(['ad_id', 'metric_type', DB::raw('SUM(metric_value) AS metric_value')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->ad_id][(string) $row->metric_type] = (float) $row->metric_value;
        }

        return $out;
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function breakdowns(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable(self::BREAKDOWN_DAILY)) {
            return $this->emptyBreakdowns();
        }

        $rows = DB::table(self::BREAKDOWN_DAILY)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('breakdown_type', 'breakdown_key')
            ->get([
                'breakdown_type', 'breakdown_key',
                DB::raw('SUM(spend) AS spend'),
                DB::raw('SUM(impressions) AS impressions'),
                DB::raw('SUM(clicks) AS clicks'),
            ]);

        $decoded = $rows->map(function ($row): array {
            $dimensions = $this->jsonArray($row->breakdown_key);
            $spend = (float) $row->spend;
            $impressions = (int) $row->impressions;
            $clicks = (int) $row->clicks;

            return [
                'type' => (string) $row->breakdown_type,
                'dimensions' => $dimensions,
                'spend' => $spend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            ];
        })->all();

        return [
            'country' => $this->aggregateDimension($decoded, 'country', 'country'),
            'age' => $this->aggregateDimension($decoded, 'demographic', 'age'),
            'gender' => $this->aggregateDimension($decoded, 'demographic', 'gender'),
            'publisher_platform' => $this->aggregateDimension($decoded, 'placement', 'publisher_platform'),
            'platform_position' => $this->aggregateDimension($decoded, 'placement', 'platform_position'),
            'device' => $this->aggregateDimension($decoded, 'device', 'impression_device'),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function aggregateDimension(array $rows, string $type, string $dimension): array
    {
        $aggregated = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? null) !== $type) {
                continue;
            }
            $value = $row['dimensions'][$dimension] ?? null;
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $key = (string) $value;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = ['label' => $this->humanize($key), 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0];
            }
            $aggregated[$key]['spend'] += (float) $row['spend'];
            $aggregated[$key]['impressions'] += (int) $row['impressions'];
            $aggregated[$key]['clicks'] += (int) $row['clicks'];
        }

        $totalSpend = array_sum(array_column($aggregated, 'spend'));
        foreach ($aggregated as &$row) {
            $row['spend'] = round($row['spend'], 2);
            $row['share'] = $totalSpend > 0 ? round(($row['spend'] / $totalSpend) * 100, 1) : 0.0;
            $row['ctr'] = $row['impressions'] > 0 ? round(($row['clicks'] / $row['impressions']) * 100, 2) : null;
        }
        unset($row);

        usort($aggregated, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return array_values($aggregated);
    }

    /** @return list<array<string,mixed>> */
    private function hourly(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable(self::HOURLY_DAILY)) {
            return [];
        }

        return DB::table(self::HOURLY_DAILY)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('hour_bucket')
            ->orderBy('hour_bucket')
            ->get([
                'hour_bucket',
                DB::raw('SUM(spend) AS spend'),
                DB::raw('SUM(impressions) AS impressions'),
                DB::raw('SUM(clicks) AS clicks'),
            ])
            ->map(static function ($row): array {
                $spend = (float) $row->spend;
                $impressions = (int) $row->impressions;
                $clicks = (int) $row->clicks;

                return [
                    'hour' => (string) $row->hour_bucket,
                    'spend' => round($spend, 2),
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                ];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function targetingSnapshots(int $digitalAssetId, int $externalResourceId, string $accountId): array
    {
        if (! Schema::hasTable(self::TARGETING_SNAPSHOT)) {
            return [];
        }

        return DB::table(self::TARGETING_SNAPSHOT)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->orderBy('adset_name')
            ->limit(300)
            ->get(['adset_id', 'campaign_id', 'adset_name', 'optimization_goal', 'billing_event', 'bid_strategy', 'targeting', 'attribution_spec'])
            ->map(function ($row): array {
                $targeting = $this->jsonArray($row->targeting);
                $attribution = $this->jsonArray($row->attribution_spec);

                return [
                    'adset_id' => (string) $row->adset_id,
                    'campaign_id' => $row->campaign_id !== null ? (string) $row->campaign_id : null,
                    'adset_name' => $row->adset_name !== null ? (string) $row->adset_name : ('Ad set '.$row->adset_id),
                    'optimization_goal' => $row->optimization_goal !== null ? $this->humanize((string) $row->optimization_goal) : null,
                    'billing_event' => $row->billing_event !== null ? $this->humanize((string) $row->billing_event) : null,
                    'bid_strategy' => $row->bid_strategy !== null ? $this->humanize((string) $row->bid_strategy) : null,
                    'summary' => $this->targetingSummary($targeting),
                    'attribution' => $attribution,
                ];
            })
            ->all();
    }

    /** @return list<string> */
    private function targetingSummary(array $targeting): array
    {
        $summary = [];
        $ageMin = $targeting['age_min'] ?? null;
        $ageMax = $targeting['age_max'] ?? null;
        if ($ageMin !== null || $ageMax !== null) {
            $summary[] = 'Age '.($ageMin ?? '?').'–'.($ageMax ?? '?');
        }

        $countries = data_get($targeting, 'geo_locations.countries');
        if (is_array($countries) && $countries !== []) {
            $summary[] = 'Countries: '.implode(', ', array_slice(array_map('strval', $countries), 0, 8));
        }

        $platforms = $targeting['publisher_platforms'] ?? null;
        if (is_array($platforms) && $platforms !== []) {
            $summary[] = 'Platforms: '.implode(', ', array_map(fn ($v): string => $this->humanize((string) $v), $platforms));
        }

        $customAudiences = $targeting['custom_audiences'] ?? null;
        if (is_array($customAudiences) && $customAudiences !== []) {
            $summary[] = count($customAudiences).' custom audience'.(count($customAudiences) === 1 ? '' : 's');
        }

        $interests = data_get($targeting, 'flexible_spec.0.interests');
        if (is_array($interests) && $interests !== []) {
            $summary[] = count($interests).' interest'.(count($interests) === 1 ? '' : 's');
        }

        return $summary;
    }

    /** @param list<array<string,mixed>> $actions @return list<array<string,mixed>> */
    private function presentTypedActions(array $actions): array
    {
        return array_map(fn (array $action): array => [
            'action_type' => (string) $action['action_type'],
            'label' => $this->humanize((string) $action['action_type']),
            'value' => round((float) $action['action_value'], 2),
            'currency' => $action['currency'] ?? null,
            'rows' => (int) $action['rows'],
        ], $actions);
    }

    /** @return list<array<string,mixed>> */
    private function conversionSources(int $digitalAssetId, int $externalResourceId, string $accountId): array
    {
        if (! Schema::hasTable(self::CONVERSION_SOURCE_SNAPSHOT)) {
            return [];
        }

        return DB::table(self::CONVERSION_SOURCE_SNAPSHOT)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->orderBy('source_type')
            ->orderBy('source_name')
            ->get(['source_type', 'source_id', 'source_name', 'event_type', 'first_fired_time', 'last_fired_time', 'is_archived', 'is_unavailable', 'pixel_id'])
            ->map(static fn ($row): array => [
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'source_name' => $row->source_name !== null ? (string) $row->source_name : null,
                'event_type' => $row->event_type !== null ? (string) $row->event_type : null,
                'first_fired_time' => $row->first_fired_time !== null ? (string) $row->first_fired_time : null,
                'last_fired_time' => $row->last_fired_time !== null ? (string) $row->last_fired_time : null,
                'is_archived' => $row->is_archived !== null ? (bool) $row->is_archived : null,
                'is_unavailable' => $row->is_unavailable !== null ? (bool) $row->is_unavailable : null,
                'pixel_id' => $row->pixel_id !== null ? (string) $row->pixel_id : null,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function changeHistory(int $digitalAssetId, int $externalResourceId, string $accountId, string $start, string $end): array
    {
        if (! Schema::hasTable(self::CHANGE_EVENT)) {
            return [];
        }

        return DB::table(self::CHANGE_EVENT)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('account_id', $accountId)
            ->whereBetween('event_time', [$start.' 00:00:00', $end.' 23:59:59'])
            ->orderByDesc('event_time')
            ->limit(100)
            ->get(['event_time', 'event_type', 'translated_event_type', 'object_id', 'object_name', 'object_type', 'actor_name', 'application_name'])
            ->map(static fn ($row): array => [
                'event_time' => (string) $row->event_time,
                'event_type' => $row->event_type !== null ? (string) $row->event_type : null,
                'translated_event_type' => $row->translated_event_type !== null ? (string) $row->translated_event_type : null,
                'object_id' => $row->object_id !== null ? (string) $row->object_id : null,
                'object_name' => $row->object_name !== null ? (string) $row->object_name : null,
                'object_type' => $row->object_type !== null ? (string) $row->object_type : null,
                'actor_name' => $row->actor_name !== null ? (string) $row->actor_name : null,
                'application_name' => $row->application_name !== null ? (string) $row->application_name : null,
            ])
            ->all();
    }

    /** @param array<string,MetaAdsDatasetReadiness> $gates @return array<string,mixed> */
    private function health(array $gates): array
    {
        $usable = 0;
        $full = 0;
        $issues = [];

        foreach ($gates as $datasetId => $gate) {
            if ($gate->isUsable()) {
                $usable++;
            }
            if ($gate->isFullyCovered()) {
                $full++;
            }
            if (! $gate->isUsable() || ! in_array($gate->freshnessState, ['FRESH', 'FRESH_WITH_LIMITATION'], true)) {
                $issues[] = [
                    'dataset_id' => $datasetId,
                    'label' => $this->humanize($datasetId),
                    'freshness_state' => $gate->freshnessState,
                    'coverage_state' => $gate->coverageState,
                    'integrity_status' => $gate->integrityStatus,
                    'usable' => $gate->isUsable(),
                ];
            }
        }

        return [
            'total' => count($gates),
            'usable' => $usable,
            'fully_covered' => $full,
            'issues' => $issues,
            'state' => $usable === count($gates) && $issues === [] ? 'healthy' : ($usable > 0 ? 'partial' : 'unavailable'),
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function emptyBreakdowns(): array
    {
        return [
            'country' => [],
            'age' => [],
            'gender' => [],
            'publisher_platform' => [],
            'platform_position' => [],
            'device' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyWorkspace(string $start, string $end, ?string $currency, ?string $timezone, ?string $accountId): array
    {
        return [
            'available' => false,
            'account_id' => $accountId,
            'act_id' => $accountId !== null ? 'act_'.$accountId : null,
            'currency' => $currency,
            'timezone' => $timezone,
            'period_start' => $start,
            'period_end' => $end,
            'metric_source' => null,
            'kpis' => [],
            'trend' => [],
            'campaigns' => [],
            'adsets' => [],
            'ads' => [],
            'creatives' => [],
            'breakdowns' => $this->emptyBreakdowns(),
            'hourly' => [],
            'targeting' => [],
            'typed_actions' => [],
            'conversion_sources' => [],
            'change_history' => [],
            'datasets' => [],
            'health' => ['total' => 0, 'usable' => 0, 'fully_covered' => 0, 'issues' => [], 'state' => 'unavailable'],
            'notes' => [],
            'error' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function humanize(string $value): string
    {
        return Str::title(strtolower(str_replace(['.', '_'], ' ', $value)));
    }

    private function money(float $amount, string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $currency = $currency !== '' && $currency !== 'XXX' ? $currency : 'N/A';

        return $currency.' '.number_format($amount, 2);
    }
}
