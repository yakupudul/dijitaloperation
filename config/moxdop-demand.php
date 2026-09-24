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

    /*
     * Area SERP checks (paid, DataForSEO Live Regular). Per-brand opt-in on the brand page; never exceeds the
     * brand's monthly USD cap. A result is reused (no new call) for the same keyword + location within
     * freshness_days, for any brand.
     */
    'serp' => [
        'monthly_usd_per_brand' => (float) env('MOXDOP_DEMAND_SERP_MONTHLY_USD', 2.0),
        'cost_per_check_usd' => (float) env('MOXDOP_DEMAND_SERP_COST_USD', 0.002),
        'freshness_days' => 28,
        'queries_per_service' => 3,
        'areas_per_query' => 2,
        'max_services' => 10,
        'language_code' => 'tr',
        'fallback_location_code' => 2792, // Türkiye
        'competitor_min_keywords' => 2,
        'weekly_time' => '05:45',
    ],
];
