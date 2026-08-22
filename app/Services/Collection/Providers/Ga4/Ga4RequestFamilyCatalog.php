<?php

namespace App\Services\Collection\Providers\Ga4;

use InvalidArgumentException;

/**
 * Canonical GA4 collection families used by both bound Digital Assets and resource-first central ingestion.
 */
final class Ga4RequestFamilyCatalog
{
    public const string FAMILY_PROPERTY_METADATA = 'GA4_RF_PROPERTY_METADATA';
    public const string FAMILY_PROPERTY_DAILY = 'GA4_RF_PROPERTY_DAILY';
    public const string FAMILY_CHANNEL_DAILY = 'GA4_RF_CHANNEL_DAILY';
    public const string FAMILY_SOURCE_MEDIUM_DAILY = 'GA4_RF_SOURCE_MEDIUM_DAILY';
    public const string FAMILY_CAMPAIGN_DAILY = 'GA4_RF_CAMPAIGN_DAILY';
    public const string FAMILY_FIRST_USER_DAILY = 'GA4_RF_FIRST_USER_DAILY';
    public const string FAMILY_LANDING_PAGE_DAILY = 'GA4_RF_LANDING_PAGE_DAILY';
    public const string FAMILY_PAGE_CONTENT_DAILY = 'GA4_RF_PAGE_CONTENT_DAILY';
    public const string FAMILY_EVENT_DAILY = 'GA4_RF_EVENT_DAILY';
    public const string FAMILY_KEY_EVENT_DAILY = 'GA4_RF_KEY_EVENT_DAILY';
    public const string FAMILY_EVENT_BREAKDOWNS = 'GA4_RF_EVENT_BREAKDOWNS';
    public const string FAMILY_DEVICE_DAILY = 'GA4_RF_DEVICE_DAILY';
    public const string FAMILY_TECHNOLOGY_DAILY = 'GA4_RF_TECHNOLOGY_DAILY';
    public const string FAMILY_COUNTRY_DAILY = 'GA4_RF_COUNTRY_DAILY';
    public const string FAMILY_REGION_DAILY = 'GA4_RF_REGION_DAILY';
    public const string FAMILY_CITY_DAILY = 'GA4_RF_CITY_DAILY';
    public const string FAMILY_HOUR_DAILY = 'GA4_RF_HOUR_DAILY';
    public const string FAMILY_ECOMMERCE_ITEM_DAILY = 'GA4_RF_ECOMMERCE_ITEM_DAILY';
    public const string FAMILY_GENERIC_REPORT = 'GA4_RF_GENERIC_REPORT';

    /** @return list<string> */
    public static function supportedFamilies(): array
    {
        return [
            ...self::centralFamilies(),
            self::FAMILY_EVENT_BREAKDOWNS,
            self::FAMILY_GENERIC_REPORT,
        ];
    }

    /**
     * Families persisted by the user-selectable central 486-day collector.
     * Generic range users is intentionally excluded because it has no durable typed fact table.
     * Event breakdowns remain supported for legacy/bound flows but are not needed for the core central contract.
     *
     * @return list<string>
     */
    public static function centralFamilies(): array
    {
        return [
            self::FAMILY_PROPERTY_METADATA,
            self::FAMILY_PROPERTY_DAILY,
            self::FAMILY_CHANNEL_DAILY,
            self::FAMILY_SOURCE_MEDIUM_DAILY,
            self::FAMILY_CAMPAIGN_DAILY,
            self::FAMILY_FIRST_USER_DAILY,
            self::FAMILY_LANDING_PAGE_DAILY,
            self::FAMILY_PAGE_CONTENT_DAILY,
            self::FAMILY_EVENT_DAILY,
            self::FAMILY_KEY_EVENT_DAILY,
            self::FAMILY_DEVICE_DAILY,
            self::FAMILY_TECHNOLOGY_DAILY,
            self::FAMILY_COUNTRY_DAILY,
            self::FAMILY_REGION_DAILY,
            self::FAMILY_CITY_DAILY,
            self::FAMILY_HOUR_DAILY,
            self::FAMILY_ECOMMERCE_ITEM_DAILY,
        ];
    }

