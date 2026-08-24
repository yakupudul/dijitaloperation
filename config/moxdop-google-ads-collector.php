<?php

/**
 * Google Ads production collector — contract/config-driven slice & retrieval policy.
 * Official Search page size fixed at 10,000 (verified 2026-08-13).
 * SearchStream has no pageToken; date-slice is the durable replay boundary.
 */
return [
    'collector_version' => 'google-ads-production-collector/2',

    'api_version' => (string) config('moxdop.google.ads_api_version', 'v25'),

    /** Official GoogleAdsService.Search page size */
    'search_page_size' => 10000,

    'max_search_pages_per_tick' => (int) env('MOXDOP_GADS_MAX_SEARCH_PAGES_PER_TICK', 20),

    /** Application write batch size while processing SearchStream / large Search pages */
    'write_batch_size' => (int) env('MOXDOP_GADS_WRITE_BATCH_SIZE', 500),

    'search_stream_timeout_seconds' => (int) env('MOXDOP_GADS_STREAM_TIMEOUT', 120),

    /**
     * Provider request governor. Requests for one Customer ID are serialized, and
     * RESOURCE_EXHAUSTED establishes a shared developer-token cooldown.
     */
    'minimum_request_interval_ms' => (int) env('MOXDOP_GADS_MIN_REQUEST_INTERVAL_MS', 500),
    'request_lock_seconds' => (int) env('MOXDOP_GADS_REQUEST_LOCK_SECONDS', 180),
    'request_lock_wait_seconds' => (int) env('MOXDOP_GADS_REQUEST_LOCK_WAIT_SECONDS', 30),
    'request_lock_contention_retry_seconds' => (int) env('MOXDOP_GADS_REQUEST_LOCK_CONTENTION_RETRY_SECONDS', 15),

    /**
     * Google Ads transient failures preserve checkpoints. Provider-supplied Retry
     * windows always win over this fallback exponential backoff.
     */
    'retry_max_attempts' => (int) env('MOXDOP_GADS_RETRY_MAX_ATTEMPTS', 7),

    /** @var list<int> */
    'retry_backoff_seconds' => [10, 20, 40, 80, 160, 300, 300],

    'retry_jitter_seconds' => (int) env('MOXDOP_GADS_RETRY_JITTER_SECONDS', 5),

    /**
     * Inclusive date slices. Previous one-day slices for keyword/search-term/
     * landing-page history multiplied operation count unnecessarily and could burn
     * the developer-token rolling quota during history repair.
     *
     * @var array<string, int>
     */
    'date_slice_days' => [
        'GADS_RF_ACCOUNT_DAILY' => 28,
        'GADS_RF_SEARCH_STREAM' => 28,
        'GADS_RF_CAMPAIGN_DAILY' => 14,
        'GADS_RF_KEYWORD' => 14,
        'GADS_RF_SEARCH_TERM' => 7,
        'GADS_RF_LANDING_PAGE' => 28,
        'GADS_RF_CONVERSION_ACTION' => 14,
    ],

    'default_date_slice_days' => 14,

    'raw_retention_class' => 'provider_raw_standard',
];
