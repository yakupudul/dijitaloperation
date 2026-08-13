<?php

/**
 * Search Console production collector — contract/config-driven slice & page policy.
 * Official Search Analytics rowLimit max verified 2026-08-13: 25,000.
 */
return [
    'collector_version' => 'gsc-production-collector/1',

    /**
     * Official Search Console Search Analytics maximum rows per request.
     *
     * @see https://developers.google.com/webmaster-tools/v1/searchanalytics/query
     */
    'max_row_limit' => 25000,

    /**
     * Page size used by production requests (≤ max_row_limit).
     * Tests may lower this via config override to exercise pagination.
     */
    'page_size' => (int) env('MOXDOP_GSC_PAGE_SIZE', 25000),

    /** Max Search Analytics pages processed per DatasetExecutor invocation before Continue. */
    'max_pages_per_tick' => (int) env('MOXDOP_GSC_MAX_PAGES_PER_TICK', 50),

    /**
     * Preferred inclusive date-slice width (days) per request family.
     * High-cardinality families use daily slices by default.
     *
     * @var array<string, int>
     */
    'date_slice_days' => [
        'GSC_RF_PROPERTY_DAILY' => 28,
        'GSC_RF_QUERY_DAILY' => 1,
        'GSC_RF_PAGE_DAILY' => 1,
        'GSC_RF_QUERY_PAGE_DAILY' => 1,
        'GSC_RF_DEVICE_DAILY' => 28,
        'GSC_RF_COUNTRY_DAILY' => 7,
    ],

    'default_date_slice_days' => 7,

    /**
     * Search Analytics request defaults from SEARCH_CONSOLE_DATA_CONTRACT_V1.
     */
    'search_type' => 'web',
    'data_state' => 'final',

    /**
     * Controlled URL Inspection budget per DatasetRun (not site-wide).
     */
    'url_inspection_max_targets_per_run' => (int) env('MOXDOP_GSC_URL_INSPECTION_MAX', 25),

    'http_timeout_seconds' => 45,

    'raw_retention_class' => 'provider_raw_standard',
];
