<?php

return [
    'collector_version' => 'meta-ads-production-collector-v1',
    'api_version' => env('META_GRAPH_API_VERSION', 'v26.0'),
    'verification_date' => '2026-08-13',
    'raw_retention_class' => 'standard',
    'write_batch_size' => (int) env('MOXDOP_META_ADS_WRITE_BATCH_SIZE', 500),
    'max_insight_pages_per_tick' => (int) env('MOXDOP_META_ADS_MAX_PAGES_PER_TICK', 25),
    'default_date_slice_days' => (int) env('MOXDOP_META_ADS_DEFAULT_SLICE_DAYS', 7),
    'date_slice_days' => [
        'RF_META_INSIGHTS_DAILY' => (int) env('MOXDOP_META_ADS_DAILY_SLICE_DAYS', 7),
        'RF_META_TYPED_ACTIONS' => (int) env('MOXDOP_META_ADS_ACTIONS_SLICE_DAYS', 7),
        'RF_META_INSIGHTS_BREAKDOWN' => (int) env('MOXDOP_META_ADS_BREAKDOWN_SLICE_DAYS', 3),
        'RF_META_INSIGHTS_SYNC' => (int) env('MOXDOP_META_ADS_SYNC_SLICE_DAYS', 14),
    ],
    /**
     * Prefer async Insights when date span (days) exceeds this for high-cardinality levels.
     * Strategy is still deterministic per Request Family (see MetaInsightsRetrievalStrategy).
     */
    'async_day_threshold' => [
        'campaign' => (int) env('MOXDOP_META_ADS_ASYNC_DAYS_CAMPAIGN', 90),
        'adset' => (int) env('MOXDOP_META_ADS_ASYNC_DAYS_ADSET', 45),
        'ad' => (int) env('MOXDOP_META_ADS_ASYNC_DAYS_AD', 30),
        'account' => (int) env('MOXDOP_META_ADS_ASYNC_DAYS_ACCOUNT', 90),
    ],
    'async_poll_backoff_seconds' => (int) env('MOXDOP_META_ADS_ASYNC_POLL_BACKOFF', 30),
    'async_max_poll_attempts' => (int) env('MOXDOP_META_ADS_ASYNC_MAX_POLLS', 40),
    /**
     * Meta Ad Account budgets are returned in the account's minor currency units (cents for
     * most ISO currencies). NOT Google Ads micros.
     */
    'budget_minor_units_divisor' => 100,
    'insights_fields' => [
        'spend',
        'impressions',
        'reach',
        'frequency',
        'clicks',
        'inline_link_clicks',
        'outbound_clicks',
        'actions',
        'action_values',
        'account_currency',
        'campaign_id',
        'adset_id',
        'ad_id',
        'date_start',
        'date_stop',
    ],
    'attribution' => [
        // Contract: use unified attribution setting for Insights actions (reproducible).
        'use_unified_attribution_setting' => true,
        'action_attribution_windows' => null,
    ],
];
