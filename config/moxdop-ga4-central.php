<?php

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'metric'): array => [
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
];

$metricColumns = static function (array $bigints = [], array $decimals = []) use ($column): array {
    return [
        ...array_map(fn (string $name): array => $column($name, 'bigint'), $bigints),
        ...array_map(fn (string $name): array => $column($name, 'decimal'), $decimals),
    ];
};

$commonSessionMetrics = $metricColumns(
    ['sessions', 'engagedSessions', 'activeUsers', 'totalUsers', 'newUsers', 'screenPageViews', 'eventCount'],
    ['engagementRate', 'bounceRate', 'averageSessionDuration', 'keyEvents', 'sessionKeyEventRate', 'totalRevenue'],
);

$physical = static function (
    string $table,
    array $grain,
    array $dimensions,
    array $metrics,
) use ($column): array {
    return [
        'table' => $table,
        'provider_or_source' => 'GA4',
        'storage_class' => 'NORMALIZED_FACT',
        'write_mode' => 'UPSERT_DAILY_FACT',
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => [
            $column('digital_asset_id', 'bigint', true, 'scope'),
            $column('external_resource_id', 'bigint', false, 'scope'),
            $column('property_id', 'text', false, 'identity'),
            $column('reporting_date', 'date', false, 'identity'),
            ...$dimensions,
            ...$metrics,
            $column('contract_version', 'integer', false, 'provenance'),
            $column('last_collection_run_id', 'bigint', true, 'provenance'),
            $column('last_dataset_run_id', 'bigint', true, 'provenance'),
            $column('first_collected_at', 'timestamptz', false, 'provenance'),
            $column('last_collected_at', 'timestamptz', false, 'provenance'),
            $column('source_timezone', 'text', true, 'provenance'),
            $column('record_fingerprint', 'char(64)', false, 'provenance'),
            $column('metadata', 'json', true, 'extension'),
        ],
    ];
};

