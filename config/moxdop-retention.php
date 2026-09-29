<?php

/*
 * Data retention (MoxDOP v2 Faz 1; `moxdop:retention`, monthly, dry-run unless --apply):
 * - daily facts (every *_daily table with reporting_date) are kept `daily_performance_months` (16); older months are
 *   rolled into performance_monthly_rollups, then deleted;
 * - the query daily facts (Search Console query × page, Google Ads search terms) are not rolled up generically: their
 *   monthly aggregate IS `query_sources`, kept `query_sources_months` (24);
 * - raw provider payloads / HTML copies older than `raw_payload_days` are deleted (each page's latest HTML is kept);
 * - telemetry is trimmed to its own window. GBP provider content has its own 30-day job (moxdop:gbp:purge-expired).
 */
return [
    'enabled' => (bool) env('MOXDOP_RETENTION_ENABLED', true),

    'raw_payload_days' => (int) env('MOXDOP_RETENTION_RAW_DAYS', 90),
    'raw_payload_batch' => 2000,

    'daily_performance_months' => (int) env('MOXDOP_RETENTION_DAILY_MONTHS', 16),

    /* Daily query facts whose monthly form is query_sources: deleted after the daily window without a generic rollup. */
    'query_daily_tables' => [
        'gsc_query_page_daily', 'google_ads_search_term_daily',
    ],

    /* Closed suggestions (applied / dismissed): deleted this many months after they were closed. */
    'closed_suggestion_months' => (int) env('MOXDOP_RETENTION_CLOSED_SUGGESTION_MONTHS', 12),

    /* Raw query layer (monthly): kept this many months. */
    'query_sources_months' => (int) env('MOXDOP_RETENTION_QUERY_SOURCES_MONTHS', 24),

    /* Veri merkezi: data sets holding queries / search terms / keywords; never deleted from the data center by hand. */
    'protected_tables' => [
        'gsc_query_page_daily', 'google_ads_search_term_daily', 'google_ads_keyword_daily',
        'gbp_search_keywords_monthly', 'query_sources',
        // no longer collected (v2) but still query history until retention empties them
        'gsc_query_daily', 'gsc_query_country_daily', 'gsc_query_device_daily', 'google_ads_keyword_snapshot',
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
        'app_error_groups' => ['last_seen_at', 90],
    ],
];
