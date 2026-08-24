<?php

/**
 * Google Ads production collector — contract/config-driven slice & retrieval policy.
 * Official Search page size fixed at 10,000 (verified 2026-08-13).
 * SearchStream has no pageToken; date-slice is the durable replay boundary.
 */
return [
    'collector_version' => 'google-ads-production-collector/1',

    'api_version' => (string) config('moxdop.google.ads_api_version', 'v25'),

    /** Official GoogleAdsService.Search page size */
    'search_page_size' => 10000,

    'max_search_pages_per_tick' => (int) env('MOXDOP_GADS_MAX_SEARCH_PAGES_PER_TICK', 20),

    /** Application write batch size while processing SearchStream / large Search pages */
    'write_batch_size' => (int) env('MOXDOP_GADS_WRITE_BATCH_SIZE', 500),

    'search_stream_timeout_seconds' => (int) env('MOXDOP_GADS_STREAM_TIMEOUT', 120),

    /**
     * Google Ads transient failures are frequently short-lived provider/network events.
     * Dataset-level retries preserve checkpoints and use exponential backoff with a
     * small deterministic jitter so concurrent account datasets do not retry in lockstep.
     */
    'retry_max_attempts' => (int) env('MOXDOP_GADS_RETRY_MAX_ATTEMPTS', 7),

    /** @var list<int> */
    'retry_backoff_seconds' => [10, 20, 40, 80, 160, 300, 300],

    'retry_jitter_seconds' => (int) env('MOXDOP_GADS_RETRY_JITTER_SECONDS', 5),

    /**
     * Preferred inclusive date-slice width (days) per request family.
     *
     * @var array<string, int>
     */
    'date_slice_days' => [
        'GADS_RF_ACCOUNT_DAILY' => 28,
        'GADS_RF_SEARCH_STREAM' => 28,
        'GADS_RF_CAMPAIGN_DAILY' => 7,
        'GADS_RF_KEYWORD' => 1,
        'GADS_RF_SEARCH_TERM' => 1,
        'GADS_RF_LANDING_PAGE' => 1,
        'GADS_RF_CONVERSION_ACTION' => 7,
    ],

    'default_date_slice_days' => 7,

    'raw_retention_class' => 'provider_raw_standard',
];
