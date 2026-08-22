<?php

namespace App\Services\Ga4;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Operator\OperatorReportingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website-scoped GA4 read model over the central Data Pool.
 *
 * Important: central GA4 rows are owned by external_resource_id + property_id.
 * They are not copied into a Website asset when a binding is created. The Website
 * merely references the already-collected provider resource and reads the same facts.
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
        $rangeStart = $bounds['start']->toDateString();
        $rangeEnd = $bounds['end']->toDateString();
        $prevStart = $comparison['start']->toDateString();
        $prevEnd = $comparison['end']->toDateString();

        if (! Schema::hasTable('ga4_property_daily')) {
            return array_merge($empty, [
                'connected' => true,
                'property_id' => $propertyId,
                'property_name' => $resource->display_name ?: 'Google Analytics',
            ]);
        }

        $coverage = DB::table('ga4_property_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->selectRaw('MIN(reporting_date) as min_date, MAX(reporting_date) as max_date, MAX(last_collected_at) as last_collected_at')
            ->first();

        $current = $this->propertySums($resourceId, $propertyId, $rangeStart, $rangeEnd);
        $previous = $compare
            ? $this->propertySums($resourceId, $propertyId, $prevStart, $prevEnd)
            : null;

        $sessions = (int) $current['sessions'];
        $engaged = (int) $current['engagedSessions'];
        $engagementRate = $sessions > 0 ? ($engaged / $sessions) * 100 : null;
        $previousEngagementRate = $previous && (int) $previous['sessions'] > 0
            ? ((int) $previous['engagedSessions'] / (int) $previous['sessions']) * 100
            : null;

        $metrics = [
            $this->metric('sessions', $sessions, $previous['sessions'] ?? null, 'number', $compare),
            $this->metric('new_users', $current['newUsers'], $previous['newUsers'] ?? null, 'number', $compare),
            $this->rateMetric('engagement_rate', $engagementRate, $previousEngagementRate, $compare),
            $this->metric('views', $current['screenPageViews'], $previous['screenPageViews'] ?? null, 'number', $compare),
        ];

        $trendRows = DB::table('ga4_property_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$rangeStart, $rangeEnd])
            ->orderBy('reporting_date')
            ->get(['reporting_date', 'sessions', 'screenPageViews', 'newUsers'])
            ->map(static fn ($row): array => [
                'date' => (string) $row->reporting_date,
                'sessions' => (int) ($row->sessions ?? 0),
                'views' => (int) ($row->screenPageViews ?? 0),
                'new_users' => (int) ($row->newUsers ?? 0),
            ])
            ->all();

        return [
            'connected' => true,
            'has_data' => $current['rows'] > 0,
            'property_id' => $propertyId,
            'property_name' => $resource->display_name ?: 'Google Analytics',
            'external_resource_id' => $resourceId,
            'period' => [
                'start' => $rangeStart,
                'end' => $rangeEnd,
                'label' => $bounds['label'],
                'comparison_label' => $compare ? $comparison['label'] : null,
            ],
            'coverage' => [
                'start' => $coverage?->min_date ? (string) $coverage->min_date : null,
                'end' => $coverage?->max_date ? (string) $coverage->max_date : null,
                'last_collected_at' => $coverage?->last_collected_at ? (string) $coverage->last_collected_at : null,
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
            'channels' => $this->channels($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'first_user_acquisition' => $this->firstUserAcquisition($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'source_medium' => $this->sourceMedium($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'campaigns' => $this->campaigns($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'landing_pages' => $this->landingPages($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'pages' => $this->pages($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'events' => $this->events($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'key_events' => $this->keyEvents($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'devices' => $this->devices($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'browsers' => $this->browsers($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'countries' => $this->countries($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'cities' => $this->cities($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'busy_hours' => $this->busyHours($resourceId, $propertyId, $rangeStart, $rangeEnd),
            'ecommerce' => $this->ecommerce($resourceId, $propertyId, $rangeStart, $rangeEnd, $current),
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
                'label' => $bounds['label'],
                'comparison_label' => $compare ? $comparison['label'] : null,
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

    /** @return array<string, int|float|null> */
    private function propertySums(int $resourceId, string $propertyId, string $start, string $end): array
    {
        $row = DB::table('ga4_property_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(sessions),0) as sessions_sum, COALESCE(SUM(engagedSessions),0) as engaged_sum, SUM(newUsers) as new_users_sum, COALESCE(SUM(screenPageViews),0) as views_sum, COALESCE(SUM(eventCount),0) as event_count_sum, SUM(keyEvents) as key_events_sum, SUM(totalRevenue) as revenue_sum')
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
        $delta = $compare && $value !== null && $previous !== null ? $value - $previous : null;

        return ['key' => $key, 'value' => $value, 'format' => 'percent', 'delta' => $delta, 'delta_kind' => 'pp'];
    }

    /** @return list<array<string, mixed>> */
    private function channels(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_acquisition_channel_daily')) return [];
        $rows = DB::table('ga4_acquisition_channel_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionDefaultChannelGroup')->orderByDesc(DB::raw('SUM(sessions)'))->limit(8)
            ->selectRaw('sessionDefaultChannelGroup as label, COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(engagedSessions),0) as engaged')->get();
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
        if (! Schema::hasTable('ga4_first_user_acquisition_daily')) return [];
        return DB::table('ga4_first_user_acquisition_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('firstUserDefaultChannelGroup')->orderByDesc(DB::raw('SUM(newUsers)'))->limit(8)
            ->selectRaw('firstUserDefaultChannelGroup as label, COALESCE(SUM(newUsers),0) as new_users, COALESCE(SUM(activeUsers),0) as active_users')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'new_users' => (int) $row->new_users, 'active_users' => (int) $row->active_users])->all();
    }

    /** @return list<array<string, mixed>> */
    private function sourceMedium(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_source_medium_daily')) return [];
        return DB::table('ga4_source_medium_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionSource', 'sessionMedium')->orderByDesc(DB::raw('SUM(sessions)'))->limit(10)
            ->selectRaw('sessionSource as source, sessionMedium as medium, COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(engagedSessions),0) as engaged')->get()
            ->map(static fn ($row): array => ['label' => trim((string) $row->source).' / '.trim((string) $row->medium), 'sessions' => (int) $row->sessions, 'engaged' => (int) $row->engaged])->all();
    }

    /** @return list<array<string, mixed>> */
    private function campaigns(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_campaign_daily')) return [];
        return DB::table('ga4_campaign_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionCampaignName')->orderByDesc(DB::raw('SUM(sessions)'))->limit(10)
            ->selectRaw('sessionCampaignName as label, COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(engagedSessions),0) as engaged')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'sessions' => (int) $row->sessions, 'engaged' => (int) $row->engaged])->all();
    }

    /** @return list<array<string, mixed>> */
    private function landingPages(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_landing_page_daily')) return [];
        return DB::table('ga4_landing_page_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('landingPage')->orderByDesc(DB::raw('SUM(sessions)'))->limit(10)
            ->selectRaw('landingPage as label, COALESCE(SUM(sessions),0) as sessions, COALESCE(SUM(engagedSessions),0) as engaged')->get()
            ->map(static fn ($row): array => [
                'label' => (string) $row->label,
                'sessions' => (int) $row->sessions,
                'engaged' => (int) $row->engaged,
                'engagement_rate' => (int) $row->sessions > 0 ? round(((int) $row->engaged / (int) $row->sessions) * 100, 1) : null,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function pages(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_page_content_daily')) return [];
        return DB::table('ga4_page_content_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('pagePathPlusQueryString', 'pageTitle')->orderByDesc(DB::raw('SUM(screenPageViews)'))->limit(10)
            ->selectRaw('pagePathPlusQueryString as path, pageTitle as title, COALESCE(SUM(screenPageViews),0) as views, COALESCE(SUM(eventCount),0) as events')->get()
            ->map(static fn ($row): array => ['path' => (string) $row->path, 'title' => (string) $row->title, 'views' => (int) $row->views, 'events' => (int) $row->events])->all();
    }

    /** @return list<array<string, mixed>> */
    private function events(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_event_daily')) return [];
        return DB::table('ga4_event_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('eventName')->orderByDesc(DB::raw('SUM(eventCount)'))->limit(12)
            ->selectRaw('eventName as label, COALESCE(SUM(eventCount),0) as events')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'events' => (int) $row->events])->all();
    }

    /** @return list<array<string, mixed>> */
    private function keyEvents(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_key_event_daily')) return [];
        return DB::table('ga4_key_event_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('eventName')->orderByDesc(DB::raw('SUM(keyEvents)'))->limit(10)
            ->selectRaw('eventName as label, COALESCE(SUM(keyEvents),0) as events')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'events' => (float) $row->events])->all();
    }

    /** @return list<array<string, mixed>> */
    private function devices(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_device_daily')) return [];
        return DB::table('ga4_device_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('deviceCategory')->orderByDesc(DB::raw('SUM(sessions)'))
            ->selectRaw('deviceCategory as label, COALESCE(SUM(sessions),0) as sessions')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'sessions' => (int) $row->sessions])->all();
    }

    /** @return list<array<string, mixed>> */
    private function browsers(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_technology_daily')) return [];
        return DB::table('ga4_technology_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('browser')->orderByDesc(DB::raw('SUM(sessions)'))->limit(6)
            ->selectRaw('browser as label, COALESCE(SUM(sessions),0) as sessions')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'sessions' => (int) $row->sessions])->all();
    }

    /** @return list<array<string, mixed>> */
    private function countries(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_geo_country_daily')) return [];
        return DB::table('ga4_geo_country_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('country')->orderByDesc(DB::raw('SUM(sessions)'))->limit(8)
            ->selectRaw('country as label, COALESCE(SUM(sessions),0) as sessions')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'sessions' => (int) $row->sessions])->all();
    }

    /** @return list<array<string, mixed>> */
    private function cities(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_geo_city_daily')) return [];
        return DB::table('ga4_geo_city_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('city')->orderByDesc(DB::raw('SUM(sessions)'))->limit(8)
            ->selectRaw('city as label, COALESCE(SUM(sessions),0) as sessions')->get()
            ->map(static fn ($row): array => ['label' => (string) $row->label, 'sessions' => (int) $row->sessions])->all();
    }

    /** @return list<array<string, mixed>> */
    private function busyHours(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_hour_daily')) return [];
        return DB::table('ga4_hour_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('dayOfWeek', 'hour')->orderByDesc(DB::raw('SUM(sessions)'))->limit(6)
            ->selectRaw('dayOfWeek as day, hour, COALESCE(SUM(sessions),0) as sessions')->get()
            ->map(static fn ($row): array => ['day' => (string) $row->day, 'hour' => (string) $row->hour, 'sessions' => (int) $row->sessions])->all();
    }

    /** @return array<string, mixed> */
    private function ecommerce(int $resourceId, string $propertyId, string $start, string $end, array $current): array
    {
        if (! Schema::hasTable('ga4_ecommerce_item_daily')) {
            return ['has_data' => false, 'items' => [], 'revenue' => $current['totalRevenue'], 'purchases' => null];
        }
        $items = DB::table('ga4_ecommerce_item_daily')
            ->where('external_resource_id', $resourceId)->where('property_id', $propertyId)->whereBetween('reporting_date', [$start, $end])
            ->groupBy('itemId', 'itemName')->orderByDesc(DB::raw('SUM(itemsPurchased)'))->limit(10)
            ->selectRaw('itemId as item_id, itemName as item_name, COALESCE(SUM(itemsViewed),0) as views, COALESCE(SUM(itemsAddedToCart),0) as carts, COALESCE(SUM(itemsPurchased),0) as purchases, SUM(itemRevenue) as revenue')->get()
            ->map(static fn ($row): array => ['item_id' => (string) $row->item_id, 'item_name' => (string) $row->item_name, 'views' => (int) $row->views, 'carts' => (int) $row->carts, 'purchases' => (int) $row->purchases, 'revenue' => $row->revenue !== null ? (float) $row->revenue : null])->all();
        $purchases = array_sum(array_column($items, 'purchases'));
        $hasData = $purchases > 0 || collect($items)->sum('views') > 0 || (float) ($current['totalRevenue'] ?? 0) > 0;
        return ['has_data' => $hasData, 'items' => $items, 'revenue' => $current['totalRevenue'], 'purchases' => $purchases];
    }
}
