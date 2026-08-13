<?php

namespace App\Services\Collection\Providers\SearchConsole;

use InvalidArgumentException;

/**
 * Contract-driven GSC request-family → Search Analytics / sitemap / inspection mapping.
 * Dimensions and aggregation come from SEARCH_CONSOLE_DATA_CONTRACT_V1 + Registry V1.
 */
final class SearchConsoleRequestFamilyCatalog
{
    public const string FAMILY_PROPERTY_DAILY = 'GSC_RF_PROPERTY_DAILY';

    public const string FAMILY_QUERY_DAILY = 'GSC_RF_QUERY_DAILY';

    public const string FAMILY_PAGE_DAILY = 'GSC_RF_PAGE_DAILY';

    public const string FAMILY_QUERY_PAGE_DAILY = 'GSC_RF_QUERY_PAGE_DAILY';

    public const string FAMILY_DEVICE_DAILY = 'GSC_RF_DEVICE_DAILY';

    public const string FAMILY_COUNTRY_DAILY = 'GSC_RF_COUNTRY_DAILY';

    public const string FAMILY_SITEMAPS = 'GSC_RF_SITEMAPS';

    public const string FAMILY_URL_INSPECTION = 'GSC_RF_URL_INSPECTION';

    public const string FAMILY_SEARCH_ANALYTICS = 'GSC_RF_SEARCH_ANALYTICS';

    /**
     * Production families this collector implements.
     *
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [
            self::FAMILY_PROPERTY_DAILY,
            self::FAMILY_QUERY_DAILY,
            self::FAMILY_PAGE_DAILY,
            self::FAMILY_QUERY_PAGE_DAILY,
            self::FAMILY_DEVICE_DAILY,
            self::FAMILY_COUNTRY_DAILY,
            self::FAMILY_SITEMAPS,
            self::FAMILY_URL_INSPECTION,
            self::FAMILY_SEARCH_ANALYTICS,
        ];
    }

    /**
     * @return array{
     *   kind: 'search_analytics'|'sitemaps'|'url_inspection'|'site_metadata',
     *   dataset_id: string|null,
     *   dimensions: list<string>,
     *   aggregation_type: string|null,
     *   search_type: string,
     *   data_state: string,
     *   requires_date_range: bool,
     *   high_cardinality: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        $searchType = (string) config('moxdop-gsc-collector.search_type', 'web');
        $dataState = (string) config('moxdop-gsc-collector.data_state', 'final');

        return match ($familyId) {
            self::FAMILY_PROPERTY_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_property_daily',
                'dimensions' => ['date'],
                'aggregation_type' => 'byProperty',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            self::FAMILY_QUERY_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_query_daily',
                'dimensions' => ['date', 'query'],
                'aggregation_type' => 'auto',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_PAGE_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_page_daily',
                'dimensions' => ['date', 'page'],
                'aggregation_type' => 'byPage',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_QUERY_PAGE_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_query_page_daily',
                'dimensions' => ['date', 'query', 'page'],
                'aggregation_type' => 'auto',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_DEVICE_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_device_daily',
                'dimensions' => ['date', 'device'],
                'aggregation_type' => 'byProperty',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            self::FAMILY_COUNTRY_DAILY => [
                'kind' => 'search_analytics',
                'dataset_id' => 'gsc_country_daily',
                'dimensions' => ['date', 'country'],
                'aggregation_type' => 'byProperty',
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            self::FAMILY_SITEMAPS => [
                'kind' => 'sitemaps',
                'dataset_id' => 'gsc_sitemap_snapshot',
                'dimensions' => [],
                'aggregation_type' => null,
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => false,
                'high_cardinality' => false,
            ],
            self::FAMILY_URL_INSPECTION => [
                'kind' => 'url_inspection',
                'dataset_id' => 'gsc_url_inspection_snapshot',
                'dimensions' => [],
                'aggregation_type' => null,
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => false,
                'high_cardinality' => false,
            ],
            self::FAMILY_SEARCH_ANALYTICS => [
                'kind' => 'site_metadata',
                'dataset_id' => null,
                'dimensions' => [],
                'aggregation_type' => null,
                'search_type' => $searchType,
                'data_state' => $dataState,
                'requires_date_range' => false,
                'high_cardinality' => false,
            ],
            default => throw new InvalidArgumentException("Unknown GSC request family [{$familyId}]"),
        };
    }
}
