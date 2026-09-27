<?php

/**
 * Proof that data and connections are real and correct.
 *
 * live: moxdop:verify:live — one cheapest read-only call per active integration and bound account, daily.
 * consistency: moxdop:verify:data — per bound account over the last complete days, "Veri şüpheli" items.
 */
return [
    'live' => [
        'enabled' => (bool) env('MOXDOP_VERIFY_LIVE_ENABLED', true),
        'time' => (string) env('MOXDOP_VERIFY_LIVE_TIME', '06:20'),
        // live_checks rows older than this are deleted after each run.
        'retention_days' => (int) env('MOXDOP_VERIFY_LIVE_RETENTION_DAYS', 30),
        // Latest result per check older than this is not shown as failing in Komuta merkezi.
        'stale_hours' => 48,
    ],

    'consistency' => [
        'enabled' => (bool) env('MOXDOP_VERIFY_DATA_ENABLED', true),
        'time' => (string) env('MOXDOP_VERIFY_DATA_TIME', '07:25'),
        'window_days' => 14,
        // (b) Google Ads spends but GA4 shows no google / cpc session on at least this many days.
        'untagged_min_days' => 3,
        // Ignore spend days below this amount (account currency).
        'untagged_min_daily_spend' => 1.0,
        // (c) Google Ads conversions vs GA4 key events from google / cpc sessions.
        'conversion_min' => 10,
        'conversion_divergence_pct' => 50,
        'paid_mediums' => ['cpc', 'ppc', 'paid', 'paidsearch'],
    ],
];
