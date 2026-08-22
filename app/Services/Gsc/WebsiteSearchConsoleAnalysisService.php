<?php

namespace App\Services\Gsc;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Operator\OperatorReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Website-scoped Search Console decision model over the resource-first central Data Pool.
 *
 * The Website does not own or copy Search Console facts. Its active search_console
 * binding references the central provider resource and this service reads facts by
 * external_resource_id + site_url. Property totals always come from gsc_property_daily.
 */
final class WebsiteSearchConsoleAnalysisService
{
    private const string DEFAULT_SEARCH_TYPE = 'web';

    /** @return array<string, mixed> */
    public function build(
        DigitalAsset $asset,
        string $preset,
        ?string $start,
        ?string $end,
        bool $compare,
        string $compareMode,
    ): array {
        $bounds = OperatorReportingPeriod::queryBounds($preset, $start, $end);
        $comparison = OperatorReportingPeriod::comparisonQueryBounds($compareMode, $preset, $start, $end);
        $empty = $this->emptyState($bounds, $comparison, $compare);

        $binding = CoreAssetBinding::query()
            ->with('externalResource')
            ->where('digital_asset_id', (int) $asset->id)
            ->where('capability', GscSpecialistBindingResolver::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $binding instanceof CoreAssetBinding || ! $binding->externalResource instanceof CoreExternalResource) {
            return $empty;
        }

        $resource = $binding->externalResource;
        if ($resource->resource_type !== GoogleResourceType::GSC_PROPERTY) {
            return $empty;
        }

        $siteUrl = trim((string) $resource->external_id);
        $resourceId = (int) $resource->id;
        if ($siteUrl === '') {
            return $empty;
        }

        $connected = array_merge($empty, [
            'connected' => true,
            'property_name' => (string) ($resource->display_name ?: $siteUrl),
            'property_id' => $siteUrl,
            'property_type' => str_starts_with($siteUrl, 'sc-domain:') ? 'domain' : 'url_prefix',
            'external_resource_id' => $resourceId,
        ]);

        if (! Schema::hasTable('gsc_property_daily')) {
            return $connected;
        }

        $coverage = $this->baseQuery('gsc_property_daily', $resourceId, $siteUrl, null, null, self::DEFAULT_SEARCH_TYPE)
            ->selectRaw('MIN(reporting_date) as min_date, MAX(reporting_date) as max_date, MAX(last_collected_at) as last_collected_at')
            ->first();

        $coverageStart = filled($coverage?->min_date) ? (string) $coverage->min_date : null;
        $coverageEnd = filled($coverage?->max_date) ? (string) $coverage->max_date : null;
        $requestedStart = $bounds['start']->toDateString();
        $requestedEnd = $bounds['end']->toDateString();
        $rangeStart = $requestedStart;
        $rangeEnd = $coverageEnd !== null && $coverageEnd < $requestedEnd ? $coverageEnd : $requestedEnd;
        $rangeUsable = $coverageEnd !== null && $rangeEnd >= $rangeStart;

        [$previousStart, $previousEnd] = $this->comparisonRange(
            $rangeStart,
            $rangeEnd,
            $compareMode,
            $comparison['start']->toDateString(),
            $comparison['end']->toDateString(),
            $rangeUsable,
        );

        $current = $rangeUsable
            ? $this->propertySums($resourceId, $siteUrl, $rangeStart, $rangeEnd, self::DEFAULT_SEARCH_TYPE)
            : $this->zeroPropertySums();
        $previous = $compare && $rangeUsable
            ? $this->propertySums($resourceId, $siteUrl, $previousStart, $previousEnd, self::DEFAULT_SEARCH_TYPE)
            : null;

        $yoyStart = $rangeUsable ? CarbonImmutable::parse($rangeStart)->subYear()->toDateString() : null;
        $yoyEnd = $rangeUsable ? CarbonImmutable::parse($rangeEnd)->subYear()->toDateString() : null;
        $yoy = $rangeUsable && $yoyStart !== null && $yoyEnd !== null
            ? $this->propertySums($resourceId, $siteUrl, $yoyStart, $yoyEnd, self::DEFAULT_SEARCH_TYPE)
            : null;
        if (($yoy['rows'] ?? 0) === 0) {
            $yoy = null;
        }

        $queryCurrent = $rangeUsable
            ? $this->dimensionPerformance('gsc_query_daily', 'query', $resourceId, $siteUrl, $rangeStart, $rangeEnd, 400, 'impressions')
            : [];
        $queryPrevious = $compare && $rangeUsable
            ? $this->dimensionPerformance('gsc_query_daily', 'query', $resourceId, $siteUrl, $previousStart, $previousEnd, 400, 'impressions')
            : [];
        $pageCurrent = $rangeUsable
            ? $this->dimensionPerformance('gsc_page_daily', 'page', $resourceId, $siteUrl, $rangeStart, $rangeEnd, 150, 'impressions')
            : [];
        $pagePrevious = $compare && $rangeUsable
            ? $this->dimensionPerformance('gsc_page_daily', 'page', $resourceId, $siteUrl, $previousStart, $previousEnd, 150, 'impressions')
            : [];

        $queryMovements = $this->movements($queryCurrent, $queryPrevious, 'query');
        $pageMovements = $this->movements($pageCurrent, $pagePrevious, 'page');
        $opportunities = $this->opportunities($queryCurrent);
        $positionBands = $this->positionBands($queryCurrent);
        $brandSplit = $this->brandSplit($asset, $queryCurrent);

        $trend = $rangeUsable ? $this->propertyTrend($resourceId, $siteUrl, $rangeStart, $rangeEnd) : [];
        $surfaces = $rangeUsable ? $this->searchSurfaces($resourceId, $siteUrl, $rangeStart, $rangeEnd) : [];
        $devices = $rangeUsable ? $this->dimensionPerformance('gsc_device_daily', 'device', $resourceId, $siteUrl, $rangeStart, $rangeEnd, 10, 'clicks') : [];
        $countries = $rangeUsable ? $this->dimensionPerformance('gsc_country_daily', 'country', $resourceId, $siteUrl, $rangeStart, $rangeEnd, 12, 'clicks') : [];
        $appearances = $rangeUsable ? $this->searchAppearances($resourceId, $siteUrl, $rangeStart, $rangeEnd) : [];

        $cross = $rangeUsable ? [
            'page_device' => $this->crossDimensionPerformance('gsc_page_device_daily', ['page', 'device'], $resourceId, $siteUrl, $rangeStart, $rangeEnd, 30),
            'page_country' => $this->crossDimensionPerformance('gsc_page_country_daily', ['page', 'country'], $resourceId, $siteUrl, $rangeStart, $rangeEnd, 30),
            'query_device' => $this->crossDimensionPerformance('gsc_query_device_daily', ['query', 'device'], $resourceId, $siteUrl, $rangeStart, $rangeEnd, 30),
            'query_country' => $this->crossDimensionPerformance('gsc_query_country_daily', ['query', 'country'], $resourceId, $siteUrl, $rangeStart, $rangeEnd, 30),
        ] : ['page_device' => [], 'page_country' => [], 'query_device' => [], 'query_country' => []];

        $sitemaps = $this->sitemaps($resourceId, $siteUrl);
        $inspection = $this->urlInspectionSamples($resourceId, $siteUrl, 20);
        $cannibalization = $rangeUsable ? $this->cannibalizationCandidates($resourceId, $siteUrl, $rangeStart, $rangeEnd) : [];
        $risks = $this->riskSignals($current, $previous, $pageMovements, $sitemaps, $inspection, $compare);
        $topicClusters = $this->topicClusters($queryCurrent);

        return [
            'connected' => true,
            'has_data' => (int) $current['rows'] > 0,
            'property_name' => (string) ($resource->display_name ?: $siteUrl),
            'property_id' => $siteUrl,
            'property_type' => str_starts_with($siteUrl, 'sc-domain:') ? 'domain' : 'url_prefix',
            'external_resource_id' => $resourceId,
            'period' => [
                'start' => $rangeUsable ? $rangeStart : $requestedStart,
                'end' => $rangeUsable ? $rangeEnd : $requestedEnd,
                'requested_start' => $requestedStart,
                'requested_end' => $requestedEnd,
                'label' => $bounds['label'],
                'comparison_label' => $compare ? $comparison['label'] : null,
                'truncated_to_available_data' => $rangeUsable && $rangeEnd !== $requestedEnd,
            ],
            'coverage' => [
                'start' => $coverageStart,
                'end' => $coverageEnd,
                'last_collected_at' => filled($coverage?->last_collected_at) ? (string) $coverage->last_collected_at : null,
            ],
            'metrics' => [
                $this->metric('clicks', $current['clicks'], $previous['clicks'] ?? null, 'number', $compare),
                $this->metric('impressions', $current['impressions'], $previous['impressions'] ?? null, 'number', $compare),
                $this->rateMetric('ctr', $current['ctr'], $previous['ctr'] ?? null, $compare),
                $this->positionMetric($current['position'], $previous['position'] ?? null, $compare),
            ],
            'yoy_metrics' => [
                'clicks' => $this->percentDelta($current['clicks'], $yoy['clicks'] ?? null),
                'impressions' => $this->percentDelta($current['impressions'], $yoy['impressions'] ?? null),
                'ctr_pp' => $yoy !== null && $current['ctr'] !== null && $yoy['ctr'] !== null ? $current['ctr'] - $yoy['ctr'] : null,
                'position_improvement' => $yoy !== null && $current['position'] !== null && $yoy['position'] !== null ? $yoy['position'] - $current['position'] : null,
            ],
            'trend' => [
                'labels' => array_column($trend, 'date'),
                'clicks' => array_column($trend, 'clicks'),
                'impressions' => array_column($trend, 'impressions'),
                'ctr' => array_column($trend, 'ctr'),
                'position' => array_column($trend, 'position'),
            ],
            'health_summary' => [
                'rising_queries' => count($queryMovements['rising']),
                'falling_queries' => count($queryMovements['falling']),
                'new_queries' => count($queryMovements['new']),
                'lost_queries' => count($queryMovements['lost']),
                'rising_pages' => count($pageMovements['rising']),
                'falling_pages' => count($pageMovements['falling']),
                'opportunity_candidates' => count($opportunities['all']),
                'cannibalization_candidates' => count($cannibalization),
                'risk_signals' => count($risks),
            ],
            'queries' => [
                'top' => array_slice($this->sortRows($queryCurrent, 'clicks'), 0, 25),
                'rising' => $queryMovements['rising'],
                'falling' => $queryMovements['falling'],
                'new' => $queryMovements['new'],
                'lost' => $queryMovements['lost'],
                'position_bands' => $positionBands,
                'brand_split' => $brandSplit,
                'topic_clusters' => $topicClusters,
            ],
            'pages' => [
                'top' => array_slice($this->sortRows($pageCurrent, 'clicks'), 0, 25),
                'rising' => $pageMovements['rising'],
                'falling' => $pageMovements['falling'],
                'new' => $pageMovements['new'],
                'lost' => $pageMovements['lost'],
                'content_decay' => $this->contentDecay($pageCurrent, $pagePrevious),
            ],
            'opportunities' => $opportunities,
            'risks' => $risks,
            'cannibalization' => $cannibalization,
            'surfaces' => $surfaces,
            'devices' => $devices,
            'countries' => $countries,
            'search_appearances' => $appearances,
            'cross_dimensions' => $cross,
            'sitemaps' => $sitemaps,
            'index_health' => $this->indexHealth($inspection),
            'url_inspection' => $inspection,
            'ai_search' => [
                'available' => false,
                'reason' => 'provider_dataset_not_available',
            ],
            'data_quality' => [
                'property_totals_source' => 'gsc_property_daily',
                'default_search_type' => self::DEFAULT_SEARCH_TYPE,
                'central_resource_only' => true,
                'query_page_rows_are_not_site_totals' => true,
                'query_page_provider_limits_apply' => true,
                'average_position_is_impression_weighted' => true,
                'average_position_is_not_rank_tracker' => true,
                'brand_classification' => $brandSplit['classification'] ?? 'unavailable',
                'available_search_types' => array_values(array_map(static fn (array $row): string => (string) $row['search_type'], $surfaces)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyState(array $bounds, array $comparison, bool $compare): array
    {
        return [
            'connected' => false,
            'has_data' => false,
            'property_name' => null,
            'property_id' => null,
            'property_type' => null,
            'external_resource_id' => null,
            'period' => [
                'start' => $bounds['start']->toDateString(),
                'end' => $bounds['end']->toDateString(),
                'requested_start' => $bounds['start']->toDateString(),
                'requested_end' => $bounds['end']->toDateString(),
                'label' => $bounds['label'],
                'comparison_label' => $compare ? $comparison['label'] : null,
                'truncated_to_available_data' => false,
            ],
            'coverage' => ['start' => null, 'end' => null, 'last_collected_at' => null],
            'metrics' => [],
            'yoy_metrics' => [],
            'trend' => ['labels' => [], 'clicks' => [], 'impressions' => [], 'ctr' => [], 'position' => []],
            'health_summary' => [],
            'queries' => ['top' => [], 'rising' => [], 'falling' => [], 'new' => [], 'lost' => [], 'position_bands' => [], 'brand_split' => [], 'topic_clusters' => []],
            'pages' => ['top' => [], 'rising' => [], 'falling' => [], 'new' => [], 'lost' => [], 'content_decay' => []],
            'opportunities' => ['all' => [], 'low_ctr' => [], 'top_10' => [], 'page_two' => [], 'zero_click' => []],
            'risks' => [],
            'cannibalization' => [],
            'surfaces' => [],
            'devices' => [],
            'countries' => [],
            'search_appearances' => [],
            'cross_dimensions' => ['page_device' => [], 'page_country' => [], 'query_device' => [], 'query_country' => []],
            'sitemaps' => [],
            'index_health' => ['available' => false, 'total' => 0, 'indexable' => 0, 'issues' => 0, 'canonical_mismatches' => 0],
            'url_inspection' => [],
            'ai_search' => ['available' => false, 'reason' => 'provider_dataset_not_available'],
            'data_quality' => [],
        ];
    }

    /** @return array{0:string,1:string} */
    private function comparisonRange(string $start, string $end, string $mode, string $fallbackStart, string $fallbackEnd, bool $usable): array
    {
        if (! $usable) {
            return [$fallbackStart, $fallbackEnd];
        }

        $currentStart = CarbonImmutable::parse($start);
        $currentEnd = CarbonImmutable::parse($end);
        if ($mode === 'yoy') {
            return [$currentStart->subYear()->toDateString(), $currentEnd->subYear()->toDateString()];
        }

        $days = $currentStart->diffInDays($currentEnd) + 1;
        $previousEnd = $currentStart->subDay();

        return [$previousEnd->subDays($days - 1)->toDateString(), $previousEnd->toDateString()];
    }

    /** @return array{rows:int,clicks:int,impressions:int,ctr:?float,position:?float} */
    private function propertySums(int $resourceId, string $siteUrl, string $start, string $end, string $searchType): array
    {
        $rows = $this->baseQuery('gsc_property_daily', $resourceId, $siteUrl, $start, $end, $searchType)
            ->get(['clicks', 'impressions', 'metadata']);

        $clicks = 0;
        $impressions = 0;
        $positionNumerator = 0.0;
        $positionImpressions = 0;
        foreach ($rows as $row) {
            $dayClicks = (int) ($row->clicks ?? 0);
            $dayImpressions = (int) ($row->impressions ?? 0);
            $clicks += $dayClicks;
            $impressions += $dayImpressions;
            $position = $this->metadataFloat($row->metadata, 'provider_average_position');
            if ($position !== null && $dayImpressions > 0) {
                $positionNumerator += $position * $dayImpressions;
                $positionImpressions += $dayImpressions;
            }
        }

        return [
            'rows' => $rows->count(),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
            'position' => $positionImpressions > 0 ? $positionNumerator / $positionImpressions : null,
        ];
    }

    /** @return array{rows:int,clicks:int,impressions:int,ctr:?float,position:?float} */
    private function zeroPropertySums(): array
    {
        return ['rows' => 0, 'clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null];
    }

    /** @return list<array<string,mixed>> */
    private function propertyTrend(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        return $this->baseQuery('gsc_property_daily', $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
            ->orderBy('reporting_date')
            ->get(['reporting_date', 'clicks', 'impressions', 'metadata'])
            ->map(function ($row): array {
                $clicks = (int) ($row->clicks ?? 0);
                $impressions = (int) ($row->impressions ?? 0);

                return [
                    'date' => (string) $row->reporting_date,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                    'position' => $this->metadataFloat($row->metadata, 'provider_average_position'),
                ];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function dimensionPerformance(
        string $table,
        string $dimension,
        int $resourceId,
        string $siteUrl,
        string $start,
        string $end,
        int $limit,
        string $orderBy,
    ): array {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $aggregates = $this->baseQuery($table, $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
            ->groupBy($dimension)
            ->selectRaw($this->quote($dimension).' as dimension_value, COALESCE(SUM(clicks),0) as clicks_sum, COALESCE(SUM(impressions),0) as impressions_sum')
            ->orderByDesc($orderBy === 'clicks' ? 'clicks_sum' : 'impressions_sum')
            ->limit(max(1, min(1000, $limit)))
            ->get();

        $keys = $aggregates->pluck('dimension_value')->filter(fn ($value): bool => $value !== null && (string) $value !== '')->map(fn ($value): string => (string) $value)->values()->all();
        $details = [];
        if ($keys !== []) {
            $detailRows = $this->baseQuery($table, $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
                ->whereIn($dimension, $keys)
                ->get([$dimension, 'impressions', 'metadata']);
            foreach ($detailRows as $row) {
                $details[(string) $row->{$dimension}][] = $row;
            }
        }

        $results = [];
        foreach ($aggregates as $row) {
            $value = (string) $row->dimension_value;
            $clicks = (int) $row->clicks_sum;
            $impressions = (int) $row->impressions_sum;
            $positionNumerator = 0.0;
            $positionImpressions = 0;
            foreach ($details[$value] ?? [] as $detail) {
                $detailImpressions = (int) ($detail->impressions ?? 0);
                $position = $this->metadataFloat($detail->metadata, 'provider_average_position');
                if ($position !== null && $detailImpressions > 0) {
                    $positionNumerator += $position * $detailImpressions;
                    $positionImpressions += $detailImpressions;
                }
            }

            $results[] = [
                $dimension => $value,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                'position' => $positionImpressions > 0 ? $positionNumerator / $positionImpressions : null,
            ];
        }

        return $results;
    }

    /** @return list<array<string,mixed>> */
    private function searchSurfaces(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        $rows = $this->baseQuery('gsc_property_daily', $resourceId, $siteUrl, $start, $end, null)
            ->groupBy('search_type')
            ->selectRaw('search_type, COALESCE(SUM(clicks),0) as clicks_sum, COALESCE(SUM(impressions),0) as impressions_sum')
            ->orderByDesc('clicks_sum')
            ->get();

        return $rows->map(static function ($row): array {
            $clicks = (int) $row->clicks_sum;
            $impressions = (int) $row->impressions_sum;

            return [
                'search_type' => (string) $row->search_type,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
            ];
        })->all();
    }

    /** @return list<array<string,mixed>> */
    private function searchAppearances(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        if (! Schema::hasTable('gsc_search_appearance_daily')) {
            return [];
        }

        return $this->baseQuery('gsc_search_appearance_daily', $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
            ->groupBy('searchAppearance')
            ->selectRaw($this->quote('searchAppearance').' as appearance, COALESCE(SUM(clicks),0) as clicks_sum, COALESCE(SUM(impressions),0) as impressions_sum')
            ->orderByDesc('impressions_sum')
            ->limit(30)
            ->get()
            ->map(static function ($row): array {
                $clicks = (int) $row->clicks_sum;
                $impressions = (int) $row->impressions_sum;

                return [
                    'appearance' => (string) $row->appearance,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                ];
            })->all();
    }

    /** @param list<string> $dimensions
     *  @return list<array<string,mixed>>
     */
    private function crossDimensionPerformance(string $table, array $dimensions, int $resourceId, string $siteUrl, string $start, string $end, int $limit): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $selectDimensions = implode(', ', array_map(fn (string $dimension): string => $this->quote($dimension), $dimensions));

        return $this->baseQuery($table, $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
            ->groupBy($dimensions)
            ->selectRaw($selectDimensions.', COALESCE(SUM(clicks),0) as clicks_sum, COALESCE(SUM(impressions),0) as impressions_sum')
            ->orderByDesc('impressions_sum')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(function ($row) use ($dimensions): array {
                $result = [];
                foreach ($dimensions as $dimension) {
                    $result[$dimension] = (string) $row->{$dimension};
                }
                $result['clicks'] = (int) $row->clicks_sum;
                $result['impressions'] = (int) $row->impressions_sum;
                $result['ctr'] = $result['impressions'] > 0 ? ($result['clicks'] / $result['impressions']) * 100 : null;

                return $result;
            })->all();
    }

    /** @param list<array<string,mixed>> $current
     *  @param list<array<string,mixed>> $previous
     *  @return array<string,list<array<string,mixed>>>
     */
    private function movements(array $current, array $previous, string $dimension): array
    {
        $currentMap = collect($current)->keyBy($dimension);
        $previousMap = collect($previous)->keyBy($dimension);
        $rising = [];
        $falling = [];
        $new = [];
        $lost = [];

        foreach ($currentMap as $key => $row) {
            $before = $previousMap->get($key);
            if (! is_array($before)) {
                if ((int) ($row['impressions'] ?? 0) >= 10) {
                    $new[] = $row + ['click_delta' => (int) ($row['clicks'] ?? 0), 'impression_delta' => (int) ($row['impressions'] ?? 0)];
                }
                continue;
            }

            $clickDelta = (int) ($row['clicks'] ?? 0) - (int) ($before['clicks'] ?? 0);
            $impressionDelta = (int) ($row['impressions'] ?? 0) - (int) ($before['impressions'] ?? 0);
            $positionDelta = isset($row['position'], $before['position']) && $row['position'] !== null && $before['position'] !== null
                ? (float) $before['position'] - (float) $row['position']
                : null;
            $movement = $row + [
                'previous_clicks' => (int) ($before['clicks'] ?? 0),
                'previous_impressions' => (int) ($before['impressions'] ?? 0),
                'previous_position' => $before['position'] ?? null,
                'click_delta' => $clickDelta,
                'impression_delta' => $impressionDelta,
                'position_improvement' => $positionDelta,
            ];

            if ($clickDelta > 0 || ($clickDelta === 0 && $impressionDelta > 0)) {
                $rising[] = $movement;
            } elseif ($clickDelta < 0 || ($clickDelta === 0 && $impressionDelta < 0)) {
                $falling[] = $movement;
            }
        }

        foreach ($previousMap as $key => $row) {
            if (! $currentMap->has($key) && (int) ($row['impressions'] ?? 0) >= 10) {
                $lost[] = $row + [
                    'previous_clicks' => (int) ($row['clicks'] ?? 0),
                    'previous_impressions' => (int) ($row['impressions'] ?? 0),
                    'click_delta' => -(int) ($row['clicks'] ?? 0),
                    'impression_delta' => -(int) ($row['impressions'] ?? 0),
                ];
            }
        }

        usort($rising, static fn (array $a, array $b): int => ($b['click_delta'] <=> $a['click_delta']) ?: ($b['impression_delta'] <=> $a['impression_delta']));
        usort($falling, static fn (array $a, array $b): int => ($a['click_delta'] <=> $b['click_delta']) ?: ($a['impression_delta'] <=> $b['impression_delta']));
        usort($new, static fn (array $a, array $b): int => ($b['impressions'] <=> $a['impressions']));
        usort($lost, static fn (array $a, array $b): int => ($b['impressions'] <=> $a['impressions']));

        return [
            'rising' => array_slice($rising, 0, 20),
            'falling' => array_slice($falling, 0, 20),
            'new' => array_slice($new, 0, 20),
            'lost' => array_slice($lost, 0, 20),
        ];
    }

    /** @param list<array<string,mixed>> $queries
     *  @return array<string,list<array<string,mixed>>>
     */
    private function opportunities(array $queries): array
    {
        $lowCtr = [];
        $top10 = [];
        $pageTwo = [];
        $zeroClick = [];

        foreach ($queries as $row) {
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $ctr = $row['ctr'] === null ? null : (float) $row['ctr'];
            $position = $row['position'] === null ? null : (float) $row['position'];
            if ($position === null || $impressions < 30) {
                continue;
            }

            if ($position <= 10 && $impressions >= 100 && $ctr !== null && $ctr < 2.0) {
                $lowCtr[] = $row + ['opportunity_type' => 'low_ctr'];
            }
            if ($position >= 4 && $position <= 10 && $impressions >= 50) {
                $top10[] = $row + ['opportunity_type' => 'positions_4_10'];
            }
            if ($position > 10 && $position <= 20 && $impressions >= 50) {
                $pageTwo[] = $row + ['opportunity_type' => 'positions_11_20'];
            }
            if ($clicks === 0 && $impressions >= 100) {
                $zeroClick[] = $row + ['opportunity_type' => 'zero_click'];
            }
        }

        foreach ([$lowCtr, $top10, $pageTwo, $zeroClick] as &$bucket) {
            usort($bucket, static fn (array $a, array $b): int => ($b['impressions'] <=> $a['impressions']));
            $bucket = array_slice($bucket, 0, 15);
        }
        unset($bucket);

        $all = collect([...$lowCtr, ...$top10, ...$pageTwo, ...$zeroClick])
            ->unique(fn (array $row): string => ($row['query'] ?? '').'|'.($row['opportunity_type'] ?? ''))
            ->sortByDesc('impressions')
            ->take(30)
            ->values()
            ->all();

        return ['all' => $all, 'low_ctr' => $lowCtr, 'top_10' => $top10, 'page_two' => $pageTwo, 'zero_click' => $zeroClick];
    }

    /** @param list<array<string,mixed>> $queries
     *  @return array<string,int>
     */
    private function positionBands(array $queries): array
    {
        $bands = ['top_3' => 0, 'positions_4_10' => 0, 'positions_11_20' => 0, 'positions_21_50' => 0, 'positions_51_plus' => 0, 'unavailable' => 0];
        foreach ($queries as $row) {
            $position = $row['position'] ?? null;
            if ($position === null) {
                $bands['unavailable']++;
            } elseif ($position <= 3) {
                $bands['top_3']++;
            } elseif ($position <= 10) {
                $bands['positions_4_10']++;
            } elseif ($position <= 20) {
                $bands['positions_11_20']++;
            } elseif ($position <= 50) {
                $bands['positions_21_50']++;
            } else {
                $bands['positions_51_plus']++;
            }
        }

        return $bands;
    }

    /** @param list<array<string,mixed>> $queries
     *  @return array<string,mixed>
     */
    private function brandSplit(DigitalAsset $asset, array $queries): array
    {
        $brandName = trim((string) ($asset->brand?->name ?? ''));
        $domain = trim((string) ($asset->domain ?? ''));
        $domainStem = $domain !== '' ? explode('.', preg_replace('/^www\./i', '', $domain))[0] ?? '' : '';
        $terms = collect([$brandName, $domainStem])
            ->map(fn (string $term): string => $this->normalizeText($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->unique()
            ->values()
            ->all();

        if ($terms === []) {
            return ['classification' => 'unavailable', 'terms' => [], 'brand' => null, 'non_brand' => null];
        }

        $brand = ['clicks' => 0, 'impressions' => 0];
        $nonBrand = ['clicks' => 0, 'impressions' => 0];
        foreach ($queries as $row) {
            $query = $this->normalizeText((string) ($row['query'] ?? ''));
            $isBrand = collect($terms)->contains(fn (string $term): bool => str_contains($query, $term));
            $target = $isBrand ? 'brand' : 'non_brand';
            if ($target === 'brand') {
                $brand['clicks'] += (int) ($row['clicks'] ?? 0);
                $brand['impressions'] += (int) ($row['impressions'] ?? 0);
            } else {
                $nonBrand['clicks'] += (int) ($row['clicks'] ?? 0);
                $nonBrand['impressions'] += (int) ($row['impressions'] ?? 0);
            }
        }

        foreach ([$brand, $nonBrand] as &$bucket) {
            $bucket['ctr'] = $bucket['impressions'] > 0 ? ($bucket['clicks'] / $bucket['impressions']) * 100 : null;
        }
        unset($bucket);

        return [
            'classification' => 'heuristic_from_brand_and_domain',
            'terms' => $terms,
            'brand' => $brand,
            'non_brand' => $nonBrand,
            'non_additive_note' => 'query_rows_may_be_provider_limited',
        ];
    }

    /** @param list<array<string,mixed>> $queries
     *  @return list<array<string,mixed>>
     */
    private function topicClusters(array $queries): array
    {
        $stop = ['ve','ile','icin','bir','bu','mi','mu','mı','the','and','for','with','from','what','how','near','in','of','to','a','an'];
        $tokens = [];
        foreach (array_slice($queries, 0, 200) as $row) {
            $words = preg_split('/\s+/u', $this->normalizeText((string) ($row['query'] ?? ''))) ?: [];
            foreach (array_unique($words) as $word) {
                if (mb_strlen($word) < 3 || in_array($word, $stop, true) || is_numeric($word)) {
                    continue;
                }
                $tokens[$word] = ($tokens[$word] ?? 0) + (int) ($row['impressions'] ?? 0);
            }
        }
        arsort($tokens);
        $topTokens = array_slice(array_keys($tokens), 0, 8);
        $clusters = [];
        foreach ($topTokens as $token) {
            $matching = array_values(array_filter($queries, fn (array $row): bool => str_contains($this->normalizeText((string) ($row['query'] ?? '')), $token)));
            if (count($matching) < 2) {
                continue;
            }
            $clicks = array_sum(array_column($matching, 'clicks'));
            $impressions = array_sum(array_column($matching, 'impressions'));
            $clusters[] = [
                'label' => $token,
                'query_count' => count($matching),
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? ($clicks / $impressions) * 100 : null,
                'method' => 'deterministic_term_cluster',
            ];
        }

        usort($clusters, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return array_slice($clusters, 0, 8);
    }

    /** @param list<array<string,mixed>> $current
     *  @param list<array<string,mixed>> $previous
     *  @return list<array<string,mixed>>
     */
    private function contentDecay(array $current, array $previous): array
    {
        $previousMap = collect($previous)->keyBy('page');
        $rows = [];
        foreach ($current as $row) {
            $before = $previousMap->get($row['page'] ?? '');
            if (! is_array($before) || (int) ($before['clicks'] ?? 0) < 10) {
                continue;
            }
            $clickDelta = $this->percentDelta($row['clicks'] ?? null, $before['clicks'] ?? null);
            $impressionDelta = $this->percentDelta($row['impressions'] ?? null, $before['impressions'] ?? null);
            if ($clickDelta !== null && $clickDelta <= -20 && ($impressionDelta === null || $impressionDelta >= -20)) {
                $rows[] = $row + [
                    'click_delta_percent' => $clickDelta,
                    'impression_delta_percent' => $impressionDelta,
                    'previous_clicks' => $before['clicks'] ?? 0,
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => ($a['click_delta_percent'] <=> $b['click_delta_percent']));

        return array_slice($rows, 0, 15);
    }

    /** @return list<array<string,mixed>> */
    private function cannibalizationCandidates(int $resourceId, string $siteUrl, string $start, string $end): array
    {
        if (! Schema::hasTable('gsc_query_page_daily')) {
            return [];
        }

        $candidateQueries = $this->baseQuery('gsc_query_page_daily', $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
            ->groupBy('query')
            ->selectRaw('query, COUNT(DISTINCT page) as page_count, COALESCE(SUM(impressions),0) as impressions_sum, COALESCE(SUM(clicks),0) as clicks_sum')
            ->havingRaw('COUNT(DISTINCT page) >= 2')
            ->orderByDesc('impressions_sum')
            ->limit(12)
            ->get();

        $results = [];
        foreach ($candidateQueries as $candidate) {
            $pages = $this->baseQuery('gsc_query_page_daily', $resourceId, $siteUrl, $start, $end, self::DEFAULT_SEARCH_TYPE)
                ->where('query', (string) $candidate->query)
                ->groupBy('page')
                ->selectRaw('page, COALESCE(SUM(clicks),0) as clicks_sum, COALESCE(SUM(impressions),0) as impressions_sum')
                ->orderByDesc('impressions_sum')
                ->limit(5)
                ->get()
                ->map(static fn ($row): array => [
                    'page' => (string) $row->page,
                    'clicks' => (int) $row->clicks_sum,
                    'impressions' => (int) $row->impressions_sum,
                ])->all();

            $results[] = [
                'query' => (string) $candidate->query,
                'page_count' => (int) $candidate->page_count,
                'clicks' => (int) $candidate->clicks_sum,
                'impressions' => (int) $candidate->impressions_sum,
                'pages' => $pages,
                'classification' => 'potential_overlap_not_definitive_cannibalization',
            ];
        }

        return $results;
    }

    /** @return list<array<string,mixed>> */
    private function sitemaps(int $resourceId, string $siteUrl): array
    {
        if (! Schema::hasTable('gsc_sitemap_snapshot')) {
            return [];
        }

        $rows = DB::table('gsc_sitemap_snapshot')
            ->whereNull('digital_asset_id')
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl)
            ->orderByDesc('retrieved_at')
            ->limit(500)
            ->get();

        $seen = [];
        $results = [];
        foreach ($rows as $row) {
            $path = (string) $row->sitemap_path;
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $metadata = $this->decodeMetadata($row->metadata);
            $submitted = 0;
            foreach ((array) ($metadata['contents'] ?? []) as $content) {
                if (is_array($content)) {
                    $submitted += (int) ($content['submitted'] ?? 0);
                }
            }
            $results[] = [
                'path' => $path,
                'retrieved_at' => (string) $row->retrieved_at,
                'last_submitted' => $metadata['last_submitted'] ?? null,
                'last_downloaded' => $metadata['last_downloaded'] ?? null,
                'pending' => (bool) ($metadata['is_pending'] ?? false),
                'is_index' => (bool) ($metadata['is_sitemaps_index'] ?? false),
                'type' => $metadata['type'] ?? null,
                'warnings' => (int) ($metadata['warnings'] ?? 0),
                'errors' => (int) ($metadata['errors'] ?? 0),
                'submitted_urls' => $submitted,
            ];
        }

        return $results;
    }

    /** @return list<array<string,mixed>> */
    private function urlInspectionSamples(int $resourceId, string $siteUrl, int $limit): array
    {
        if (! Schema::hasTable('gsc_url_inspection_snapshot')) {
            return [];
        }

        $rows = DB::table('gsc_url_inspection_snapshot')
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl)
            ->orderByDesc('inspected_at')
            ->limit(min(500, max(1, $limit) * 25))
            ->get();

        $seen = [];
        $results = [];
        foreach ($rows as $row) {
            $page = (string) $row->page;
            if ($page === '' || isset($seen[$page])) {
                continue;
            }
            $seen[$page] = true;
            $meta = $this->decodeMetadata($row->metadata);
            $results[] = [
                'page' => $page,
                'inspected_at' => (string) $row->inspected_at,
                'verdict' => $meta['verdict'] ?? null,
                'coverage_state' => $meta['coverage_state'] ?? null,
                'robots_txt_state' => $meta['robots_txt_state'] ?? null,
                'indexing_state' => $meta['indexing_state'] ?? null,
                'last_crawl_time' => $meta['last_crawl_time'] ?? null,
                'page_fetch_state' => $meta['page_fetch_state'] ?? null,
                'google_canonical' => $meta['google_canonical'] ?? null,
                'user_canonical' => $meta['user_canonical'] ?? null,
                'crawled_as' => $meta['crawled_as'] ?? null,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /** @param list<array<string,mixed>> $samples
     *  @return array<string,mixed>
     */
    private function indexHealth(array $samples): array
    {
        if ($samples === []) {
            return ['available' => false, 'total' => 0, 'indexable' => 0, 'issues' => 0, 'canonical_mismatches' => 0];
        }

        $indexable = 0;
        $issues = 0;
        $mismatches = 0;
        foreach ($samples as $row) {
            $verdict = mb_strtoupper((string) ($row['verdict'] ?? ''));
            if (in_array($verdict, ['PASS', 'VERDICT_PASS'], true)) {
                $indexable++;
            } else {
                $issues++;
            }
            $googleCanonical = trim((string) ($row['google_canonical'] ?? ''));
            $userCanonical = trim((string) ($row['user_canonical'] ?? ''));
            if ($googleCanonical !== '' && $userCanonical !== '' && $googleCanonical !== $userCanonical) {
                $mismatches++;
            }
        }

        return [
            'available' => true,
            'total' => count($samples),
            'indexable' => $indexable,
            'issues' => $issues,
            'canonical_mismatches' => $mismatches,
            'scope' => 'controlled_url_inspection_sample',
        ];
    }

    /** @param array<string,mixed> $current
     *  @param array<string,mixed>|null $previous
     *  @param array<string,list<array<string,mixed>>> $pageMovements
     *  @param list<array<string,mixed>> $sitemaps
     *  @param list<array<string,mixed>> $inspection
     *  @return list<array<string,mixed>>
     */
    private function riskSignals(array $current, ?array $previous, array $pageMovements, array $sitemaps, array $inspection, bool $compare): array
    {
        $risks = [];
        if ($compare && is_array($previous)) {
            $clickDelta = $this->percentDelta($current['clicks'] ?? null, $previous['clicks'] ?? null);
            $impressionDelta = $this->percentDelta($current['impressions'] ?? null, $previous['impressions'] ?? null);
            if ($clickDelta !== null && $clickDelta <= -20) {
                $risks[] = ['type' => 'traffic_drop', 'severity' => $clickDelta <= -40 ? 'high' : 'medium', 'value' => $clickDelta];
            }
            if ($impressionDelta !== null && $impressionDelta <= -20) {
                $risks[] = ['type' => 'visibility_drop', 'severity' => $impressionDelta <= -40 ? 'high' : 'medium', 'value' => $impressionDelta];
            }
            if ($current['ctr'] !== null && $previous['ctr'] !== null && ($current['ctr'] - $previous['ctr']) <= -1.0) {
                $risks[] = ['type' => 'ctr_drop', 'severity' => 'medium', 'value' => $current['ctr'] - $previous['ctr']];
            }
            if ($current['position'] !== null && $previous['position'] !== null && ($current['position'] - $previous['position']) >= 2.0) {
                $risks[] = ['type' => 'position_deterioration', 'severity' => 'medium', 'value' => $current['position'] - $previous['position']];
            }
        }
        if (count($pageMovements['falling'] ?? []) >= 5) {
            $risks[] = ['type' => 'multiple_page_decline', 'severity' => 'medium', 'value' => count($pageMovements['falling'])];
        }
        $sitemapErrors = array_sum(array_map(static fn (array $row): int => (int) ($row['errors'] ?? 0), $sitemaps));
        if ($sitemapErrors > 0) {
            $risks[] = ['type' => 'sitemap_errors', 'severity' => 'high', 'value' => $sitemapErrors];
        }
        $canonicalMismatches = count(array_filter($inspection, static fn (array $row): bool => filled($row['google_canonical'] ?? null) && filled($row['user_canonical'] ?? null) && $row['google_canonical'] !== $row['user_canonical']));
        if ($canonicalMismatches > 0) {
            $risks[] = ['type' => 'canonical_mismatch', 'severity' => 'high', 'value' => $canonicalMismatches];
        }

        return $risks;
    }

    /** @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>>
     */
    private function sortRows(array $rows, string $key): array
    {
        usort($rows, static fn (array $a, array $b): int => (($b[$key] ?? 0) <=> ($a[$key] ?? 0)));

        return $rows;
    }

    /** @return array<string,mixed> */
    private function metric(string $key, int|float|null $value, int|float|null $previous, string $format, bool $compare): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'previous' => $compare ? $previous : null,
            'format' => $format,
            'delta' => $compare ? $this->percentDelta($value, $previous) : null,
            'delta_kind' => 'percent',
            'direction' => 'higher_is_better',
        ];
    }

    /** @return array<string,mixed> */
    private function rateMetric(string $key, ?float $value, ?float $previous, bool $compare): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'previous' => $compare ? $previous : null,
            'format' => 'percent',
            'delta' => $compare && $value !== null && $previous !== null ? $value - $previous : null,
            'delta_kind' => 'pp',
            'direction' => 'higher_is_better',
        ];
    }

    /** @return array<string,mixed> */
    private function positionMetric(?float $value, ?float $previous, bool $compare): array
    {
        return [
            'key' => 'position',
            'value' => $value,
            'previous' => $compare ? $previous : null,
            'format' => 'decimal',
            'delta' => $compare && $value !== null && $previous !== null ? $previous - $value : null,
            'delta_kind' => 'position_improvement',
            'direction' => 'lower_is_better',
        ];
    }

    private function percentDelta(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return (((float) $current - (float) $previous) / abs((float) $previous)) * 100;
    }

    private function baseQuery(
        string $table,
        int $resourceId,
        string $siteUrl,
        ?string $start = null,
        ?string $end = null,
        ?string $searchType = self::DEFAULT_SEARCH_TYPE,
    ): Builder {
        $query = DB::table($table)
            ->whereNull('digital_asset_id')
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl);

        if ($start !== null && $end !== null) {
            $query->whereBetween('reporting_date', [$start, $end]);
        }
        if ($searchType !== null && Schema::hasColumn($table, 'search_type')) {
            $query->where('search_type', $searchType);
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return (array) $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function metadataFloat(mixed $raw, string $key): ?float
    {
        $metadata = $this->decodeMetadata($raw);
        if (! array_key_exists($key, $metadata) || $metadata[$key] === null || $metadata[$key] === '') {
            return null;
        }

        return (float) $metadata[$key];
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($value))) ?? '');
    }

    private function quote(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }
}
