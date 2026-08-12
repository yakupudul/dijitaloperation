<?php

namespace MoxDop\MetaAds\History;

use App\Models\CoreExternalResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;
use MoxDop\MetaAds\Normalization\MetaResultResolver;

/**
 * Read-side of the Meta Ads historical store.
 *
 * Aggregation rules (never violated here):
 * - spend / impressions / clicks / link_clicks / outbound_clicks: sum.
 * - CTR = sum(clicks) / sum(impressions); Link CTR = sum(link_clicks) / sum(impressions).
 * - CPC = sum(spend) / sum(clicks); CPM = sum(spend) / sum(impressions) * 1000.
 * - A rate/ratio is null (not 0) whenever its denominator is missing or zero —
 *   missing != zero.
 * - `reach` is NEVER summed and `frequency` is NEVER averaged across a range — both are
 *   resolved exclusively from the exact-period aggregate cache (resolveReachFrequency()).
 */
final class MetaHistoricalQueryService
{
    /**
     * Additive metrics carried on `meta_ads_daily_facts` (reach/frequency excluded by design).
     *
     * @var list<string>
     */
    private const array ADDITIVE_METRICS = ['spend', 'impressions', 'clicks', 'link_clicks', 'outbound_clicks'];

    /**
     * Any raw per-day column exposable via dailySeries() (reach/frequency included —
     * each point is a single day, not a range).
     *
     * @var list<string>
     */
    private const array DAILY_SERIES_METRICS = [
        'spend', 'impressions', 'clicks', 'link_clicks', 'outbound_clicks',
        'reach', 'frequency', 'cpc', 'cpm', 'ctr', 'link_ctr',
    ];

