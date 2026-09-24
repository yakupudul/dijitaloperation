<?php

/*
 * Data retention (roadmap principle 2). Gold data is never deleted: GSC queries, Ads search terms and keywords,
 * GBP search keywords, DataForSEO keyword data, AI outputs, work items and measured outcomes.
 * Daily performance older than `daily_performance_months` is rolled into performance_monthly_rollups, then deleted.
 * Raw provider payloads / HTML copies are deleted after `raw_payload_days` (each page's latest HTML is kept).
 * Telemetry is deleted after its own window. GBP provider content has its own 30-day job (moxdop:gbp:purge-expired).
 */
return [
    'enabled' => (bool) env('MOXDOP_RETENTION_ENABLED', true),

    'raw_payload_days' => (int) env('MOXDOP_RETENTION_RAW_DAYS', 90),
    'raw_payload_batch' => 2000,

    'daily_performance_months' => (int) env('MOXDOP_RETENTION_DAILY_MONTHS', 25),

    /* Daily tables that are gold and never rolled up or deleted. */
    'gold_daily_tables' => [
        'gsc_query_daily', 'gsc_query_country_daily', 'gsc_query_device_daily', 'gsc_query_page_daily',
        'google_ads_search_term_daily', 'google_ads_keyword_daily',
    ],

    /* Columns that are bookkeeping, never a dimension or a metric. */
    'ignored_columns' => [
        'id', 'reporting_date', 'contract_version', 'last_collection_run_id', 'last_dataset_run_id',
        'first_collected_at', 'last_collected_at', 'source_timezone', 'record_fingerprint', 'metadata',
        'created_at', 'updated_at', 'collected_at', 'run_id',
    ],

    /* Numeric columns matching this pattern are ratios/averages, stored as a weighted average (by impressions,
       else sessions, else plain), never a sum. Meta `reach` is summed per day (approximation; not de-duplicated). */
    'average_column_pattern' => '/(?i:rate|ctr|average|avg|position|percent|share|frequency|cpm|cpc|cpa|cost_per|roas|score|per_)|Per[A-Z]/',
    'average_weight_columns' => ['impressions', 'sessions'],

    /* Numeric columns that identify something (kept as dimensions). */
    'dimension_numeric_pattern' => '/(_id$|Id$|^hour$|^dayOfWeek$|^day_of_week$|^year$|^month$|_code$)/',

    /* table => [timestamp column, days, optional extra where [column, operator, value]] */
    'telemetry' => [
        'provider_api_counters' => ['window_started_at', 30],
        'ai_provider_attempts' => ['created_at', 90],
        'collection_dataset_attempts' => ['created_at', 90],
        'worker_heartbeats' => ['last_seen_at', 30],
        'ops_dispatcher_heartbeats' => ['last_seen_at', 30],
        'report_share_access_events' => ['created_at', 180],
        'whatsapp_webhook_receipts' => ['created_at', 30, ['status', '=', 'completed']],
        'google_oauth_authorization_attempts' => ['created_at', 30],
        'meta_oauth_authorization_attempts' => ['created_at', 30],
        'google_integration_discovery_attempts' => ['created_at', 90],
        'meta_integration_discovery_attempts' => ['created_at', 90],
        'search_demand_provider_payloads' => ['captured_at', 90],
        'uptime_checks' => ['checked_at', 30],
        'push_notifications' => ['created_at', 90],
    ],
];
