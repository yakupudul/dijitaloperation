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
    'search_traffic_drop' => [
        'drop_pct' => 40,        // last 7 days vs previous 7 days
        'min_previous_clicks' => 50,
    ],
    'stale_data_hours' => 72,
    'bad_review' => [
        'max_stars' => 2,
        'days' => 7,
    ],
];
