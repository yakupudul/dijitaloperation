<?php

namespace App\Services\GoogleAds;

use App\Enums\DataPool\DataSourceState;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Support\Operator\OperatorReportingPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Decision-oriented read layer for the Google Ads Digital Asset workspace.
 *
 * Reads only the local Data Pool. It never calls Google Ads/OAuth during render and
 * never fabricates provider facts. Missing tables or provider-limited dimensions are
 * represented explicitly so the UI can say "unavailable" instead of displaying zero.
 */
final class GoogleAdsProfessionalWorkspaceReadService
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(string $assetId, string $preset = 'last_28', ?string $start = null, ?string $end = null): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound) {
            return $this->emptyWorkspace($binding->mode->value ?? 'not_connected');
        }

        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $resourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;
        $currency = (string) ($binding->currency ?? 'XXX');

        try {
            $device = $this->dailyBreakdown('google_ads_device_daily', ['device'], $resourceId, $customerId, $rangeStart, $rangeEnd);
            $hour = $this->dailyBreakdown('google_ads_hour_daily', ['day_of_week', 'hour'], $resourceId, $customerId, $rangeStart, $rangeEnd, 200);
            $network = $this->dailyBreakdown('google_ads_network_daily', ['ad_network_type'], $resourceId, $customerId, $rangeStart, $rangeEnd);
            $location = $this->dailyBreakdown('google_ads_user_location_daily', ['country_criterion_id', 'targeting_location'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $age = $this->dailyBreakdown('google_ads_age_range_daily', ['criterion_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 50);
            $gender = $this->dailyBreakdown('google_ads_gender_daily', ['criterion_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 50);
            $campaignAudience = $this->dailyBreakdown('google_ads_campaign_audience_daily', ['campaign_id', 'criterion_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $adGroupAudience = $this->dailyBreakdown('google_ads_ad_group_audience_daily', ['campaign_id', 'ad_group_id', 'criterion_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $adGroups = $this->dailyBreakdown('google_ads_ad_group_daily', ['campaign_id', 'ad_group_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $ads = $this->dailyBreakdown('google_ads_ad_daily', ['campaign_id', 'ad_group_id', 'ad_id'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $pmax = $this->dailyBreakdown('google_ads_pmax_asset_daily', ['campaign_id', 'asset_group_id', 'asset_id', 'field_type'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $shopping = $this->dailyBreakdown('google_ads_shopping_product_daily', ['product_key'], $resourceId, $customerId, $rangeStart, $rangeEnd, 100);
            $video = $this->videoBreakdown($resourceId, $customerId, $rangeStart, $rangeEnd);

            $negativeCampaign = $this->snapshotRows('google_ads_campaign_negative_keyword_snapshot', $resourceId, $customerId, 250);
            $negativeAdGroup = $this->snapshotRows('google_ads_ad_group_negative_keyword_snapshot', $resourceId, $customerId, 250);
            $bidding = $this->snapshotRows('google_ads_bidding_strategy_snapshot', $resourceId, $customerId, 100);
            $pmaxGroups = $this->snapshotRows('google_ads_pmax_asset_group_snapshot', $resourceId, $customerId, 100);
            $recommendations = $this->recommendations($resourceId, $customerId);
            $changes = $this->changes($resourceId, $customerId);
            $history = $this->history($resourceId, $customerId);
            $health = $this->dataHealth($resourceId);

            return [
                'connected' => true,
                'state' => DataSourceState::Real->value,
                'period' => ['start' => $rangeStart, 'end' => $rangeEnd, 'label' => $bounds['label']],
                'currency' => $currency,
                'ad_groups' => $adGroups,
                'ad_daily' => $ads,
                'performance' => [
                    'device' => $device,
                    'hour' => $hour,
                    'network' => $network,
                    'location' => $location,
                    'age' => $age,
                    'gender' => $gender,
                    'campaign_audience' => $campaignAudience,
                    'ad_group_audience' => $adGroupAudience,
                    'demographic_note' => 'Age/gender labels require Google criterion metadata resolution; provider criterion IDs are preserved without guessing.',
                    'location_note' => 'User location uses Google Ads user_location_view semantics (physical user location). Country criterion IDs are provider facts.',
                ],
                'search' => [
                    'campaign_negatives' => $negativeCampaign,
                    'ad_group_negatives' => $negativeAdGroup,
                ],
                'budget_bidding' => [
                    'strategies' => $bidding,
                ],
                'optimization' => [
                    'google_recommendations' => $recommendations,
                    'boundary' => 'Google recommendations are provider suggestions. They are not MOXDOP recommendations and are never auto-applied.',
                ],
                'changes' => $changes,
                'pmax' => [
                    'asset_groups' => $pmaxGroups,
                    'assets' => $pmax,
                ],
                'shopping' => $shopping,
                'video' => $video,
                'history' => $history,
                'data_health' => $health,
                'capabilities' => [
                    'pmax' => $pmax !== [] || $pmaxGroups !== [],
                    'shopping' => $shopping !== [],
                    'video' => $video !== [],
                ],
            ];
        } catch (Throwable $e) {
            report($e);

            return array_merge($this->emptyWorkspace('unavailable'), [
                'connected' => true,
                'error' => $e->getMessage(),
                'period' => ['start' => $rangeStart, 'end' => $rangeEnd, 'label' => $bounds['label']],
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function emptyWorkspace(string $state): array
    {
        return [
            'connected' => false,
            'state' => $state,
            'period' => null,
            'currency' => 'XXX',
            'ad_groups' => [],
            'ad_daily' => [],
            'performance' => [
                'device' => [], 'hour' => [], 'network' => [], 'location' => [], 'age' => [], 'gender' => [],
                'campaign_audience' => [], 'ad_group_audience' => [],
                'demographic_note' => null, 'location_note' => null,
            ],
            'search' => ['campaign_negatives' => [], 'ad_group_negatives' => []],
            'budget_bidding' => ['strategies' => []],
            'optimization' => ['google_recommendations' => [], 'boundary' => null],
            'changes' => [],
            'pmax' => ['asset_groups' => [], 'assets' => []],
            'shopping' => [],
            'video' => [],
            'history' => [],
            'data_health' => [],
            'capabilities' => ['pmax' => false, 'shopping' => false, 'video' => false],
        ];
    }

    /**
     * @param list<string> $dimensions
     * @return list<array<string,mixed>>
     */
    private function dailyBreakdown(
        string $table,
        array $dimensions,
        int $resourceId,
        string $customerId,
        string $start,
        string $end,
        int $limit = 50,
    ): array {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        foreach ($dimensions as $dimension) {
            if (! in_array($dimension, $columns, true)) {
                return [];
            }
        }

        $selects = $dimensions;
        foreach (['impressions', 'clicks', 'interactions', 'cost_micros', 'cost_amount', 'conversions', 'conversions_value', 'all_conversions', 'all_conversions_value', 'view_through_conversions'] as $metric) {
            if (in_array($metric, $columns, true)) {
                $selects[] = DB::raw('SUM('.$metric.') as '.$metric);
            }
        }

        $rows = $this->scopedTable($table, $resourceId, $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->select($selects)
            ->groupBy($dimensions)
            ->orderByDesc(in_array('cost_amount', $columns, true) ? 'cost_amount' : 'clicks')
            ->limit($limit)
            ->get();

        return $rows->map(function (object $row) use ($dimensions): array {
            $data = [];
            foreach ($dimensions as $dimension) {
                $data[$dimension] = $row->{$dimension} ?? null;
            }
            $data += $this->metricArray($row);

            return $data;
        })->all();
    }

    /** @return list<array<string,mixed>> */
    private function videoBreakdown(int $resourceId, string $customerId, string $start, string $end): array
    {
        $table = 'google_ads_video_daily';
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        $query = $this->scopedTable($table, $resourceId, $customerId)
            ->whereBetween('reporting_date', [$start, $end])
            ->select(['video_id', 'ad_format_type']);

        foreach (['impressions', 'clicks', 'cost_amount', 'conversions', 'conversions_value', 'video_views'] as $metric) {
            if (in_array($metric, $columns, true)) {
                $query->addSelect(DB::raw('SUM('.$metric.') as '.$metric));
            }
        }
        foreach (['video_quartile_p25_rate', 'video_quartile_p50_rate', 'video_quartile_p75_rate', 'video_quartile_p100_rate'] as $metric) {
            if (in_array($metric, $columns, true)) {
                $query->addSelect(DB::raw('AVG('.$metric.') as '.$metric));
            }
        }

        return $query->groupBy(['video_id', 'ad_format_type'])
            ->orderByDesc(in_array('cost_amount', $columns, true) ? 'cost_amount' : 'video_id')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                return [
                    'video_id' => $row->video_id ?? null,
                    'ad_format_type' => $row->ad_format_type ?? null,
                    ...$this->metricArray($row),
                    'video_views' => (int) ($row->video_views ?? 0),
                    'p25' => $this->floatOrNull($row->video_quartile_p25_rate ?? null),
                    'p50' => $this->floatOrNull($row->video_quartile_p50_rate ?? null),
                    'p75' => $this->floatOrNull($row->video_quartile_p75_rate ?? null),
                    'p100' => $this->floatOrNull($row->video_quartile_p100_rate ?? null),
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function snapshotRows(string $table, int $resourceId, string $customerId, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);
        $query = $this->scopedTable($table, $resourceId, $customerId);
        if (in_array('last_collected_at', $columns, true)) {
            $query->orderByDesc('last_collected_at');
        } else {
            $query->orderByDesc('id');
        }

        return $query->limit($limit)->get()->map(fn (object $row): array => $this->rowToArray($row))->all();
    }

    /** @return list<array<string,mixed>> */
    private function recommendations(int $resourceId, string $customerId): array
    {
        $table = 'google_ads_recommendation_snapshot';
        if (! Schema::hasTable($table)) {
            return [];
        }

        return $this->scopedTable($table, $resourceId, $customerId)
            ->orderByDesc('observed_date')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->rowToArray($row))
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function changes(int $resourceId, string $customerId): array
    {
        $table = 'google_ads_change_event';
        if (! Schema::hasTable($table)) {
            return [];
        }

        return $this->scopedTable($table, $resourceId, $customerId)
            ->orderByDesc('changed_at')
            ->limit(150)
            ->get()
            ->map(fn (object $row): array => $this->rowToArray($row))
            ->all();
    }

    /** @return array<string,mixed> */
    private function history(int $resourceId, string $customerId): array
    {
        $table = 'google_ads_account_monthly_history';
        if (! Schema::hasTable($table)) {
            return [];
        }

        $rows = $this->scopedTable($table, $resourceId, $customerId)
            ->orderBy('reporting_month')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $active = $rows->filter(fn (object $row): bool => (bool) ($row->activity_detected ?? false));

        return [
            'first_activity_month' => $active->first()?->reporting_month,
            'last_activity_month' => $active->last()?->reporting_month,
            'active_months' => $active->count(),
            'lifetime_spend' => (float) $rows->sum(fn (object $row): float => (float) ($row->cost_amount ?? 0)),
            'lifetime_clicks' => (int) $rows->sum(fn (object $row): int => (int) ($row->clicks ?? 0)),
            'lifetime_conversions' => (float) $rows->sum(fn (object $row): float => (float) ($row->conversions ?? 0)),
            'months' => $rows->map(fn (object $row): array => [
                'month' => $row->reporting_month,
                'active' => (bool) ($row->activity_detected ?? false),
                'spend' => (float) ($row->cost_amount ?? 0),
                'clicks' => (int) ($row->clicks ?? 0),
                'conversions' => (float) ($row->conversions ?? 0),
            ])->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function dataHealth(int $resourceId): array
    {
        return DatasetMaterialization::query()
            ->where('external_resource_id', $resourceId)
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->orderBy('dataset_id')
            ->get()
            ->map(function (DatasetMaterialization $row): array {
                $status = $row->status;

                return [
                    'dataset' => (string) $row->dataset_id,
                    'status' => is_object($status) && property_exists($status, 'value') ? $status->value : (string) $status,
                    'coverage_start' => $row->coverage_start_date?->toDateString(),
                    'coverage_end' => $row->coverage_end_date?->toDateString(),
                    'last_collected_at' => $row->last_collected_at?->toIso8601String(),
                    'rows' => (int) ($row->row_count_approx ?? 0),
                    'partial' => (bool) ($row->partial ?? false),
                ];
            })->all();
    }

    private function scopedTable(string $table, int $resourceId, string $customerId): Builder
    {
        $query = DB::table($table)->where('external_resource_id', $resourceId);
        if (Schema::hasColumn($table, 'customer_id') && $customerId !== '') {
            $query->where('customer_id', $customerId);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function metricArray(object $row): array
    {
        $impressions = (int) ($row->impressions ?? 0);
        $clicks = (int) ($row->clicks ?? 0);
        $cost = (float) ($row->cost_amount ?? 0);
        $conversions = (float) ($row->conversions ?? 0);
        $value = (float) ($row->conversions_value ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'interactions' => (int) ($row->interactions ?? 0),
            'cost' => $cost,
            'conversions' => $conversions,
            'conversion_value' => $value,
            'all_conversions' => (float) ($row->all_conversions ?? 0),
            'all_conversions_value' => (float) ($row->all_conversions_value ?? 0),
            'view_through_conversions' => (float) ($row->view_through_conversions ?? 0),
            'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
            'cpc' => $clicks > 0 ? $cost / $clicks : null,
            'cvr' => $clicks > 0 ? ($conversions / $clicks) * 100 : null,
            'cpa' => $conversions > 0 ? $cost / $conversions : null,
            'roas' => $cost > 0 ? $value / $cost : null,
        ];
    }

    /** @return array<string,mixed> */
    private function rowToArray(object $row): array
    {
        $data = (array) $row;
        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = $this->decodeMetadata($data['metadata']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (! is_string($metadata) || $metadata === '') {
            return [];
        }
        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
