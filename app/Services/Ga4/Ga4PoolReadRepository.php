<?php

namespace App\Services\Ga4;

use App\Services\Ga4\Support\Ga4CampaignGrain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
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
     * GA4 unique users cannot be summed across days into a period total. Optional
     * metrics remain null when they were never collected; measured zero remains 0.
     *
     * @return array{sessions: int, engagedSessions: int, screenPageViews: int, userEngagementDuration: float, activeUsers: int, newUsers: ?int, conversions: ?float, keyEvents: ?float, totalRevenue: ?float, rows: int}
     */
    public function propertyDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        return $this->aggregatePropertyDaily(
            DB::table('ga4_property_daily')->where('digital_asset_id', $digitalAssetId),
            $externalResourceId,
            $propertyId,
            $start,
            $end,
        );
    }

    /**
     * Property-level sums for one collection scope. A null digital asset id reads the
     * resource-first central pool (digital_asset_id = null); an id reads that asset's
     * bound collection. Same non-additive exclusions as propertyDailySums().
     *
     * @return array{sessions: int, engagedSessions: int, screenPageViews: int, userEngagementDuration: float, activeUsers: int, newUsers: ?int, conversions: ?float, keyEvents: ?float, totalRevenue: ?float, rows: int}
     */
    public function scopedPropertyDailySums(
        ?int $digitalAssetId,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        $query = DB::table('ga4_property_daily');
        $query = $digitalAssetId === null
            ? $query->whereNull('digital_asset_id')
            : $query->where('digital_asset_id', $digitalAssetId);

        return $this->aggregatePropertyDaily($query, $externalResourceId, $propertyId, $start, $end);
    }

    /**
     * @return array{sessions: int, engagedSessions: int, screenPageViews: int, userEngagementDuration: float, activeUsers: int, newUsers: ?int, conversions: ?float, keyEvents: ?float, totalRevenue: ?float, rows: int}
     */
    private function aggregatePropertyDaily(
        Builder $query,
        int $externalResourceId,
        string $propertyId,
        string $start,
        string $end,
    ): array {
        $row = $query
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->selectRaw('COUNT(*) as rows_count, COUNT(DISTINCT "reporting_date") as days_count, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum, COALESCE(SUM("screenPageViews"), 0) as views_sum, COALESCE(SUM("userEngagementDuration"), 0) as engagement_duration_sum, COALESCE(SUM("activeUsers"), 0) as active_users_sum, SUM("newUsers") as new_users_sum, COUNT("newUsers") as new_users_count, SUM("conversions") as conversions_sum, COUNT("conversions") as conversions_count, SUM("keyEvents") as key_events_sum, COUNT("keyEvents") as key_events_count, SUM("totalRevenue") as revenue_sum, COUNT("totalRevenue") as revenue_count')
            ->first();

        $rows = (int) ($row->rows_count ?? 0);
        $fullDayCoverage = $rows > 0
            && (int) ($row->days_count ?? 0) === $this->inclusiveDayCount($start, $end);

        /*
         * An optional metric total is only available when every row of every day in the
         * requested range carries it. SUM() ignores NULLs, so a single collected day would
         * otherwise present a partial total as the period total. Missing ≠ zero.
         */
        $complete = static fn (string $countAlias): bool => $fullDayCoverage && (int) ($row->{$countAlias} ?? 0) === $rows;

        return [
            'sessions' => (int) ($row->sessions_sum ?? 0),
            'engagedSessions' => (int) ($row->engaged_sum ?? 0),
            'screenPageViews' => (int) ($row->views_sum ?? 0),
            'userEngagementDuration' => (float) ($row->engagement_duration_sum ?? 0),
            'activeUsers' => (int) ($row->active_users_sum ?? 0),
            'newUsers' => $complete('new_users_count') ? (int) $row->new_users_sum : null,
            'conversions' => $complete('conversions_count') ? (float) $row->conversions_sum : null,
            'keyEvents' => $complete('key_events_count') ? (float) $row->key_events_sum : null,
            'totalRevenue' => $complete('revenue_count') ? (float) $row->revenue_sum : null,
            'rows' => $rows,
        ];
    }

    private function inclusiveDayCount(string $start, string $end): int
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        return $to->lessThan($from) ? 0 : (int) $from->diffInDays($to) + 1;
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
            ->orderByDesc(DB::raw('SUM("sessions")'))
            ->selectRaw('"sessionDefaultChannelGroup" as channel, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum')
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
            ->orderByDesc(DB::raw('SUM("sessions")'))
            ->limit($limit)
            ->selectRaw('"sessionSource" as source, "sessionMedium" as medium, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum')
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
        $query = DB::table('ga4_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end]);
        Ga4CampaignGrain::excludeSupersededLegacyRows($query);

        return $query
            ->groupBy('sessionCampaignName')
            ->orderByDesc(DB::raw('SUM("sessions")'))
            ->limit($limit)
            ->selectRaw('"sessionCampaignName" as campaign, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum')
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
        $query = DB::table('ga4_campaign_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end]);
        Ga4CampaignGrain::excludeSupersededLegacyRows($query);

        return (int) $query
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
            ->orderByDesc(DB::raw('SUM("sessions")'))
            ->limit($limit)
            ->selectRaw('"landingPage" as path, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum')
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
            ->orderByDesc(DB::raw('SUM("eventCount")'))
            ->limit($limit)
            ->selectRaw('"eventName" as event, COALESCE(SUM("eventCount"), 0) as count_sum')
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
            ->orderByDesc(DB::raw('SUM("sessions")'))
            ->selectRaw('"deviceCategory" as device, COALESCE(SUM("sessions"), 0) as sessions_sum, COALESCE(SUM("engagedSessions"), 0) as engaged_sum')
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
