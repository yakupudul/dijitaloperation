<?php

namespace App\Services\Ga4;

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

/**
 * Website-scoped GA4 read model over the resource-first central Data Pool.
 * A Website binding references the already-collected GA4 resource; provider facts
 * are not copied or recollected for the Digital Asset.
 */
final class WebsiteGa4AnalysisService
{
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
            ->where('capability', Ga4SpecialistBindingResolver::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $binding instanceof CoreAssetBinding || ! $binding->externalResource instanceof CoreExternalResource) {
            return $empty;
        }

        $resource = $binding->externalResource;
        if ($resource->resource_type !== GoogleResourceType::GA4_PROPERTY) {
            return $empty;
        }

        $propertyId = preg_replace('/^properties\//', '', trim((string) $resource->external_id)) ?: '';
        if ($propertyId === '') {
            return $empty;
        }

        $resourceId = (int) $resource->id;
        if (! Schema::hasTable('ga4_property_daily')) {
            return array_merge($empty, [
                'connected' => true,
                'property_id' => $propertyId,
                'property_name' => $resource->display_name ?: 'Google Analytics',
                'external_resource_id' => $resourceId,
            ]);
        }

        $requestedStart = $bounds['start']->toDateString();
        $requestedEnd = $bounds['end']->toDateString();

        $coverage = $this->baseQuery('ga4_property_daily', $resourceId, $propertyId)
            ->selectRaw('MIN("reporting_date") as min_date, MAX("reporting_date") as max_date, MAX("last_collected_at") as last_collected_at')
            ->first();

        $coverageEnd = filled($coverage?->max_date) ? (string) $coverage->max_date : null;
        $rangeStart = $requestedStart;
        $rangeEnd = $coverageEnd !== null && $coverageEnd < $requestedEnd ? $coverageEnd : $requestedEnd;
        $rangeIsUsable = $rangeEnd >= $rangeStart;

        [$prevStart, $prevEnd] = $this->comparisonRange(
            $rangeStart,
            $rangeEnd,
            $compareMode,
            $comparison['start']->toDateString(),
            $comparison['end']->toDateString(),
            $rangeIsUsable,
        );

        $current = $rangeIsUsable
            ? $this->propertySums($resourceId, $propertyId, $rangeStart, $rangeEnd)
            : $this->zeroPropertySums();
        $previous = $compare && $rangeIsUsable
            ? $this->propertySums($resourceId, $propertyId, $prevStart, $prevEnd)
            : null;

        $sessions = (int) $current['sessions'];
        $engaged = (int) $current['engagedSessions'];
        $engagementRate = $sessions > 0 ? ($engaged / $sessions) * 100 : null;
        $previousEngagementRate = $previous && (int) $previous['sessions'] > 0
            ? ((int) $previous['engagedSessions'] / (int) $previous['sessions']) * 100
            : null;

        $trendRows = $rangeIsUsable
            ? $this->baseQuery('ga4_property_daily', $resourceId, $propertyId, $rangeStart, $rangeEnd)
                ->orderBy('reporting_date')
                ->get(['reporting_date', 'sessions', 'screenPageViews', 'newUsers'])
                ->map(static fn ($row): array => [
                    'date' => (string) $row->reporting_date,
                    'sessions' => (int) ($row->sessions ?? 0),
                    'views' => (int) ($row->screenPageViews ?? 0),
                    'new_users' => (int) ($row->newUsers ?? 0),
                ])
                ->all()
            : [];

        $metrics = [
            $this->metric('sessions', $sessions, $previous['sessions'] ?? null, 'number', $compare),
            $this->metric('new_users', $current['newUsers'], $previous['newUsers'] ?? null, 'number', $compare),
            $this->rateMetric('engagement_rate', $engagementRate, $previousEngagementRate, $compare),
            $this->metric('views', $current['screenPageViews'], $previous['screenPageViews'] ?? null, 'number', $compare),
        ];