    /** @return list<string> */
    public static function propertyDailyRequiredMetrics(): array
    {
        return [
            'activeUsers', 'totalUsers', 'newUsers', 'sessions', 'engagedSessions',
            'engagementRate', 'bounceRate', 'averageSessionDuration', 'sessionsPerUser',
            'screenPageViews', 'screenPageViewsPerSession', 'screenPageViewsPerUser',
            'eventCount', 'eventsPerSession', 'userEngagementDuration',
        ];
    }

    /** @return list<string> */
    public static function propertyDailyOptionalMetrics(): array
    {
        return [
            'keyEvents', 'conversions', 'sessionKeyEventRate', 'userKeyEventRate', 'scrolledUsers',
            'transactions', 'ecommercePurchases', 'totalPurchasers', 'purchaseRevenue', 'totalRevenue',
        ];
    }

    /** @return list<string> */
    public static function propertyDailyAllMetrics(): array
    {
        return array_values(array_unique([
            ...self::propertyDailyRequiredMetrics(),
            ...self::propertyDailyOptionalMetrics(),
        ]));
    }

    /** @return list<string> */
    private static function sessionRequiredMetrics(): array
    {
        return ['sessions', 'engagedSessions', 'activeUsers'];
    }

    /** @return list<string> */
    private static function sessionOptionalMetrics(): array
    {
        return [
            'totalUsers', 'newUsers', 'engagementRate', 'bounceRate', 'averageSessionDuration',
            'screenPageViews', 'eventCount', 'keyEvents', 'sessionKeyEventRate', 'totalRevenue',
        ];
    }

