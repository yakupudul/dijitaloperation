<?php

namespace App\Services\Gsc;

use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate SQL over the GSC normalized data pool.
 * Property KPIs come from gsc_property_daily ONLY — never summed from query/page rows.
 */
class GscPoolReadRepository
{
    /**
     * Property-level sums for a date range from gsc_property_daily only.
     *
     * @return array{clicks: int, impressions: int, position_weighted_numerator: float, position_impressions: int, rows: int}
     */
    public function propertyDailySums(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
    ): array {
        $rows = DB::table('gsc_property_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->get(['clicks', 'impressions', 'metadata']);

        $clicks = 0;
        $impressions = 0;
        $positionNumerator = 0.0;
        $positionImpressions = 0;

        foreach ($rows as $row) {
            $dayClicks = (int) $row->clicks;
            $dayImpressions = (int) $row->impressions;
            $clicks += $dayClicks;
            $impressions += $dayImpressions;

            $position = $this->metadataFloat($row->metadata, 'provider_average_position');
            if ($position !== null && $dayImpressions > 0) {
                $positionNumerator += $position * $dayImpressions;
                $positionImpressions += $dayImpressions;
            }
        }

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'position_weighted_numerator' => $positionNumerator,
            'position_impressions' => $positionImpressions,
            'rows' => $rows->count(),
        ];
    }

