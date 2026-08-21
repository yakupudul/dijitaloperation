<?php

namespace App\Services\Collection\Providers\Ga4;

use InvalidArgumentException;

/**
 * Contract-driven GA4 request-family definitions (source GA4 Data Contract V1 + Registry IDs).
 */
final class Ga4RequestFamilyCatalog
{
    public const string FAMILY_PROPERTY_METADATA = 'GA4_RF_PROPERTY_METADATA';

    public const string FAMILY_PROPERTY_DAILY = 'GA4_RF_PROPERTY_DAILY';

    public const string FAMILY_CHANNEL_DAILY = 'GA4_RF_CHANNEL_DAILY';

    public const string FAMILY_SOURCE_MEDIUM_DAILY = 'GA4_RF_SOURCE_MEDIUM_DAILY';

    public const string FAMILY_CAMPAIGN_DAILY = 'GA4_RF_CAMPAIGN_DAILY';

    public const string FAMILY_LANDING_PAGE_DAILY = 'GA4_RF_LANDING_PAGE_DAILY';

    public const string FAMILY_EVENT_DAILY = 'GA4_RF_EVENT_DAILY';

    public const string FAMILY_EVENT_BREAKDOWNS = 'GA4_RF_EVENT_BREAKDOWNS';

    public const string FAMILY_DEVICE_DAILY = 'GA4_RF_DEVICE_DAILY';

    public const string FAMILY_GENERIC_REPORT = 'GA4_RF_GENERIC_REPORT';

    /**
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [
            self::FAMILY_PROPERTY_METADATA,
            self::FAMILY_PROPERTY_DAILY,
            self::FAMILY_CHANNEL_DAILY,
            self::FAMILY_SOURCE_MEDIUM_DAILY,
            self::FAMILY_CAMPAIGN_DAILY,
            self::FAMILY_LANDING_PAGE_DAILY,
            self::FAMILY_EVENT_DAILY,
            self::FAMILY_EVENT_BREAKDOWNS,
            self::FAMILY_DEVICE_DAILY,
            self::FAMILY_GENERIC_REPORT,
        ];
    }

    /**
     * Required property-daily metrics that must be requested when the family runs.
     *
     * @return list<string>
     */
    public static function propertyDailyRequiredMetrics(): array
    {
        return ['sessions', 'engagedSessions', 'screenPageViews', 'userEngagementDuration', 'totalUsers', 'activeUsers'];
    }

    /**
     * Optional property-daily metrics collected when the property metadata/API supports them.
     *
     * @return list<string>
     */
    public static function propertyDailyOptionalMetrics(): array
    {
        return ['newUsers', 'conversions', 'keyEvents', 'totalRevenue'];
    }

    /**
     * @return list<string>
     */
    public static function propertyDailyAllMetrics(): array
    {
        return array_values(array_unique([
            ...self::propertyDailyRequiredMetrics(),
            ...self::propertyDailyOptionalMetrics(),
        ]));
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
            self::FAMILY_PROPERTY_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_property_daily',
                'dimensions' => ['date'],
                'metrics' => self::propertyDailyRequiredMetrics(),
                'optional_metrics' => self::propertyDailyOptionalMetrics(),
                'semantic_scope' => 'property',
                'requires_date_range' => true,
                'high_cardinality' => false,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_CHANNEL_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_acquisition_channel_daily',
                'dimensions' => ['date', 'sessionDefaultChannelGroup'],
                'metrics' => ['sessions', 'engagedSessions'],
                'optional_metrics' => [],
                'semantic_scope' => 'session_acquisition',
                'requires_date_range' => true,
                'high_cardinality' => false,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_SOURCE_MEDIUM_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_source_medium_daily',
                'dimensions' => ['date', 'sessionSourceMedium'],
                'metrics' => ['sessions', 'engagedSessions'],
                'optional_metrics' => [],
                'semantic_scope' => 'session_acquisition',
                'requires_date_range' => true,
                'high_cardinality' => true,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_CAMPAIGN_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_campaign_daily',
                'dimensions' => ['date', 'sessionCampaignName'],
                'metrics' => ['sessions'],
                'optional_metrics' => [],
                'semantic_scope' => 'session_acquisition',
                'requires_date_range' => true,
                'high_cardinality' => true,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_LANDING_PAGE_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_landing_page_daily',
                'dimensions' => ['date', 'landingPage'],
                'metrics' => ['sessions', 'engagedSessions'],
                'optional_metrics' => [],
                'semantic_scope' => 'session_entry',
                'requires_date_range' => true,
                'high_cardinality' => true,
                'keep_empty_rows' => $keepEmpty,
            ],
            self::FAMILY_EVENT_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_event_daily',
                'dimensions' => ['date', 'eventName'],
                'metrics' => ['eventCount'],
                'optional_metrics' => [],
                'semantic_scope' => 'event',
                'requires_date_range' => true,
                'high_cardinality' => true,
                'keep_empty_rows' => $keepEmpty,
            ],
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
            self::FAMILY_DEVICE_DAILY => [
                'kind' => 'run_report',
                'dataset_id' => 'ga4_device_daily',
                'dimensions' => ['date', 'deviceCategory'],
                'metrics' => ['sessions', 'engagedSessions'],
                'optional_metrics' => [],
                'semantic_scope' => 'device',
                'requires_date_range' => true,
                'high_cardinality' => false,
                'keep_empty_rows' => $keepEmpty,
            ],
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

    /**
     * Physical event×dimension breakdowns with Storage Contract tables.
     * ga4_event_source_medium_daily is STORAGE_CONTRACT_GAP — excluded.
     *
     * @return list<array{dataset_id: string, dimensions: list<string>}>
     */
    public static function eventBreakdownSpecs(): array
    {
        return [
            [
                'dataset_id' => 'ga4_event_channel_daily',
                'dimensions' => ['date', 'sessionDefaultChannelGroup', 'eventName'],
            ],
            [
                'dataset_id' => 'ga4_event_campaign_daily',
                'dimensions' => ['date', 'sessionCampaignName', 'eventName'],
            ],
            [
                'dataset_id' => 'ga4_event_landing_daily',
                'dimensions' => ['date', 'landingPage', 'eventName'],
            ],
        ];
    }
}
