<?php

namespace MoxDop\MetaAds\Workspace;

use App\Models\CoreExternalResource;
use App\Support\Integrations\ComparisonPeriod;
use MoxDop\MetaAds\History\MetaHistoricalQueryService;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use MoxDop\MetaAds\Normalization\MetaResultResolver;

/**
 * Builds the operator-facing Meta Ads workspace payload from the local historical
 * store (daily facts / actions / exact-period aggregates / entities) for a covered
 * range — the primary data path.
 *
 * Every metric follows MetaHistoricalQueryService aggregation rules: additive metrics
 * are summed, rates are recomputed from summed numerators/denominators, reach/frequency
 * come only from the exact-period aggregate cache, and missing != zero. Distinct Meta
 * action types are never summed into a fabricated total.
 */
final class MetaHistoricalWorkspaceBuilder
{
    public function __construct(
        private readonly MetaHistoricalQueryService $query,
    ) {}

    /**
     * @param  array{start: string, end: string}  $period
     * @param  array{start: string, end: string}  $comparisonPeriod
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(
        CoreExternalResource $resource,
        array $period,
        array $comparisonPeriod,
        array $filters,
        string $coverageState,
    ): array {
        $from = $period['start'];
        $to = $period['end'];
        $accountId = $this->accountProviderId($resource);

        $currentFacts = $this->query->accountFacts($resource, $from, $to);
        $reachFrequency = $this->query->resolveReachFrequency($resource, 'account', $accountId, $from, $to);
        $currentFacts['reach'] = $reachFrequency['reach'];
        $currentFacts['frequency'] = $reachFrequency['frequency'];

        $compareRequested = ($filters['compare'] ?? true) === true;
        $previousFacts = null;
        if ($compareRequested && $this->rangeHasFacts($resource, $comparisonPeriod['start'], $comparisonPeriod['end'])) {
            $previousFacts = $this->query->accountFacts($resource, $comparisonPeriod['start'], $comparisonPeriod['end']);
        }
        $comparisonAvailable = $previousFacts !== null;

        $accountMix = $this->query->resultMix($resource, 'account', $accountId, $from, $to);
        $resultMix = $this->shapeResultMix($accountMix);
        $resultGrouped = $this->groupResultMix($resultMix['items']);

        $entityMeta = $this->entityMetadata($resource);
        $campaignRows = $this->entityRows($resource, MetaAdsEntity::TYPE_CAMPAIGN, $from, $to, $entityMeta);
        $adsetRows = $this->entityRows($resource, MetaAdsEntity::TYPE_ADSET, $from, $to, $entityMeta);
        $adRows = $this->entityRows($resource, MetaAdsEntity::TYPE_AD, $from, $to, $entityMeta);

        // Campaign primary results inherit material delivered Ad Set consensus when homogeneous.
        $campaignRows = array_map(
            fn (array $campaign): array => MetaResultResolver::applyCampaignAdSetConsensus($campaign, $adsetRows),
            $campaignRows,
        );

        $kpis = $this->priorityKpis($currentFacts, $previousFacts, $resultMix['items']);
        $kpisSecondary = $this->secondaryKpis($currentFacts, $previousFacts);
        $kpisFull = $this->fullKpis($currentFacts, $previousFacts, $resultMix['items']);

        return [
            'source' => 'historical',
            'coverage_state' => $coverageState,
            'account_facts' => $currentFacts,
            'previous_facts' => $previousFacts,
            'reach_frequency' => $reachFrequency,
            'kpis' => $kpis,
            'kpis_secondary' => $kpisSecondary,
            'kpis_full' => $kpisFull,
            'result_mix' => $resultMix,
            'result_mix_grouped' => $resultGrouped,
            'primary_result' => $this->primaryResult($resultMix['items']),
            'campaign_rows' => $campaignRows,
            'adset_rows' => $adsetRows,
            'ad_rows' => $adRows,
            'trend' => $this->trend($resource, $accountId, $from, $to, (string) ($filters['trend_metric'] ?? 'spend')),
            'delivery_flow' => $this->deliveryFlow($currentFacts, $accountMix),
            'comparison' => [
                'period' => $comparisonPeriod,
                'available' => $comparisonAvailable,
                'reason' => $comparisonAvailable
                    ? 'Comparable prior period present in local history.'
                    : 'No complete prior-period history — comparison deltas are suppressed.',
            ],
            'data_coverage' => $this->dataCoverage($coverageState, $currentFacts, $campaignRows, $reachFrequency),
            'account_identity' => $this->accountIdentity($resource, $entityMeta, $currentFacts),
        ];
    }

    private function accountProviderId(CoreExternalResource $resource): string
    {
        $externalId = (string) $resource->external_id;

        return str_starts_with($externalId, 'act_') ? $externalId : 'act_'.$externalId;
    }

    private function rangeHasFacts(CoreExternalResource $resource, string $from, string $to): bool
    {
        $facts = $this->query->accountFacts($resource, $from, $to);

        return $facts['spend'] !== null || $facts['impressions'] !== null;
    }

    /**
     * @param  array<string, mixed>  $mix
     * @return array{mode: string, items: list<array<string, mixed>>, blind_action_sum: bool, note: ?string, raw_items: list<array<string, mixed>>}
     */
    private function shapeResultMix(array $mix): array
    {
        return [
            'mode' => 'result_mix',
            'items' => array_values(array_filter($mix['operator_items'] ?? $mix['items'] ?? [], 'is_array')),
            'raw_items' => array_values(array_filter($mix['raw_items'] ?? [], 'is_array')),
            'blind_action_sum' => false,
            'note' => isset($mix['note']) ? (string) $mix['note'] : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{contact_conversion: list<array<string, mixed>>, traffic_engagement: list<array<string, mixed>>, note: ?string}
     */
    private function groupResultMix(array $items): array
    {
        $contact = [];
        $traffic = [];
        foreach ($items as $item) {
            $family = MetaResultResolver::resultFamily(
                isset($item['normalized_result_type']) ? (string) $item['normalized_result_type'] : null,
                (string) ($item['raw_action_type'] ?? ''),
            );
            if ($family === 'contact_conversion') {
                $contact[] = $item;
            } elseif ($family === 'traffic_engagement') {
                $traffic[] = $item;
            }
        }

        return [
            'contact_conversion' => $contact,
            'traffic_engagement' => $traffic,
            'note' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function primaryResult(array $items): ?array
    {
        foreach ($items as $item) {
            $family = MetaResultResolver::resultFamily(
                isset($item['normalized_result_type']) ? (string) $item['normalized_result_type'] : null,
                (string) ($item['raw_action_type'] ?? ''),
            );
            if ($family === 'contact_conversion') {
                return [
                    'status' => 'resolved',
                    'raw_action_type' => $item['raw_action_type'] ?? null,
                    'normalized_result_type' => $item['normalized_result_type'] ?? null,
                    'human_label' => $item['human_label'] ?? null,
                    'count' => $item['count'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, float|int|null>|null  $previous
     * @param  list<array<string, mixed>>  $mixItems
     * @return list<array<string, mixed>>
     */
    private function priorityKpis(array $current, ?array $previous, array $mixItems): array
    {
        $out = [];

        $out[] = $this->kpi('spend', 'Spend', 'currency', $current, $previous, 'primary');

        $added = 0;
        foreach ($mixItems as $item) {
            if ($added >= 3) {
                break;
            }
            $family = MetaResultResolver::resultFamily(
                isset($item['normalized_result_type']) ? (string) $item['normalized_result_type'] : null,
                (string) ($item['raw_action_type'] ?? ''),
            );
            if ($family !== 'contact_conversion') {
                continue;
            }
            $count = is_numeric($item['count'] ?? null) ? (float) $item['count'] : null;
            if ($count === null || $count <= 0) {
                continue;
            }
            $spend = is_numeric($current['spend'] ?? null) ? (float) $current['spend'] : null;
            $out[] = [
                'key' => 'result_'.($item['raw_action_type'] ?? 'mix'),
                'label' => $item['human_label'] ?? 'Results',
                'value' => $count,
                'type' => 'count',
                'delta_percent' => null,
                'delta_sentiment' => null,
                'tier' => 'primary',
                'family' => 'result',
                'cost_per_result' => ($spend !== null && $count > 0) ? round($spend / $count, 2) : null,
            ];
            $added++;
        }

        if ($added === 0 && ($current['link_clicks'] ?? null) !== null) {
            $out[] = $this->kpi('link_clicks', 'Link clicks', 'count', $current, $previous, 'primary', 'traffic');
        }

        return $out;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, float|int|null>|null  $previous
     * @return list<array<string, mixed>>
     */
    private function secondaryKpis(array $current, ?array $previous): array
    {
        $out = [];
        if (($current['reach'] ?? null) !== null) {
            $out[] = $this->kpi('reach', 'Reach', 'count', $current, $previous, 'secondary', 'traffic');
        }
        if (($current['frequency'] ?? null) !== null) {
            $out[] = $this->kpi('frequency', 'Frequency', 'decimal', $current, $previous, 'secondary', 'efficiency');
        }
        $out[] = $this->kpi('link_ctr', 'Link CTR', 'percentage_point', $current, $previous, 'secondary', 'efficiency', outputKey: 'inline_link_click_ctr');
        $out[] = $this->kpi('cpm', 'CPM', 'currency', $current, $previous, 'secondary', 'efficiency');

        return array_values(array_filter($out, fn (array $kpi): bool => $kpi['value'] !== null));
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, float|int|null>|null  $previous
     * @param  list<array<string, mixed>>  $mixItems
     * @return list<array<string, mixed>>
     */
    private function fullKpis(array $current, ?array $previous, array $mixItems): array
    {
        $map = [
            ['spend', 'Spend', 'currency', 'spend'],
            ['impressions', 'Impressions', 'count', 'traffic'],
            ['reach', 'Reach', 'count', 'traffic'],
            ['frequency', 'Frequency', 'decimal', 'efficiency'],
            ['clicks', 'All Clicks', 'count', 'traffic'],
            ['link_clicks', 'Link Clicks', 'count', 'traffic', 'inline_link_clicks'],
            ['outbound_clicks', 'Outbound Clicks', 'count', 'traffic'],
            ['ctr', 'All Clicks CTR', 'percentage_point', 'efficiency'],
            ['link_ctr', 'Link CTR', 'percentage_point', 'efficiency', 'inline_link_click_ctr'],
            ['cpc', 'CPC (All)', 'currency', 'efficiency'],
            ['cpm', 'CPM', 'currency', 'efficiency'],
        ];

        $out = [];
        foreach ($map as $entry) {
            $key = $entry[0];
            if (! array_key_exists($key, $current) || $current[$key] === null) {
                continue;
            }
            $out[] = $this->kpi($key, $entry[1], $entry[2], $current, $previous, 'full', $entry[3], outputKey: $entry[4] ?? null);
        }

        return $out;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, float|int|null>|null  $previous
     * @return array<string, mixed>
     */
    private function kpi(
        string $sourceKey,
        string $label,
        string $type,
        array $current,
        ?array $previous,
        string $tier,
        string $family = 'spend',
        ?string $outputKey = null,
    ): array {
        $value = $current[$sourceKey] ?? null;
        $deltaPercent = null;
        if ($previous !== null && array_key_exists($sourceKey, $previous)) {
            $deltaPercent = ComparisonPeriod::percentDelta(
                is_numeric($value) ? (float) $value : null,
                is_numeric($previous[$sourceKey] ?? null) ? (float) $previous[$sourceKey] : null,
            );
        }

        $outputKey ??= $sourceKey;

        return [
            'key' => $outputKey,
            'label' => $label,
            'value' => $value,
            'type' => $type,
            'delta_percent' => $deltaPercent,
            'delta_sentiment' => $this->deltaSentiment($outputKey, $deltaPercent),
            'tier' => $tier,
            'family' => $family,
        ];
    }

    private function deltaSentiment(string $metricKey, mixed $deltaPercent): ?string
    {
        if (! is_numeric($deltaPercent)) {
            return null;
        }

        $delta = (float) $deltaPercent;
        if (abs($delta) < 0.05) {
            return 'flat';
        }

        $upIsBad = in_array($metricKey, ['cpc', 'cpm', 'cost_per_inline_link_click', 'frequency'], true);

        if ($delta > 0) {
            return $upIsBad ? 'negative' : ($metricKey === 'spend' ? 'neutral' : 'positive');
        }

        return $upIsBad ? 'positive' : ($metricKey === 'spend' ? 'neutral' : 'negative');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function entityMetadata(CoreExternalResource $resource): array
    {
        return MetaAdsEntity::query()
            ->where('core_external_resource_id', $resource->id)
            ->get()
            ->keyBy('provider_external_id')
            ->map(fn (MetaAdsEntity $entity): array => [
                'name' => $entity->name,
                'status' => $entity->status,
                'objective' => $entity->objective,
                'optimization_goal' => $entity->optimization_goal,
                'destination_type' => $entity->destination_type,
                'parent' => $entity->parent_provider_external_id,
            ])
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $entityMeta
     * @return list<array<string, mixed>>
     */
    private function entityRows(CoreExternalResource $resource, string $entityType, string $from, string $to, array $entityMeta): array
    {
        $facts = $this->query->entityFacts($resource, $entityType, $from, $to);
        if ($facts === []) {
            return [];
        }

        $actionsByEntity = $this->actionsByEntity($resource, $entityType, $from, $to);

        return array_values(array_map(function (array $row) use ($entityType, $entityMeta, $actionsByEntity): array {
            $providerId = (string) $row['provider_external_id'];
            $meta = $entityMeta[$providerId] ?? [];
            $actions = $actionsByEntity[$providerId] ?? [];
            $status = $meta['status'] ?? null;

            $primary = MetaResultResolver::resolve(
                $actions,
                isset($meta['objective']) ? (string) $meta['objective'] : null,
                isset($meta['optimization_goal']) ? (string) $meta['optimization_goal'] : null,
                is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : null,
                null,
                isset($meta['destination_type']) ? (string) $meta['destination_type'] : null,
                null,
            );

            $humanLabel = MetaResultResolver::humanLabel(
                $primary['raw_action_type'] ?? null,
                $primary['normalized_result_type'] ?? null,
            ) ?? ($primary['status'] === 'unresolved' ? 'Unresolved' : null);

            return [
                'entity_id' => $providerId,
                'campaign_id' => $entityType === MetaAdsEntity::TYPE_CAMPAIGN ? $providerId : ($entityType === MetaAdsEntity::TYPE_ADSET ? ($meta['parent'] ?? $row['parent_provider_external_id'] ?? null) : null),
                'adset_id' => $entityType === MetaAdsEntity::TYPE_ADSET ? $providerId : ($entityType === MetaAdsEntity::TYPE_AD ? ($meta['parent'] ?? $row['parent_provider_external_id'] ?? null) : null),
                'ad_id' => $entityType === MetaAdsEntity::TYPE_AD ? $providerId : null,
                'name' => $meta['name'] ?? $providerId,
                'campaign_name' => null,
                'adset_name' => null,
                'status' => $status,
                'effective_status' => $status,
                'objective' => $meta['objective'] ?? null,
                'optimization_goal' => $meta['optimization_goal'] ?? null,
                'destination_type' => $meta['destination_type'] ?? null,
                'attribution_setting' => null,
                'spend' => $row['spend'] ?? null,
                'impressions' => $row['impressions'] ?? null,
                'reach' => null,
                'frequency' => null,
                'clicks' => $row['clicks'] ?? null,
                'inline_link_clicks' => $row['link_clicks'] ?? null,
                'outbound_clicks' => $row['outbound_clicks'] ?? null,
                'ctr' => $row['ctr'] ?? null,
                'inline_link_click_ctr' => $row['link_ctr'] ?? null,
                'cpc' => $row['cpc'] ?? null,
                'cpm' => $row['cpm'] ?? null,
                'primary_result_status' => $primary['status'] ?? null,
                'primary_result_type' => $primary['raw_action_type'] ?? $primary['normalized_result_type'] ?? null,
                'primary_result_human_label' => $humanLabel,
                'primary_result_count' => $primary['count'] ?? null,
                'primary_result_cost' => $primary['cost_per_result'] ?? null,
                'primary_result_reason' => $primary['reason'] ?? null,
                'actions' => $actions,
            ];
        }, $facts));
    }

    /**
     * Aggregates each entity's own Meta action types across the range (never blending
     * distinct types) into the normalized-action shape MetaResultResolver expects.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function actionsByEntity(CoreExternalResource $resource, string $entityType, string $from, string $to): array
    {
        $rows = MetaAdsDailyAction::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->whereBetween('date', [$from, $to])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $providerId = (string) $row->provider_external_id;
            $type = (string) $row->raw_action_type;
            if ($type === '') {
                continue;
            }
            $out[$providerId] ??= [];
            $out[$providerId][$type] ??= [
                'raw_action_type' => $type,
                'normalized_result_type' => $row->normalized_family,
                'count' => 0.0,
                'value' => 0.0,
            ];
            $out[$providerId][$type]['count'] += (float) ($row->value ?? 0.0);
            $out[$providerId][$type]['value'] += (float) ($row->action_value ?? 0.0);
        }

        return array_map(fn (array $byType): array => array_values($byType), $out);
    }

    /**
     * @return array{metric: string, label: string, type: string, values: list<float|null>, labels: list<string>, points: list<array<string, mixed>>, available: bool, needs_analyze: bool, note: ?string}
     */
    private function trend(CoreExternalResource $resource, string $accountId, string $from, string $to, string $metric): array
    {
        $allowed = [
            'spend' => ['label' => 'Spend', 'type' => 'currency', 'column' => 'spend'],
            'impressions' => ['label' => 'Impressions', 'type' => 'count', 'column' => 'impressions'],
            'inline_link_clicks' => ['label' => 'Link clicks', 'type' => 'count', 'column' => 'link_clicks'],
            'inline_link_click_ctr' => ['label' => 'Link CTR', 'type' => 'percentage_point', 'column' => 'link_ctr'],
            'cpm' => ['label' => 'CPM', 'type' => 'currency', 'column' => 'cpm'],
            'frequency' => ['label' => 'Frequency', 'type' => 'decimal', 'column' => 'frequency'],
        ];
        if (! array_key_exists($metric, $allowed)) {
            $metric = 'spend';
        }
        $column = $allowed[$metric]['column'];

        $series = $this->query->dailySeries($resource, 'account', $accountId, $from, $to);

        $labels = [];
        $values = [];
        $points = [];
        foreach ($series as $point) {
            $date = (string) ($point['date'] ?? '');
            $labels[] = $date;
            $value = isset($point[$column]) && is_numeric($point[$column]) ? (float) $point[$column] : null;
            $values[] = $value;
            $points[] = [
                'date' => $date,
                'value' => $value,
                'spend' => $this->numeric($point['spend'] ?? null),
                'impressions' => $this->numeric($point['impressions'] ?? null),
                'inline_link_clicks' => $this->numeric($point['link_clicks'] ?? null),
                'inline_link_click_ctr' => $this->numeric($point['link_ctr'] ?? null),
                'cpm' => $this->numeric($point['cpm'] ?? null),
                'frequency' => $this->numeric($point['frequency'] ?? null),
            ];
        }

        $usable = array_values(array_filter($values, fn ($v): bool => $v !== null));

        return [
            'metric' => $metric,
            'label' => $allowed[$metric]['label'],
            'type' => $allowed[$metric]['type'],
            'values' => $values,
            'labels' => $labels,
            'points' => $points,
            'available' => count($usable) >= 2,
            'needs_analyze' => false,
            'note' => count($usable) >= 2 ? null : 'Not enough daily history points for a trend in this period.',
        ];
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, mixed>  $mix
     * @return array{stages: list<array<string, mixed>>, note: string}
     */
    private function deliveryFlow(array $current, array $mix): array
    {
        $impressions = is_numeric($current['impressions'] ?? null) ? (float) $current['impressions'] : null;
        $linkClicks = is_numeric($current['link_clicks'] ?? null) ? (float) $current['link_clicks'] : null;

        $lpv = null;
        foreach (($mix['raw_items'] ?? $mix['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['raw_action_type'] ?? null) === 'landing_page_view' && is_numeric($item['count'] ?? null)) {
                $lpv = (float) $item['count'];
                break;
            }
        }

        return [
            'stages' => [
                ['key' => 'impressions', 'label' => 'Impressions', 'value' => $impressions, 'available' => $impressions !== null],
                ['key' => 'inline_link_clicks', 'label' => 'Link Clicks', 'value' => $linkClicks, 'available' => $linkClicks !== null],
                ['key' => 'landing_page_view', 'label' => 'Landing Page Views', 'value' => $lpv, 'available' => $lpv !== null],
            ],
            'note' => 'Platform delivery path only. Business outcomes require CRM Evidence.',
        ];
    }

    /**
     * @param  array<string, float|int|null>  $facts
     * @param  list<array<string, mixed>>  $campaignRows
     * @param  array{status: string, reach: ?int, frequency: ?float, reason: ?string}  $reachFrequency
     * @return array<string, string>
     */
    private function dataCoverage(string $coverageState, array $facts, array $campaignRows, array $reachFrequency): array
    {
        $accountState = $facts['spend'] !== null || $facts['impressions'] !== null
            ? ($coverageState === 'complete' ? 'Complete' : 'Partial')
            : 'Unavailable';
        $campaignState = $campaignRows !== [] ? ($coverageState === 'complete' ? 'Complete' : 'Partial') : 'Unavailable';
        $trendState = $coverageState === 'complete' ? 'Complete' : 'Partial';

        $reachState = match ($reachFrequency['status']) {
            'ready' => 'Complete',
            'unavailable' => 'Unavailable',
            default => 'Partial',
        };

        return [
            'account' => $accountState,
            'campaigns' => $campaignState,
            'adsets' => $campaignState,
            'ads' => $campaignState,
            'creative' => 'Unknown',
            'trend' => $trendState,
            'reach_frequency' => $reachState,
            'result_signal' => $this->resultSignalCoverage($campaignRows),
            'business_validation' => 'Not connected',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $campaignRows
     */
    private function resultSignalCoverage(array $campaignRows): string
    {
        if ($campaignRows === []) {
            return 'Unknown';
        }

        $statuses = collect($campaignRows)->pluck('primary_result_status')->filter();
        if ($statuses->isEmpty()) {
            return 'Unknown';
        }

        $resolvedLike = $statuses->filter(fn (?string $s): bool => in_array($s, ['resolved', 'zero'], true))->count();
        $unresolved = $statuses->filter(fn (?string $s): bool => $s === 'unresolved')->count();
        $total = $statuses->count();

        if ($resolvedLike === $total) {
            return 'Resolved';
        }
        if ($unresolved === $total) {
            return 'Unresolved';
        }

        return 'Mixed';
    }

    /**
     * @param  array<string, array<string, mixed>>  $entityMeta
     * @param  array<string, float|int|null>  $facts
     * @return array<string, mixed>
     */
    private function accountIdentity(CoreExternalResource $resource, array $entityMeta, array $facts): array
    {
        $accountId = $this->accountProviderId($resource);
        $accountEntity = $entityMeta[$accountId] ?? [];
        $resourceMeta = is_array($resource->metadata ?? null) ? $resource->metadata : [];

        return [
            'name' => ($accountEntity['name'] ?? null)
                ?: $resource->display_name
                ?: $resource->external_id,
            'external_id' => $resource->external_id,
            'business_name' => $resourceMeta['business_name'] ?? null,
            'business_id' => $resourceMeta['business_id'] ?? null,
            'currency' => $resourceMeta['currency'] ?? null,
            'timezone' => $resourceMeta['timezone_name'] ?? null,
        ];
    }
}