    /**
     * @return list<array{date: string, clicks: int, impressions: int, position: ?float}>
     */
    public function propertyDailySeries(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
    ): array {
        return DB::table('gsc_property_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->orderBy('reporting_date')
            ->get(['reporting_date', 'clicks', 'impressions', 'metadata'])
            ->map(function ($row): array {
                return [
                    'date' => (string) $row->reporting_date,
                    'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                    'position' => $this->metadataFloat($row->metadata, 'provider_average_position'),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{query: string, clicks: int, impressions: int, position_weighted_numerator: float, position_impressions: int}>
     */
    public function topQueries(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
        int $limit = 10,
    ): array {
        $rows = DB::table('gsc_query_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('query')
            ->orderByDesc(DB::raw('SUM(clicks)'))
            ->limit($limit)
            ->selectRaw('query, COALESCE(SUM(clicks), 0) as clicks_sum, COALESCE(SUM(impressions), 0) as impressions_sum')
            ->get();

        $queryKeys = $rows->map(static fn ($row): string => (string) $row->query)->all();
        $detailsByQuery = [];
        if ($queryKeys !== []) {
            // One bounded detail query for the top-N queries (avoids N+1) — Prompt 65.
            $detailRows = DB::table('gsc_query_daily')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('site_url', $siteUrl)
                ->whereIn('query', $queryKeys)
                ->whereBetween('reporting_date', [$start, $end])
                ->get(['query', 'impressions', 'metadata']);
            foreach ($detailRows as $detail) {
                $detailsByQuery[(string) $detail->query][] = $detail;
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $positionNumerator = 0.0;
            $positionImpressions = 0;
            foreach ($detailsByQuery[(string) $row->query] ?? [] as $detail) {
                $dayImpressions = (int) $detail->impressions;
                $position = $this->metadataFloat($detail->metadata, 'provider_average_position');
                if ($position !== null && $dayImpressions > 0) {
                    $positionNumerator += $position * $dayImpressions;
                    $positionImpressions += $dayImpressions;
                }
            }

            $results[] = [
                'query' => (string) $row->query,
                'clicks' => (int) $row->clicks_sum,
                'impressions' => (int) $row->impressions_sum,
                'position_weighted_numerator' => $positionNumerator,
                'position_impressions' => $positionImpressions,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{page: string, clicks: int, impressions: int}>
     */
    public function topPages(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
        int $limit = 20,
    ): array {
        return DB::table('gsc_page_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('page')
            ->orderByDesc(DB::raw('SUM(clicks)'))
            ->limit($limit)
            ->selectRaw('page, COALESCE(SUM(clicks), 0) as clicks_sum, COALESCE(SUM(impressions), 0) as impressions_sum')
            ->get()
            ->map(static fn ($row): array => [
                'page' => (string) $row->page,
                'clicks' => (int) $row->clicks_sum,
                'impressions' => (int) $row->impressions_sum,
            ])
            ->all();
    }

    /**
     * @return list<array{device: string, clicks: int, impressions: int, position_weighted_numerator: float, position_impressions: int}>
     */
    public function devices(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
    ): array {
        $aggregates = DB::table('gsc_device_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('device')
            ->orderByDesc(DB::raw('SUM(clicks)'))
            ->selectRaw('device, COALESCE(SUM(clicks), 0) as clicks_sum, COALESCE(SUM(impressions), 0) as impressions_sum')
            ->get();

        $deviceKeys = $aggregates->map(static fn ($row): string => (string) $row->device)->all();
        $detailsByDevice = [];
        if ($deviceKeys !== []) {
            $detailRows = DB::table('gsc_device_daily')
                ->where('digital_asset_id', $digitalAssetId)
                ->where('external_resource_id', $externalResourceId)
                ->where('site_url', $siteUrl)
                ->whereIn('device', $deviceKeys)
                ->whereBetween('reporting_date', [$start, $end])
                ->get(['device', 'impressions', 'metadata']);
            foreach ($detailRows as $detail) {
                $detailsByDevice[(string) $detail->device][] = $detail;
            }
        }

        $results = [];
        foreach ($aggregates as $row) {
            $positionNumerator = 0.0;
            $positionImpressions = 0;
            foreach ($detailsByDevice[(string) $row->device] ?? [] as $detail) {
                $dayImpressions = (int) $detail->impressions;
                $position = $this->metadataFloat($detail->metadata, 'provider_average_position');
                if ($position !== null && $dayImpressions > 0) {
                    $positionNumerator += $position * $dayImpressions;
                    $positionImpressions += $dayImpressions;
                }
            }

            $results[] = [
                'device' => (string) $row->device,
                'clicks' => (int) $row->clicks_sum,
                'impressions' => (int) $row->impressions_sum,
                'position_weighted_numerator' => $positionNumerator,
                'position_impressions' => $positionImpressions,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{country: string, clicks: int, impressions: int}>
     */
    public function countries(
        int $digitalAssetId,
        int $externalResourceId,
        string $siteUrl,
        string $start,
        string $end,
        int $limit = 10,
    ): array {
        return DB::table('gsc_country_daily')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('external_resource_id', $externalResourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('country')
            ->orderByDesc(DB::raw('SUM(clicks)'))
            ->limit($limit)
            ->selectRaw('country, COALESCE(SUM(clicks), 0) as clicks_sum, COALESCE(SUM(impressions), 0) as impressions_sum')
            ->get()
            ->map(static fn ($row): array => [
                'country' => (string) $row->country,
                'clicks' => (int) $row->clicks_sum,
                'impressions' => (int) $row->impressions_sum,
            ])
            ->all();
    }

    /**
     * Latest snapshot rows per sitemap path.
     *
     * @return list<array<string, mixed>>
     */
    public function sitemaps(int $digitalAssetId, string $siteUrl): array
    {
        // Bound SQL before PHP dedupe (Prompt 65) — unique sitemap paths only.
        $rows = DB::table('gsc_sitemap_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('site_url', $siteUrl)
            ->orderByDesc('retrieved_at')
            ->limit(500)
            ->get();

        $seen = [];
        $results = [];
        foreach ($rows as $row) {
            $path = (string) $row->sitemap_path;
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $metadata = $this->decodeMetadata($row->metadata);
            $results[] = [
                'path' => $path,
                'retrieved_at' => $row->retrieved_at,
                'metadata' => $metadata,
            ];
        }

        return $results;
    }

    /**
     * Latest inspection row per page URL (controlled sample only).
     *
     * @return list<array<string, mixed>>
     */
    public function urlInspectionSamples(int $digitalAssetId, string $siteUrl, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        // Over-fetch for per-page dedupe without loading the entire snapshot history.
        $rows = DB::table('gsc_url_inspection_snapshot')
            ->where('digital_asset_id', $digitalAssetId)
            ->where('site_url', $siteUrl)
            ->orderByDesc('inspected_at')
            ->limit(min(500, $limit * 25))
            ->get();

        $seen = [];
        $results = [];
        foreach ($rows as $row) {
            $page = (string) $row->page;
            if (isset($seen[$page])) {
                continue;
            }
            $seen[$page] = true;

            $results[] = [
                'page' => $page,
                'inspected_at' => $row->inspected_at,
                'metadata' => $this->decodeMetadata($row->metadata),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function metadataFloat(mixed $raw, string $key): ?float
    {
        $meta = $this->decodeMetadata($raw);
        if (! array_key_exists($key, $meta) || $meta[$key] === null) {
            return null;
        }

        return (float) $meta[$key];
    }
}