        return [
            'connected' => true,
            'has_data' => $current['rows'] > 0,
            'property_id' => $propertyId,
            'property_name' => $resource->display_name ?: 'Google Analytics',
            'external_resource_id' => $resourceId,
            'period' => [
                'start' => $rangeIsUsable ? $rangeStart : $requestedStart,
                'end' => $rangeIsUsable ? $rangeEnd : $requestedEnd,
                'requested_start' => $requestedStart,
                'requested_end' => $requestedEnd,
                'label' => $bounds['label'],
                'comparison_label' => $compare ? $comparison['label'] : null,
                'truncated_to_available_data' => $rangeIsUsable && $rangeEnd !== $requestedEnd,
            ],
            'coverage' => [
                'start' => filled($coverage?->min_date) ? (string) $coverage->min_date : null,
                'end' => $coverageEnd,
                'last_collected_at' => filled($coverage?->last_collected_at) ? (string) $coverage->last_collected_at : null,
            ],
            'metrics' => $metrics,
            'secondary_metrics' => [
                'engaged_sessions' => (int) $current['engagedSessions'],
                'events' => (int) $current['eventCount'],
                'key_events' => $current['keyEvents'],
                'revenue' => $current['totalRevenue'],
            ],
            'trend' => [
                'labels' => array_column($trendRows, 'date'),
                'sessions' => array_column($trendRows, 'sessions'),
                'views' => array_column($trendRows, 'views'),
                'new_users' => array_column($trendRows, 'new_users'),
            ],
            'channels' => $rangeIsUsable ? $this->channels($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'first_user_acquisition' => $rangeIsUsable ? $this->firstUserAcquisition($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'source_medium' => $rangeIsUsable ? $this->sourceMedium($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'campaigns' => $rangeIsUsable ? $this->campaigns($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'landing_pages' => $rangeIsUsable ? $this->landingPages($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'pages' => $rangeIsUsable ? $this->pages($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'events' => $rangeIsUsable ? $this->events($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'key_events' => $rangeIsUsable ? $this->keyEvents($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'devices' => $rangeIsUsable ? $this->devices($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'browsers' => $rangeIsUsable ? $this->browsers($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'countries' => $rangeIsUsable ? $this->countries($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'cities' => $rangeIsUsable ? $this->cities($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'busy_hours' => $rangeIsUsable ? $this->busyHours($resourceId, $propertyId, $rangeStart, $rangeEnd) : [],
            'ecommerce' => $rangeIsUsable
                ? $this->ecommerce($resourceId, $propertyId, $rangeStart, $rangeEnd, $current)
                : ['has_data' => false, 'items' => [], 'revenue' => null, 'purchases' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyState(array $bounds, array $comparison, bool $compare): array
    {
        return [
            'connected' => false,
            'has_data' => false,
            'property_id' => null,
            'property_name' => null,
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
            'secondary_metrics' => [],
            'trend' => ['labels' => [], 'sessions' => [], 'views' => [], 'new_users' => []],
            'channels' => [],
            'first_user_acquisition' => [],
            'source_medium' => [],
            'campaigns' => [],
            'landing_pages' => [],
            'pages' => [],
            'events' => [],
            'key_events' => [],
            'devices' => [],
            'browsers' => [],
            'countries' => [],
            'cities' => [],
            'busy_hours' => [],
            'ecommerce' => ['has_data' => false, 'items' => [], 'revenue' => null, 'purchases' => null],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function comparisonRange(
        string $start,
        string $end,
        string $mode,
        string $fallbackStart,
        string $fallbackEnd,
        bool $usable,
    ): array {
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

    /** @return array<string, int|float|null> */
    private function propertySums(int $resourceId, string $propertyId, string $start, string $end): array
    {
        $row = $this->baseQuery('ga4_property_daily', $resourceId, $propertyId, $start, $end)
            ->selectRaw(implode(', ', [
                'COUNT(*) as rows_count',
                'COALESCE(SUM("sessions"), 0) as sessions_sum',
                'COALESCE(SUM("engagedSessions"), 0) as engaged_sum',
                'SUM("newUsers") as new_users_sum',
                'COALESCE(SUM("screenPageViews"), 0) as views_sum',
                'COALESCE(SUM("eventCount"), 0) as event_count_sum',
                'SUM("keyEvents") as key_events_sum',
                'SUM("totalRevenue") as revenue_sum',
            ]))
            ->first();

        return [
            'rows' => (int) ($row->rows_count ?? 0),
            'sessions' => (int) ($row->sessions_sum ?? 0),
            'engagedSessions' => (int) ($row->engaged_sum ?? 0),
            'newUsers' => $row?->new_users_sum !== null ? (int) $row->new_users_sum : null,
            'screenPageViews' => (int) ($row->views_sum ?? 0),
            'eventCount' => (int) ($row->event_count_sum ?? 0),
            'keyEvents' => $row?->key_events_sum !== null ? (float) $row->key_events_sum : null,
            'totalRevenue' => $row?->revenue_sum !== null ? (float) $row->revenue_sum : null,
        ];
    }

    /** @return array<string, int|float|null> */
    private function zeroPropertySums(): array
    {
        return [
            'rows' => 0,
            'sessions' => 0,
            'engagedSessions' => 0,
            'newUsers' => null,
            'screenPageViews' => 0,
            'eventCount' => 0,
            'keyEvents' => null,
            'totalRevenue' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function metric(string $key, int|float|null $value, int|float|null $previous, string $format, bool $compare): array
    {
        $delta = null;
        if ($compare && $value !== null && $previous !== null && (float) $previous !== 0.0) {
            $delta = (((float) $value - (float) $previous) / abs((float) $previous)) * 100;
        }

        return ['key' => $key, 'value' => $value, 'format' => $format, 'delta' => $delta, 'delta_kind' => 'percent'];
    }

    /** @return array<string, mixed> */
    private function rateMetric(string $key, ?float $value, ?float $previous, bool $compare): array
    {
        return [
            'key' => $key,
            'value' => $value,
            'format' => 'percent',
            'delta' => $compare && $value !== null && $previous !== null ? $value - $previous : null,
            'delta_kind' => 'pp',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function channels(int $resourceId, string $propertyId, string $start, string $end): array
    {
        $rows = $this->grouped(
            'ga4_acquisition_channel_daily', $resourceId, $propertyId, $start, $end,
            ['sessionDefaultChannelGroup' => 'label'],
            ['sessions' => 'sessions', 'engagedSessions' => 'engaged'],
            'sessions', 8,
        );
        $total = max(1, (int) $rows->sum('sessions'));

        return $rows->map(static fn ($row): array => [
            'label' => (string) $row->label,
            'sessions' => (int) $row->sessions,
            'engaged' => (int) $row->engaged,
            'share' => round(((int) $row->sessions / $total) * 100, 1),
            'engagement_rate' => (int) $row->sessions > 0 ? round(((int) $row->engaged / (int) $row->sessions) * 100, 1) : null,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function firstUserAcquisition(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_first_user_acquisition_daily', $resourceId, $propertyId, $start, $end,
            ['firstUserDefaultChannelGroup' => 'label'],
            ['newUsers' => 'new_users', 'activeUsers' => 'active_users'],
            'newUsers', 8,
        )->map(static fn ($row): array => [
            'label' => (string) $row->label,
            'new_users' => (int) $row->new_users,
            'active_users' => (int) $row->active_users,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function sourceMedium(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_source_medium_daily', $resourceId, $propertyId, $start, $end,
            ['sessionSource' => 'source', 'sessionMedium' => 'medium'],
            ['sessions' => 'sessions', 'engagedSessions' => 'engaged'],
            'sessions', 10,
        )->map(static fn ($row): array => [
            'label' => trim((string) $row->source).' / '.trim((string) $row->medium),
            'sessions' => (int) $row->sessions,
            'engaged' => (int) $row->engaged,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_campaign_daily', $resourceId, $propertyId, $start, $end,
            ['sessionCampaignName' => 'label'],
            ['sessions' => 'sessions', 'engagedSessions' => 'engaged'],
            'sessions', 10,
        )->map(static fn ($row): array => [
            'label' => (string) $row->label,
            'sessions' => (int) $row->sessions,
            'engaged' => (int) $row->engaged,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function landingPages(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_landing_page_daily', $resourceId, $propertyId, $start, $end,
            ['landingPage' => 'label'],
            ['sessions' => 'sessions', 'engagedSessions' => 'engaged'],
            'sessions', 10,
        )->map(static fn ($row): array => [
            'label' => (string) $row->label,
            'sessions' => (int) $row->sessions,
            'engaged' => (int) $row->engaged,
            'engagement_rate' => (int) $row->sessions > 0 ? round(((int) $row->engaged / (int) $row->sessions) * 100, 1) : null,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function pages(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_page_content_daily', $resourceId, $propertyId, $start, $end,
            ['pagePathPlusQueryString' => 'path', 'pageTitle' => 'title'],
            ['screenPageViews' => 'views', 'eventCount' => 'events'],
            'screenPageViews', 10,
        )->map(static fn ($row): array => [
            'path' => (string) $row->path,
            'title' => (string) $row->title,
            'views' => (int) $row->views,
            'events' => (int) $row->events,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function events(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_event_daily', $resourceId, $propertyId, $start, $end,
            ['eventName' => 'label'], ['eventCount' => 'events'], 'eventCount', 12,
        )->map(static fn ($row): array => ['label' => (string) $row->label, 'events' => (int) $row->events])->all();
    }

    /** @return list<array<string, mixed>> */
    private function keyEvents(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_key_event_daily', $resourceId, $propertyId, $start, $end,
            ['eventName' => 'label'], ['keyEvents' => 'events'], 'keyEvents', 10,
        )->map(static fn ($row): array => ['label' => (string) $row->label, 'events' => (float) $row->events])->all();
    }

    /** @return list<array<string, mixed>> */
    private function devices(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->simpleSessionBreakdown('ga4_device_daily', 'deviceCategory', $resourceId, $propertyId, $start, $end, 6);
    }

    /** @return list<array<string, mixed>> */
    private function browsers(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->simpleSessionBreakdown('ga4_technology_daily', 'browser', $resourceId, $propertyId, $start, $end, 6);
    }

    /** @return list<array<string, mixed>> */
    private function countries(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->simpleSessionBreakdown('ga4_geo_country_daily', 'country', $resourceId, $propertyId, $start, $end, 8);
    }

    /** @return list<array<string, mixed>> */
    private function cities(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->simpleSessionBreakdown('ga4_geo_city_daily', 'city', $resourceId, $propertyId, $start, $end, 8);
    }

    /** @return list<array<string, mixed>> */
    private function busyHours(int $resourceId, string $propertyId, string $start, string $end): array
    {
        return $this->grouped(
            'ga4_hour_daily', $resourceId, $propertyId, $start, $end,
            ['dayOfWeek' => 'day', 'hour' => 'hour'], ['sessions' => 'sessions'], 'sessions', 6,
        )->map(static fn ($row): array => [
            'day' => (string) $row->day,
            'hour' => (string) $row->hour,
            'sessions' => (int) $row->sessions,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function ecommerce(int $resourceId, string $propertyId, string $start, string $end, array $current): array
    {
        $rows = $this->grouped(
            'ga4_ecommerce_item_daily', $resourceId, $propertyId, $start, $end,
            ['itemId' => 'item_id', 'itemName' => 'item_name'],
            [
                'itemsViewed' => 'views',
                'itemsAddedToCart' => 'carts',
                'itemsPurchased' => 'purchases',
                'itemRevenue' => 'revenue',
            ],
            'itemsPurchased', 10,
        );

        $items = $rows->map(static fn ($row): array => [
            'item_id' => (string) $row->item_id,
            'item_name' => (string) $row->item_name,
            'views' => (int) $row->views,
            'carts' => (int) $row->carts,
            'purchases' => (int) $row->purchases,
            'revenue' => (float) $row->revenue,
        ])->all();

        $purchases = array_sum(array_column($items, 'purchases'));
        $hasData = $purchases > 0 || collect($items)->sum('views') > 0 || (float) ($current['totalRevenue'] ?? 0) > 0;

        return [
            'has_data' => $hasData,
            'items' => $items,
            'revenue' => $current['totalRevenue'],
            'purchases' => $purchases,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function simpleSessionBreakdown(
        string $table,
        string $dimension,
        int $resourceId,
        string $propertyId,
        string $start,
        string $end,
        int $limit,
    ): array {
        return $this->grouped(
            $table, $resourceId, $propertyId, $start, $end,
            [$dimension => 'label'], ['sessions' => 'sessions'], 'sessions', $limit,
        )->map(static fn ($row): array => [
            'label' => (string) $row->label,
            'sessions' => (int) $row->sessions,
        ])->all();
    }

    /**
     * @param array<string, string> $dimensions provider column => output alias
     * @param array<string, string> $metrics provider metric => output alias
     * @return Collection<int, object>
     */
    private function grouped(
        string $table,
        int $resourceId,
        string $propertyId,
        string $start,
        string $end,
        array $dimensions,
        array $metrics,
        string $orderMetric,
        int $limit,
    ): Collection {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $select = [];
        foreach ($dimensions as $column => $alias) {
            $select[] = $this->identifier($column).' as '.$this->identifier($alias);
        }
        foreach ($metrics as $column => $alias) {
            $select[] = 'COALESCE(SUM('.$this->identifier($column).'), 0) as '.$this->identifier($alias);
        }

        return $this->baseQuery($table, $resourceId, $propertyId, $start, $end)
            ->groupBy(...array_keys($dimensions))
            ->orderByDesc(DB::raw('SUM('.$this->identifier($orderMetric).')'))
            ->limit($limit)
            ->selectRaw(implode(', ', $select))
            ->get();
    }

    private function baseQuery(
        string $table,
        int $resourceId,
        string $propertyId,
        ?string $start = null,
        ?string $end = null,
    ): Builder {
        $query = DB::table($table)
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId);

        if ($start !== null && $end !== null) {
            $query->whereBetween('reporting_date', [$start, $end]);
        }

        return $query;
    }

    private function identifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
