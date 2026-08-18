<?php

namespace App\Services\Collection\Providers\SearchConsole;

/**
 * Provider response → canonical normalized records (no physical table names).
 */
final class SearchConsoleNormalizer
{
    /**
     * @param  list<string>  $dimensions
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $provenance
     * @return list<array<string, mixed>>
     */
    public function normalizeSearchAnalyticsRows(
        string $datasetId,
        string $siteUrl,
        array $dimensions,
        array $rows,
        array $provenance,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
    ): array {
        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keys = $row['keys'] ?? [];
            if (! is_array($keys)) {
                $keys = [];
            }

            $record = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'site_url' => $siteUrl,
                'clicks' => (int) round((float) ($row['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
                'source_timezone' => SearchConsoleProviderCapabilities::REPORTING_TIMEZONE,
                'metadata' => [
                    'provider_average_position' => isset($row['position']) ? (float) $row['position'] : null,
                    'provider_ctr' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                    'provider_ctr_semantic' => 'provider_reported_not_canonical_formula',
                    'search_type' => $provenance['search_type'] ?? 'web',
                    'data_state' => $provenance['data_state'] ?? 'final',
                    'aggregation_type' => $provenance['aggregation_type'] ?? null,
                    'response_aggregation_type' => $provenance['response_aggregation_type'] ?? null,
                    'request_family_id' => $provenance['request_family_id'] ?? null,
                    'provider_completeness' => SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS,
                    'execution_completeness' => SearchConsoleProviderCapabilities::EXECUTION_COMPLETENESS,
                    'collector_version' => $provenance['collector_version'] ?? null,
                ],
            ];

            foreach ($dimensions as $index => $dimension) {
                $value = $keys[$index] ?? null;
                if ($dimension === 'date') {
                    $record['reporting_date'] = is_string($value) ? $value : null;
                } elseif ($dimension === 'query') {
                    // Preserve exact provider query text — no lowercase/trim/stem.
                    $record['query'] = is_string($value) ? $value : (string) $value;
                } elseif ($dimension === 'page') {
                    $record['page'] = is_string($value) ? $value : (string) $value;
                } elseif ($dimension === 'device') {
                    $record['device'] = is_string($value) ? $value : (string) $value;
                } elseif ($dimension === 'country') {
                    $record['country'] = is_string($value) ? $value : (string) $value;
                }
            }

            if (($record['reporting_date'] ?? null) === null || $record['reporting_date'] === '') {
                continue;
            }

            if ($datasetId === 'gsc_query_daily' && ($record['query'] ?? '') === '') {
                continue;
            }
            if ($datasetId === 'gsc_page_daily' && ($record['page'] ?? '') === '') {
                continue;
            }
            if ($datasetId === 'gsc_query_page_daily' && (($record['query'] ?? '') === '' || ($record['page'] ?? '') === '')) {
                continue;
            }
            if ($datasetId === 'gsc_device_daily' && ($record['device'] ?? '') === '') {
                continue;
            }
            if ($datasetId === 'gsc_country_daily' && ($record['country'] ?? '') === '') {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $sitemaps
     * @return list<array<string, mixed>>
     */
    public function normalizeSitemaps(
        string $siteUrl,
        array $sitemaps,
        string $retrievedAt,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
    ): array {
        $records = [];
        foreach ($sitemaps as $sitemap) {
            if (! is_array($sitemap)) {
                continue;
            }
            $path = (string) ($sitemap['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $contents = [];
            foreach ($sitemap['contents'] ?? [] as $content) {
                if (! is_array($content)) {
                    continue;
                }
                $entry = [
                    'type' => $content['type'] ?? null,
                    'submitted' => isset($content['submitted']) ? (int) $content['submitted'] : null,
                    // Deprecated contents[].indexed is intentionally NOT treated as canonical indexing.
                    'indexed_deprecated_ignored' => true,
                ];
                $contents[] = $entry;
            }

            $records[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'site_url' => $siteUrl,
                'sitemap_path' => $path,
                'retrieved_at' => $retrievedAt,
                'source_timezone' => SearchConsoleProviderCapabilities::REPORTING_TIMEZONE,
                'metadata' => [
                    'last_submitted' => $sitemap['lastSubmitted'] ?? null,
                    'last_downloaded' => $sitemap['lastDownloaded'] ?? null,
                    'is_pending' => (bool) ($sitemap['isPending'] ?? false),
                    'is_sitemaps_index' => (bool) ($sitemap['isSitemapsIndex'] ?? false),
                    'type' => $sitemap['type'] ?? null,
                    'warnings' => isset($sitemap['warnings']) ? (int) $sitemap['warnings'] : null,
                    'errors' => isset($sitemap['errors']) ? (int) $sitemap['errors'] : null,
                    'contents' => $contents,
                    'submitted_not_indexed' => true,
                    'deprecated_indexed_used' => false,
                    'sitemap_indexation_rate_created' => false,
                    'provider_completeness' => 'SITEMAP_METADATA_SNAPSHOT',
                ],
            ];
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $inspectionResult
     * @return array<string, mixed>
     */
    public function normalizeUrlInspection(
        string $siteUrl,
        string $page,
        string $inspectedAt,
        array $inspectionResult,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
    ): array {
        $indexStatus = $inspectionResult['inspectionResult']['indexStatusResult'] ?? $inspectionResult['indexStatusResult'] ?? [];
        if (! is_array($indexStatus)) {
            $indexStatus = [];
        }

        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'site_url' => $siteUrl,
            'page' => $page,
            'inspected_at' => $inspectedAt,
            'source_timezone' => SearchConsoleProviderCapabilities::REPORTING_TIMEZONE,
            'metadata' => [
                'verdict' => $indexStatus['verdict'] ?? null,
                'coverage_state' => $indexStatus['coverageState'] ?? null,
                'robots_txt_state' => $indexStatus['robotsTxtState'] ?? null,
                'indexing_state' => $indexStatus['indexingState'] ?? null,
                'last_crawl_time' => $indexStatus['lastCrawlTime'] ?? null,
                'page_fetch_state' => $indexStatus['pageFetchState'] ?? null,
                'google_canonical' => $indexStatus['googleCanonical'] ?? null,
                'user_canonical' => $indexStatus['userCanonical'] ?? null,
                'crawled_as' => $indexStatus['crawledAs'] ?? null,
                'referring_urls' => $indexStatus['referringUrls'] ?? [],
                'sitemap' => $indexStatus['sitemap'] ?? [],
                'inspection_result_link' => data_get($inspectionResult, 'inspectionResult.inspectionResultLink')
                    ?? data_get($inspectionResult, 'inspectionResultLink'),
                'live_url_test_claimed' => false,
                'site_wide_indexed_total_inferred' => false,
                'provider_index_status_semantics' => 'urlInspection.index.inspect_point_in_time',
                'provider_completeness' => 'CONTROLLED_SAMPLE_ONLY',
            ],
        ];
    }
}
