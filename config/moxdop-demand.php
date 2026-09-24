<?php

/*
 * Demand pipeline (Faz 2b). The brand demand table is rebuilt weekly from the brand's own accounts.
 * value_score = weights · window metrics (clicks and conversions weigh most; impressions show demand).
 */
return [
    'window_days' => (int) env('MOXDOP_DEMAND_WINDOW_DAYS', 90),
    'gbp_months' => 3,
    'max_queries_per_source' => 5000,

    'value_weights' => [
        'gsc_clicks' => 1.0,
        'gsc_impressions' => 0.05,
        'ads_clicks' => 0.5,
        'ads_conversions' => 20.0,
        'gbp_impressions' => 0.02,
    ],

    'schedule' => [
        'weekly_day' => 1,          // Monday, before the SEO plan (06:30)
        'weekly_time' => '05:30',
    ],
];