    /**
     * @return array{
     *   kind: 'metadata'|'run_report'|'range_users'|'event_breakdowns',
     *   dataset_id: string|null,
     *   dimensions: list<string>,
     *   metrics: list<string>,
     *   optional_metrics: list<string>,
     *   semantic_scope: string,
     *   requires_date_range: bool,
     *   high_cardinality: bool,
     *   keep_empty_rows: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        $keepEmpty = (bool) config('moxdop-ga4-collector.keep_empty_rows', false);
        $report = static fn (
            string $dataset,
            array $dimensions,
            array $metrics,
            array $optional,
            string $scope,
            bool $high = false,
        ): array => [
            'kind' => 'run_report',
            'dataset_id' => $dataset,
            'dimensions' => $dimensions,
            'metrics' => $metrics,
            'optional_metrics' => $optional,
            'semantic_scope' => $scope,
            'requires_date_range' => true,
            'high_cardinality' => $high,
            'keep_empty_rows' => $keepEmpty,
        ];

        return match ($familyId) {
            self::FAMILY_PROPERTY_METADATA => [
                'kind' => 'metadata',
                'dataset_id' => 'ga4_property_metadata',
                'dimensions' => [],
                'metrics' => [],
                'optional_metrics' => [],
                'semantic_scope' => 'property_config',
                'requires_date_range' => false,
                'high_cardinality' => false,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_PROPERTY_DAILY => $report(
                'ga4_property_daily', ['date'], self::propertyDailyRequiredMetrics(), self::propertyDailyOptionalMetrics(), 'property'
            ),
            self::FAMILY_CHANNEL_DAILY => $report(
                'ga4_acquisition_channel_daily', ['date', 'sessionDefaultChannelGroup'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'session_acquisition'
            ),
            self::FAMILY_SOURCE_MEDIUM_DAILY => $report(
                'ga4_source_medium_daily', ['date', 'sessionSourceMedium'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'session_acquisition', true
            ),
            self::FAMILY_CAMPAIGN_DAILY => $report(
                'ga4_campaign_daily', ['date', 'sessionCampaignId', 'sessionCampaignName', 'sessionSource', 'sessionMedium'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'session_campaign', true
            ),
            self::FAMILY_FIRST_USER_DAILY => $report(
                'ga4_first_user_acquisition_daily', ['date', 'firstUserDefaultChannelGroup', 'firstUserSourceMedium'], ['newUsers', 'activeUsers'], ['totalUsers', 'keyEvents', 'userKeyEventRate', 'totalRevenue'], 'first_user_acquisition', true
            ),
            self::FAMILY_LANDING_PAGE_DAILY => $report(
                'ga4_landing_page_daily', ['date', 'landingPagePlusQueryString'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'session_entry', true
            ),
            self::FAMILY_PAGE_CONTENT_DAILY => $report(
                'ga4_page_content_daily', ['date', 'pagePathPlusQueryString', 'pageTitle', 'hostName'], ['screenPageViews', 'activeUsers', 'eventCount'], ['totalUsers', 'userEngagementDuration', 'keyEvents', 'scrolledUsers'], 'content', true
            ),
            self::FAMILY_EVENT_DAILY => $report(
                'ga4_event_daily', ['date', 'eventName'], ['eventCount', 'activeUsers'], ['totalUsers', 'eventCountPerUser', 'eventValue', 'keyEvents'], 'event', true
            ),
            self::FAMILY_KEY_EVENT_DAILY => $report(
                'ga4_key_event_daily', ['date', 'eventName'], ['keyEvents'], ['activeUsers', 'totalUsers', 'sessionKeyEventRate', 'userKeyEventRate'], 'key_event', true
            ),
            self::FAMILY_EVENT_BREAKDOWNS => [
                'kind' => 'event_breakdowns',
                'dataset_id' => null,
                'dimensions' => [],
                'metrics' => ['eventCount'],
                'optional_metrics' => [],
                'semantic_scope' => 'event_x_session_dim',
                'requires_date_range' => true,
                'high_cardinality' => true,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_DEVICE_DAILY => $report(
                'ga4_device_daily', ['date', 'deviceCategory'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'device'
            ),
            self::FAMILY_TECHNOLOGY_DAILY => $report(
                'ga4_technology_daily', ['date', 'deviceCategory', 'browser', 'operatingSystem'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'technology', true
            ),
            self::FAMILY_COUNTRY_DAILY => $report(
                'ga4_geo_country_daily', ['date', 'country'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'geography_country'
            ),
            self::FAMILY_REGION_DAILY => $report(
                'ga4_geo_region_daily', ['date', 'country', 'region'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'geography_region', true
            ),
            self::FAMILY_CITY_DAILY => $report(
                'ga4_geo_city_daily', ['date', 'country', 'region', 'city'], self::sessionRequiredMetrics(), self::sessionOptionalMetrics(), 'geography_city', true
            ),
            self::FAMILY_HOUR_DAILY => $report(
                'ga4_hour_daily', ['date', 'dayOfWeek', 'hour'], ['sessions', 'activeUsers'], ['engagedSessions', 'keyEvents', 'sessionKeyEventRate'], 'time_of_day', true
            ),
            self::FAMILY_ECOMMERCE_ITEM_DAILY => $report(
                'ga4_ecommerce_item_daily', ['date', 'itemId', 'itemName', 'itemCategory'], ['itemsViewed', 'itemsAddedToCart', 'itemsPurchased'], ['itemsCheckedOut', 'itemRevenue', 'cartToViewRate', 'purchaseToViewRate'], 'ecommerce_item', true
            ),
            self::FAMILY_GENERIC_REPORT => [
                'kind' => 'range_users',
                'dataset_id' => null,
                'dimensions' => [],
                'metrics' => ['totalUsers', 'activeUsers'],
                'optional_metrics' => [],
                'semantic_scope' => 'property_range_users',
                'requires_date_range' => true,
                'high_cardinality' => false,
                'keep_empty_rows' => $keepEmpty,
            ],
            default => throw new InvalidArgumentException("Unknown GA4 request family [{$familyId}]"),
        };
    }

    public static function primaryDatasetForFamily(string $familyId): string
    {
        if ($familyId === self::FAMILY_EVENT_BREAKDOWNS) {
            return 'ga4_event_channel_daily';
        }
        if ($familyId === self::FAMILY_GENERIC_REPORT) {
            return 'ga4_property_daily';
        }

        $dataset = self::definition($familyId)['dataset_id'] ?? null;
        if (! is_string($dataset) || $dataset === '') {
            throw new InvalidArgumentException("GA4 family [{$familyId}] has no durable dataset.");
        }

        return $dataset;
    }

    /** @return list<array{dataset_id: string, dimensions: list<string>}> */
    public static function eventBreakdownSpecs(): array
    {
        return [
            ['dataset_id' => 'ga4_event_channel_daily', 'dimensions' => ['date', 'sessionDefaultChannelGroup', 'eventName']],
            ['dataset_id' => 'ga4_event_campaign_daily', 'dimensions' => ['date', 'sessionCampaignName', 'eventName']],
            ['dataset_id' => 'ga4_event_landing_daily', 'dimensions' => ['date', 'landingPage', 'eventName']],
        ];
    }
}