return [
    // Resource identity is the central provider truth. Digital Asset is an optional later binding.
    'natural_key_overrides' => [
        'ga4_property_metadata' => ['external_resource_id', 'property_id'],
        'ga4_property_daily' => ['external_resource_id', 'property_id', 'reporting_date'],
        'ga4_acquisition_channel_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionDefaultChannelGroup'],
        'ga4_source_medium_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionSource', 'sessionMedium'],
        'ga4_campaign_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'sessionCampaignId', 'sessionCampaignName', 'sessionSource', 'sessionMedium'],
        'ga4_landing_page_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'landingPagePlusQueryString'],
        'ga4_event_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName'],
        'ga4_event_channel_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'sessionDefaultChannelGroup'],
        'ga4_event_campaign_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'sessionCampaignName'],
        'ga4_event_landing_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'eventName', 'landingPage'],
        'ga4_device_daily' => ['external_resource_id', 'property_id', 'reporting_date', 'deviceCategory'],
    ],

    'columns_add' => [
        'ga4_property_daily' => [
            ...$metricColumns(
                ['activeUsers', 'totalUsers', 'newUsers', 'sessions', 'engagedSessions', 'screenPageViews', 'eventCount', 'scrolledUsers', 'transactions', 'ecommercePurchases', 'totalPurchasers'],
                ['engagementRate', 'bounceRate', 'averageSessionDuration', 'sessionsPerUser', 'screenPageViewsPerSession', 'screenPageViewsPerUser', 'eventsPerSession', 'userEngagementDuration', 'keyEvents', 'conversions', 'sessionKeyEventRate', 'userKeyEventRate', 'purchaseRevenue', 'totalRevenue'],
            ),
        ],
        'ga4_acquisition_channel_daily' => $commonSessionMetrics,
        'ga4_source_medium_daily' => $commonSessionMetrics,
        'ga4_campaign_daily' => [
            $column('sessionCampaignId', 'text', false, 'dimension'),
            $column('sessionSource', 'text', false, 'dimension'),
            $column('sessionMedium', 'text', false, 'dimension'),
            ...$commonSessionMetrics,
        ],
        'ga4_landing_page_daily' => [
            $column('landingPagePlusQueryString', 'text', false, 'dimension'),
            ...$commonSessionMetrics,
        ],
        'ga4_event_daily' => [
            ...$metricColumns(['eventCount', 'activeUsers', 'totalUsers'], ['eventCountPerUser', 'eventValue', 'keyEvents']),
        ],
        'ga4_device_daily' => $commonSessionMetrics,
    ],

    'physical_additions' => [
        'ga4_first_user_acquisition_daily' => $physical(
            'ga4_first_user_acquisition_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'firstUserDefaultChannelGroup', 'firstUserSource', 'firstUserMedium'],
            [
                $column('firstUserDefaultChannelGroup', 'text', false, 'dimension'),
                $column('firstUserSource', 'text', false, 'dimension'),
                $column('firstUserMedium', 'text', false, 'dimension'),
            ],
            $metricColumns(['newUsers', 'activeUsers', 'totalUsers'], ['keyEvents', 'userKeyEventRate', 'totalRevenue']),
        ),
        'ga4_page_content_daily' => $physical(
            'ga4_page_content_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'pagePathPlusQueryString', 'pageTitle', 'hostName'],
            [
                $column('pagePathPlusQueryString', 'text', false, 'dimension'),
                $column('pageTitle', 'text', false, 'dimension'),
                $column('hostName', 'text', false, 'dimension'),
            ],
            $metricColumns(['screenPageViews', 'activeUsers', 'totalUsers', 'eventCount', 'scrolledUsers'], ['userEngagementDuration', 'keyEvents']),
        ),
        'ga4_key_event_daily' => $physical(
            'ga4_key_event_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'eventName'],
            [$column('eventName', 'text', false, 'dimension')],
            $metricColumns(['activeUsers', 'totalUsers'], ['keyEvents', 'sessionKeyEventRate', 'userKeyEventRate']),
        ),
        'ga4_technology_daily' => $physical(
            'ga4_technology_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'deviceCategory', 'browser', 'operatingSystem'],
            [
                $column('deviceCategory', 'text', false, 'dimension'),
                $column('browser', 'text', false, 'dimension'),
                $column('operatingSystem', 'text', false, 'dimension'),
            ],
            $commonSessionMetrics,
        ),
        'ga4_geo_country_daily' => $physical(
            'ga4_geo_country_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'country'],
            [$column('country', 'text', false, 'dimension')],
            $commonSessionMetrics,
        ),
        'ga4_geo_region_daily' => $physical(
            'ga4_geo_region_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'country', 'region'],
            [$column('country', 'text', false, 'dimension'), $column('region', 'text', false, 'dimension')],
            $commonSessionMetrics,
        ),
        'ga4_geo_city_daily' => $physical(
            'ga4_geo_city_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'country', 'region', 'city'],
            [$column('country', 'text', false, 'dimension'), $column('region', 'text', false, 'dimension'), $column('city', 'text', false, 'dimension')],
            $commonSessionMetrics,
        ),
        'ga4_hour_daily' => $physical(
            'ga4_hour_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'dayOfWeek', 'hour'],
            [$column('dayOfWeek', 'text', false, 'dimension'), $column('hour', 'text', false, 'dimension')],
            $metricColumns(['sessions', 'activeUsers', 'engagedSessions'], ['keyEvents', 'sessionKeyEventRate']),
        ),
        'ga4_ecommerce_item_daily' => $physical(
            'ga4_ecommerce_item_daily',
            ['external_resource_id', 'property_id', 'reporting_date', 'itemId', 'itemName', 'itemCategory'],
            [
                $column('itemId', 'text', false, 'dimension'),
                $column('itemName', 'text', false, 'dimension'),
                $column('itemCategory', 'text', false, 'dimension'),
            ],
            $metricColumns(['itemsViewed', 'itemsAddedToCart', 'itemsCheckedOut', 'itemsPurchased'], ['itemRevenue', 'cartToViewRate', 'purchaseToViewRate']),
        ),
    ],
];
