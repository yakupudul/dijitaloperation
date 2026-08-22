<?php

namespace App\Services\Collection\Providers\SearchConsole;

use InvalidArgumentException;

/**
 * Contract-driven GSC request-family → Search Analytics / sitemap / inspection mapping.
 */
final class SearchConsoleRequestFamilyCatalog
{
    public const string FAMILY_PROPERTY_DAILY = 'GSC_RF_PROPERTY_DAILY';
    public const string FAMILY_QUERY_DAILY = 'GSC_RF_QUERY_DAILY';
    public const string FAMILY_PAGE_DAILY = 'GSC_RF_PAGE_DAILY';
    public const string FAMILY_QUERY_PAGE_DAILY = 'GSC_RF_QUERY_PAGE_DAILY';
    public const string FAMILY_DEVICE_DAILY = 'GSC_RF_DEVICE_DAILY';
    public const string FAMILY_COUNTRY_DAILY = 'GSC_RF_COUNTRY_DAILY';
    public const string FAMILY_PAGE_DEVICE_DAILY = 'GSC_RF_PAGE_DEVICE_DAILY';
    public const string FAMILY_PAGE_COUNTRY_DAILY = 'GSC_RF_PAGE_COUNTRY_DAILY';
    public const string FAMILY_QUERY_DEVICE_DAILY = 'GSC_RF_QUERY_DEVICE_DAILY';
    public const string FAMILY_QUERY_COUNTRY_DAILY = 'GSC_RF_QUERY_COUNTRY_DAILY';
    public const string FAMILY_SEARCH_APPEARANCE_DAILY = 'GSC_RF_SEARCH_APPEARANCE_DAILY';
    public const string FAMILY_SEARCH_APPEARANCE_PAGE_DAILY = 'GSC_RF_SEARCH_APPEARANCE_PAGE_DAILY';
    public const string FAMILY_SITEMAPS = 'GSC_RF_SITEMAPS';
    public const string FAMILY_URL_INSPECTION = 'GSC_RF_URL_INSPECTION';
    public const string FAMILY_SEARCH_ANALYTICS = 'GSC_RF_SEARCH_ANALYTICS';

    /** @return list<string> */
    public static function supportedFamilies(): array
    {
        return [
            ...self::centralPerformanceFamilies(),
            self::FAMILY_SITEMAPS,
            self::FAMILY_URL_INSPECTION,
            self::FAMILY_SEARCH_ANALYTICS,
        ];
    }

    /**
     * Historical Search Analytics families collected into the central Data Pool.
     * URL Inspection is intentionally not part of bulk historical import because it is
     * a quota-limited current-state snapshot rather than a historical performance fact.
     *
     * @return list<string>
     */
    public static function centralPerformanceFamilies(): array
    {
        return [
            self::FAMILY_PROPERTY_DAILY,
            self::FAMILY_QUERY_DAILY,
            self::FAMILY_PAGE_DAILY,
            self::FAMILY_QUERY_PAGE_DAILY,
            self::FAMILY_DEVICE_DAILY,
            self::FAMILY_COUNTRY_DAILY,
            self::FAMILY_PAGE_DEVICE_DAILY,
            self::FAMILY_PAGE_COUNTRY_DAILY,
            self::FAMILY_QUERY_DEVICE_DAILY,
            self::FAMILY_QUERY_COUNTRY_DAILY,
            self::FAMILY_SEARCH_APPEARANCE_DAILY,
            self::FAMILY_SEARCH_APPEARANCE_PAGE_DAILY,
        ];
    }

    /** @return list<string> */
    public static function centralFamilies(): array
    {
        return [...self::centralPerformanceFamilies(), self::FAMILY_SITEMAPS, self::FAMILY_SEARCH_ANALYTICS];
    }

    /**
     * Returns only search types compatible with a family. Discover and Google News
     * do not expose a useful query dimension. Search Appearance is collected from
     * Web with Google's required two-step discover-then-filter flow.
     *
     * @param list<string> $activeSearchTypes
     * @return list<string>
     */
    public static function compatibleSearchTypes(string $familyId, array $activeSearchTypes): array
    {
        if (! in_array($familyId, self::centralPerformanceFamilies(), true)) {
            return ['web'];
        }

        if (in_array($familyId, [
            self::FAMILY_SEARCH_APPEARANCE_DAILY,
            self::FAMILY_SEARCH_APPEARANCE_PAGE_DAILY,
        ], true)) {
            return in_array('web', $activeSearchTypes, true) ? ['web'] : [];
        }

        if (in_array($familyId, [
            self::FAMILY_QUERY_DAILY,
            self::FAMILY_QUERY_PAGE_DAILY,
            self::FAMILY_QUERY_DEVICE_DAILY,
            self::FAMILY_QUERY_COUNTRY_DAILY,
        ], true)) {
            return array_values(array_filter(
                $activeSearchTypes,
                static fn (string $type): bool => ! in_array($type, ['discover', 'googleNews'], true),
            ));
        }

        return array_values($activeSearchTypes);
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
     *   high_cardinality: bool,
     *   search_appearance_two_step?: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        $searchType = (string) config('moxdop-gsc-collector.search_type', 'web');
        $dataState = (string) config('moxdop-gsc-collector.data_state', 'final');

        $analytics = static fn (string $datasetId, array $dimensions, string $aggregationType, bool $highCardinality = false): array => [
            'kind' => 'search_analytics',
            'dataset_id' => $datasetId,
            'dimensions' => $dimensions,
            'aggregation_type' => $aggregationType,
            'search_type' => $searchType,
            'data_state' => $dataState,
            'requires_date_range' => true,
            'high_cardinality' => $highCardinality,
        ];

        return match ($familyId) {
            self::FAMILY_PROPERTY_DAILY => $analytics('gsc_property_daily', ['date'], 'byProperty'),
            self::FAMILY_QUERY_DAILY => $analytics('gsc_query_daily', ['date', 'query'], 'auto', true),
            self::FAMILY_PAGE_DAILY => $analytics('gsc_page_daily', ['date', 'page'], 'byPage', true),
            self::FAMILY_QUERY_PAGE_DAILY => $analytics('gsc_query_page_daily', ['date', 'query', 'page'], 'auto', true),
            self::FAMILY_DEVICE_DAILY => $analytics('gsc_device_daily', ['date', 'device'], 'byProperty'),
            self::FAMILY_COUNTRY_DAILY => $analytics('gsc_country_daily', ['date', 'country'], 'byProperty'),
            self::FAMILY_PAGE_DEVICE_DAILY => $analytics('gsc_page_device_daily', ['date', 'page', 'device'], 'byPage', true),
            self::FAMILY_PAGE_COUNTRY_DAILY => $analytics('gsc_page_country_daily', ['date', 'page', 'country'], 'byPage', true),
            self::FAMILY_QUERY_DEVICE_DAILY => $analytics('gsc_query_device_daily', ['date', 'query', 'device'], 'auto', true),
            self::FAMILY_QUERY_COUNTRY_DAILY => $analytics('gsc_query_country_daily', ['date', 'query', 'country'], 'auto', true),
            self::FAMILY_SEARCH_APPEARANCE_DAILY => [
                ...$analytics('gsc_search_appearance_daily', ['date'], 'byProperty'),
                'search_appearance_two_step' => true,
            ],
            self::FAMILY_SEARCH_APPEARANCE_PAGE_DAILY => [
                ...$analytics('gsc_search_appearance_page_daily', ['date', 'page'], 'byPage', true),
                'search_appearance_two_step' => true,
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
