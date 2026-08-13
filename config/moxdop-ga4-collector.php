<?php

/**
 * GA4 production collector — contract/config-driven slice & page policy.
 * Official Data API runReport limit max verified 2026-08-13: 250,000 rows/request.
 */
return [
    'collector_version' => 'ga4-production-collector/1',

    /**
     * @see https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport
     */
    'max_row_limit' => 250000,

    'page_size' => (int) env('MOXDOP_GA4_PAGE_SIZE', 10000),

    'max_pages_per_tick' => (int) env('MOXDOP_GA4_MAX_PAGES_PER_TICK', 20),

    /**
     * Preferred inclusive date-slice width (days) per request family.
     *
     * @var array<string, int>
     */
    'date_slice_days' => [
        'GA4_RF_PROPERTY_DAILY' => 28,
        'GA4_RF_CHANNEL_DAILY' => 7,
        'GA4_RF_SOURCE_MEDIUM_DAILY' => 1,
        'GA4_RF_CAMPAIGN_DAILY' => 1,
        'GA4_RF_LANDING_PAGE_DAILY' => 1,
        'GA4_RF_EVENT_DAILY' => 1,
        'GA4_RF_EVENT_BREAKDOWNS' => 1,
        'GA4_RF_DEVICE_DAILY' => 28,
        'GA4_RF_GENERIC_REPORT' => 28,
    ],

    'default_date_slice_days' => 7,

    /**
     * Contract policy: do not fabricate dense zero rows.
     */
    'keep_empty_rows' => false,

    'return_property_quota' => true,

    'metadata_cache_ttl_seconds' => (int) env('MOXDOP_GA4_METADATA_TTL', 3600),

    'compatibility_cache_ttl_seconds' => (int) env('MOXDOP_GA4_COMPAT_TTL', 3600),

    'http_timeout_seconds' => 60,

    'raw_retention_class' => 'provider_raw_standard',
];
