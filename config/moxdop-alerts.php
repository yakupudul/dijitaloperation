<?php

/**
 * Daily asset alerts (moxdop:alerts:scan). Thresholds are deliberately conservative: an alert must be worth
 * interrupting someone for. Every check reads collected data only.
 */
return [
    'enabled' => (bool) env('MOXDOP_ALERTS_ENABLED', true),

    'spend_spike' => [
        'ratio' => 2.0,          // yesterday ≥ 2× the average of the 7 days before
        'min_baseline' => 50.0,  // ignore tiny accounts (account currency)
    ],
    'delivery_stopped' => [
        'min_baseline' => 20.0,  // 7-day average spend above which a zero day is alarming
    ],
    'conversions_stopped' => [
        'days' => 3,                 // consecutive days with spend and 0 conversions
        'min_daily_conversions' => 1.0, // prior 14-day average that makes zero suspicious
    ],
    'ga4_drop' => [
        'drop_pct' => 35,               // last 7 days vs previous 7 days
        'min_previous_sessions' => 100,
        'min_previous_key_events' => 10,
    ],
    'search_traffic_drop' => [
        'drop_pct' => 40,        // last 7 days vs previous 7 days
        'min_previous_clicks' => 50,
    ],
    'tracking' => [
        'ga4_min_daily_sessions' => 20,          // prior daily sessions that make "no GA4 data" alarming
        'conversions_stopped_days' => 3,         // days with sessions and zero counted GA4 conversions
        'conversions_min_daily' => 1.0,          // prior 14-day daily conversions that make zero suspicious
        'undefined_min_monthly_sessions' => 300, // traffic above which "no conversion defined" is worth saying
        'conversions_drop_share' => 0.5,         // Faz 14: last 7 days vs prior 28 days daily average drop
        'conversions_drop_min_daily' => 2.0,     // prior daily conversions below which a drop is noise
    ],
    'budget' => [
        'low_days' => 3,               // spend limit / prepaid balance left for fewer days of average spend → high
        'zero_spend_after_hour' => 14, // account-local hour after which "no spend today" is critical
        'capped_before_hour' => 20,    // campaign daily budget used up before this hour → high
    ],
    'stale_data_hours' => 72,
    'bad_review' => [
        'max_stars' => 2,
        'days' => 7,
    ],
];
