<?php

namespace App\Services\Ga4;

use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate SQL over the GA4 normalized data pool. No Livewire component
 * ever queries `ga4_*` tables directly — this is the single sanctioned entry point.
 * Every query is bounded by digital_asset_id + property_id + external_resource_id
 * (+ date range where applicable). Session-scoped only — no firstUser* metrics.
 */
class Ga4PoolReadRepository
{
    /**
     * Property-level sums for a date range. Deliberately excludes `totalUsers` —
     * GA4 unique users cannot be summed across days into a period total.
     *
     * @return array{sessions: int, engagedSessions: int, screenPageViews: int, userEngagementDuration: float, activeUsers: int, rows: int}
     */
    public function propertyDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        $row = DB::table('ga4_property_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COUNT(*) as rows_count, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum, COALESCE(SUM(screenPageViews), 0) as views_sum, COALESCE(SUM(userEngagementDuration), 0) as engagement_duration_sum, COALESCE(SUM(activeUsers), 0) as active_users_sum')
            ->first();

        return [
            'sessions' => (int) ($row->sessions_sum ?? 0),
            'engagedSessions' => (int) ($row->engaged_sum ?? 0),
            'screenPageViews' => (int) ($row->views_sum ?? 0),
            'userEngagementDuration' => (float) ($row->engagement_duration_sum ?? 0),
            'activeUsers' => (int) ($row->active_users_sum ?? 0),
            'rows' => (int) ($row->rows_count ?? 0),
        ];
    }

    /**
     * Daily sessions series for trend charting, ordered by reporting_date.
     *
     * @return list<array{date: string, sessions: int}>
     */
    public function propertyDailySeries(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        return DB::table('ga4_property_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->orderBy('reporting_date')
            ->get(['reporting_date', 'sessions'])
            ->map(static fn ($row): array => [
                'date' => (string) $row->reporting_date,
                'sessions' => (int) $row->sessions,
            ])
            ->all();
    }

    /**
     * Acquisition channel share source — aggregated sessions/engagedSessions per channel.
     *
     * @return list<array{channel: string, sessions: int, engagedSessions: int}>
     */
    public function acquisitionChannels(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        return DB::table('ga4_acquisition_channel_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionDefaultChannelGroup')
            ->orderByDesc(DB::raw('SUM(sessions)'))
            ->selectRaw('sessionDefaultChannelGroup as channel, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum')
            ->get()
            ->map(static fn ($row): array => [
                'channel' => (string) $row->channel,
                'sessions' => (int) $row->sessions_sum,
                'engagedSessions' => (int) $row->engaged_sum,
            ])
            ->all();
    }

    /**
     * @return list<array{source_medium: string, sessions: int, engagedSessions: int}>
     */
    public function sourceMedium(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
        int $limit = 10,
    ): array {
        return DB::table('ga4_source_medium_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionSource', 'sessionMedium')
            ->orderByDesc(DB::raw('SUM(sessions)'))
            ->limit($limit)
            ->selectRaw('sessionSource as source, sessionMedium as medium, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum')
            ->get()
            ->map(static fn ($row): array => [
                'source_medium' => $row->source.' / '.$row->medium,
                'sessions' => (int) $row->sessions_sum,
                'engagedSessions' => (int) $row->engaged_sum,
            ])
            ->all();
    }

    /**
     * @return list<array{campaign: string, sessions: int, engagedSessions: int}>
     */
    public function campaigns(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
        int $limit = 10,
    ): array {
        return DB::table('ga4_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('sessionCampaignName')
            ->orderByDesc(DB::raw('SUM(sessions)'))
            ->limit($limit)
            ->selectRaw('sessionCampaignName as campaign, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum')
            ->get()
            ->map(static fn ($row): array => [
                'campaign' => (string) $row->campaign,
                'sessions' => (int) $row->sessions_sum,
                'engagedSessions' => (int) $row->engaged_sum,
            ])
            ->all();
    }

    /**
     * Sessions with unset/empty campaign — used by FORMULA_GA4_UTM_UNAVAILABLE_PCT.
     */
    public function utmUnavailableSessions(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): int {
        return (int) DB::table('ga4_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->where(function ($query): void {
                $query->where('sessionCampaignName', '(not set)')
                    ->orWhere('sessionCampaignName', '');
            })
            ->sum('sessions');
    }

    /**
     * @return list<array{path: string, sessions: int, engagedSessions: int}>
     */
    public function landingPages(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
        int $limit = 10,
    ): array {
        return DB::table('ga4_landing_page_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('landingPage')
            ->orderByDesc(DB::raw('SUM(sessions)'))
            ->limit($limit)
            ->selectRaw('landingPage as path, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum')
            ->get()
            ->map(static fn ($row): array => [
                'path' => (string) $row->path,
                'sessions' => (int) $row->sessions_sum,
                'engagedSessions' => (int) $row->engaged_sum,
            ])
            ->all();
    }

    /**
     * @return list<array{event: string, count: int}>
     */
    public function events(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
        int $limit = 20,
    ): array {
        return DB::table('ga4_event_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('eventName')
            ->orderByDesc(DB::raw('SUM(eventCount)'))
            ->limit($limit)
            ->selectRaw('eventName as event, COALESCE(SUM(eventCount), 0) as count_sum')
            ->get()
            ->map(static fn ($row): array => [
                'event' => (string) $row->event,
                'count' => (int) $row->count_sum,
            ])
            ->all();
    }

    /**
     * @return list<array{device: string, sessions: int, engagedSessions: int}>
     */
    public function devices(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        return DB::table('ga4_device_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('deviceCategory')
            ->orderByDesc(DB::raw('SUM(sessions)'))
            ->selectRaw('deviceCategory as device, COALESCE(SUM(sessions), 0) as sessions_sum, COALESCE(SUM(engagedSessions), 0) as engaged_sum')
            ->get()
            ->map(static fn ($row): array => [
                'device' => (string) $row->device,
                'sessions' => (int) $row->sessions_sum,
                'engagedSessions' => (int) $row->engaged_sum,
            ])
            ->all();
    }

    /**
     * `UPSERT_CURRENT_STATE` — one row per digital_asset_id + property_id.
     *
     * @return array<string, mixed>|null
     */
    public function propertyMetadata(int $digitalAssetId, string $propertyId): ?array
    {
        $row = DB::table('ga4_property_metadata')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('property_id', $propertyId)
            ->first();

        if ($row === null) {
            return null;
        }

        $metadata = is_string($row->metadata ?? null)
            ? (json_decode((string) $row->metadata, true) ?: [])
            : [];

        return [
            'property_id' => $propertyId,
            'source_timezone' => $row->source_timezone ?? null,
            'metadata' => $metadata,
            'last_collected_at' => $row->last_collected_at ?? null,
        ];
    }
}