    /**
     * @return array<string, array{
     *     data_layer: string,
     *     granularity: string,
     *     status: string,
     *     start_date: ?string,
     *     end_date: ?string,
     *     last_successful_sync_at: ?string,
     * }>
     */
    public function coverageForResource(CoreExternalResource $resource): array
    {
        return MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $resource->id)
            ->get()
            ->keyBy('data_layer')
            ->map(fn (MetaAdsHistoryCoverage $row): array => [
                'data_layer' => $row->data_layer,
                'granularity' => $row->granularity,
                'status' => $row->status,
                'start_date' => $row->start_date?->toDateString(),
                'end_date' => $row->end_date?->toDateString(),
                'last_successful_sync_at' => $row->last_successful_sync_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Whether [$from, $to] is fully covered for a data layer.
     *
     * @return 'complete'|'partial'|'importing'|'not_imported'|'outside_provider'
     */
    public function isRangeCovered(
        CoreExternalResource $resource,
        string $from,
        string $to,
        string $dataLayer = MetaAdsHistoryCoverage::LAYER_DAILY_FACTS,
    ): string {
        $earliestAllowed = CarbonImmutable::now('UTC')->subMonths(MetaHistoricalConfig::HISTORY_MONTHS)->startOfDay();
        if (CarbonImmutable::parse($to)->lt($earliestAllowed)) {
            return 'outside_provider';
        }

        $coverage = MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('data_layer', $dataLayer)
            ->first();

        if (! $coverage instanceof MetaAdsHistoryCoverage || $coverage->start_date === null || $coverage->end_date === null) {
            return 'not_imported';
        }

        $covers = $coverage->start_date->lessThanOrEqualTo(CarbonImmutable::parse($from))
            && $coverage->end_date->greaterThanOrEqualTo(CarbonImmutable::parse($to));

        if ($covers && $coverage->status === MetaAdsHistoryCoverage::STATUS_COMPLETE) {
            return 'complete';
        }

        if ($coverage->status === MetaAdsHistoryCoverage::STATUS_IMPORTING) {
            return 'importing';
        }

        if ($covers && $coverage->status === MetaAdsHistoryCoverage::STATUS_PARTIAL) {
            return 'partial';
        }

        return $covers ? 'partial' : 'not_imported';
    }

    /**
     * Account-level additive metrics for a range, recomputed from daily facts.
     *
     * @return array<string, float|int|null>
     */
    public function accountFacts(CoreExternalResource $resource, string $from, string $to): array
    {
        $rows = MetaAdsDailyFact::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', 'account')
            ->where('provider_external_id', $resource->external_id)
            ->whereBetween('date', [$from, $to])
            ->get();

        return $this->aggregateRows($rows);
    }

    /**
     * Per-entity aggregated additive metrics for a range (one row per provider ID).
     *
     * @param  array{parent_provider_external_id?: string, provider_external_ids?: list<string>}  $filters
     * @return list<array<string, mixed>>
     */
    public function entityFacts(CoreExternalResource $resource, string $entityType, string $from, string $to, array $filters = []): array
    {
        $query = MetaAdsDailyFact::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->whereBetween('date', [$from, $to]);

        if (isset($filters['parent_provider_external_id'])) {
            $query->where('parent_provider_external_id', $filters['parent_provider_external_id']);
        }

        if (isset($filters['provider_external_ids']) && $filters['provider_external_ids'] !== []) {
            $query->whereIn('provider_external_id', $filters['provider_external_ids']);
        }

        return $query->get()
            ->groupBy('provider_external_id')
            ->map(function (Collection $rows, string $providerExternalId): array {
                /** @var MetaAdsDailyFact $first */
                $first = $rows->first();

                return [
                    'provider_external_id' => $providerExternalId,
                    'parent_provider_external_id' => $first->parent_provider_external_id,
                    ...$this->aggregateRows($rows),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Raw per-day points — no aggregation. Reach/frequency are safe here because each
     * point represents exactly one provider-reported day.
     *
     * @param  list<string>  $metrics  Defaults to all daily-series metrics when empty.
     * @return list<array<string, mixed>>
     */
    public function dailySeries(
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        array $metrics = [],
    ): array {
        $metrics = $metrics === []
            ? self::DAILY_SERIES_METRICS
            : array_values(array_intersect($metrics, self::DAILY_SERIES_METRICS));

        return MetaAdsDailyFact::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->where('provider_external_id', $entityId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->map(function (MetaAdsDailyFact $row) use ($metrics): array {
                $point = ['date' => $row->date->toDateString()];
                foreach ($metrics as $metric) {
                    $point[$metric] = $row->{$metric};
                }

                return $point;
            })
            ->values()
            ->all();
    }

    /**
     * Resolve range-level reach/frequency exclusively from the exact-period aggregate
     * cache. Never sums daily reach or averages daily frequency.
     *
     * @return array{status: 'ready'|'pending'|'unavailable', reach: ?int, frequency: ?float, reason: ?string}
     */
    public function resolveReachFrequency(
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        string $attributionContext = MetaAdsPeriodAggregate::ATTRIBUTION_CONTEXT_UNIFIED,
    ): array {
        $rows = MetaAdsPeriodAggregate::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->where('provider_external_id', $entityId)
            ->where('date_from', $from)
            ->where('date_to', $to)
            ->where('attribution_context', $attributionContext)
            ->whereIn('metric_key', [MetaAdsPeriodAggregate::METRIC_REACH, MetaAdsPeriodAggregate::METRIC_FREQUENCY])
            ->get()
            ->keyBy('metric_key');

        /** @var MetaAdsPeriodAggregate|null $reachRow */
        $reachRow = $rows->get(MetaAdsPeriodAggregate::METRIC_REACH);
        /** @var MetaAdsPeriodAggregate|null $frequencyRow */
        $frequencyRow = $rows->get(MetaAdsPeriodAggregate::METRIC_FREQUENCY);

        if ($reachRow === null && $frequencyRow === null) {
            return [
                'status' => 'pending',
                'reach' => null,
                'frequency' => null,
                'reason' => 'No exact-period aggregate cached for this range yet.',
            ];
        }

        $statuses = array_values(array_filter([$reachRow?->status, $frequencyRow?->status]));

        if (in_array(MetaAdsPeriodAggregate::STATUS_FAILED, $statuses, true)
            || in_array(MetaAdsPeriodAggregate::STATUS_UNAVAILABLE, $statuses, true)) {
            return [
                'status' => 'unavailable',
                'reach' => null,
                'frequency' => null,
                'reason' => 'Provider could not return an exact reach/frequency figure for this range.',
            ];
        }

        $reachReady = $reachRow?->status === MetaAdsPeriodAggregate::STATUS_READY;
        $frequencyReady = $frequencyRow?->status === MetaAdsPeriodAggregate::STATUS_READY;

        if (! $reachReady || ! $frequencyReady) {
            return [
                'status' => 'pending',
                'reach' => $reachReady ? $this->roundReach($reachRow) : null,
                'frequency' => $frequencyReady ? $frequencyRow->metric_value : null,
                'reason' => 'Exact-period aggregate fetch is queued or in progress.',
            ];
        }

        return [
            'status' => 'ready',
            'reach' => $this->roundReach($reachRow),
            'frequency' => $frequencyRow->metric_value,
            'reason' => null,
        ];
    }

    /**
     * Result Mix for a range from `meta_ads_daily_actions`. Sums each raw_action_type's
     * own count across days (legitimate) — never blends distinct action types together.
     *
     * @return array<string, mixed>
     */
    public function resultMix(
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        string $attributionWindow = '',
    ): array {
        $query = MetaAdsDailyAction::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->where('provider_external_id', $entityId)
            ->whereBetween('date', [$from, $to]);

        // Empty attribution window means "all stored provenance windows" — do not
        // silently drop rows whose attribution_setting was persisted as a label.
        if ($attributionWindow !== '') {
            $query->where('attribution_window', $attributionWindow);
        }

        $rows = $query->get();

        $byType = [];
        foreach ($rows as $row) {
            $type = $row->raw_action_type;
            $byType[$type] ??= [
                'raw_action_type' => $type,
                'normalized_result_type' => $row->normalized_family,
                'count' => 0.0,
                'value' => 0.0,
            ];
            $byType[$type]['count'] += (float) ($row->value ?? 0.0);
            $byType[$type]['value'] += (float) ($row->action_value ?? 0.0);
        }

        return MetaResultResolver::resultMix(array_values($byType));
    }

    /**
     * @param  Collection<int, MetaAdsDailyFact>  $rows
     * @return array<string, float|int|null>
     */
    private function aggregateRows(Collection $rows): array
    {
        $sums = [];
        $present = [];
        foreach (self::ADDITIVE_METRICS as $metric) {
            $sums[$metric] = 0.0;
            $present[$metric] = false;
        }

        foreach ($rows as $row) {
            foreach (self::ADDITIVE_METRICS as $metric) {
                $value = $row->{$metric};
                if ($value !== null) {
                    $sums[$metric] += (float) $value;
                    $present[$metric] = true;
                }
            }
        }

        // No fact rows at all for the range → every additive metric is unknown, not 0.
        if ($rows->isEmpty()) {
            $present = array_fill_keys(self::ADDITIVE_METRICS, false);
        }

        $spend = $present['spend'] ? round($sums['spend'], 4) : null;
        $impressions = $present['impressions'] ? (int) round($sums['impressions']) : null;
        $clicks = $present['clicks'] ? (int) round($sums['clicks']) : null;
        $linkClicks = $present['link_clicks'] ? (int) round($sums['link_clicks']) : null;
        $outboundClicks = $present['outbound_clicks'] ? (int) round($sums['outbound_clicks']) : null;

        return [
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'link_clicks' => $linkClicks,
            'outbound_clicks' => $outboundClicks,
            // Meta workspace CTR fields are percentage points (1.48 = 1.48%), matching
            // Marketing API Insights semantics — never leave them as 0–1 ratios.
            'ctr' => $this->ratio($clicks, $impressions, 100),
            'link_ctr' => $this->ratio($linkClicks, $impressions, 100),
            'cpc' => $this->ratio($spend, $clicks),
            'cpm' => $this->ratio($spend, $impressions, 1000),
        ];
    }

    private function ratio(int|float|null $numerator, int|float|null $denominator, float $multiplier = 1.0): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0) {
            return null;
        }

        return round(($numerator / $denominator) * $multiplier, 8);
    }

    private function roundReach(MetaAdsPeriodAggregate $row): ?int
    {
        return $row->metric_value !== null ? (int) round($row->metric_value) : null;
    }
}
