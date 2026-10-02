<?php

/*
 * Faz 6 — Asistan: uptime checks, renewal reminders, reminders and the calendar feed.
 */
return [
    'uptime' => [
        'enabled' => (bool) env('MOXDOP_UPTIME_ENABLED', true),
        'failures_before_down' => (int) env('MOXDOP_UPTIME_FAILURES_BEFORE_DOWN', 2),
    ],
    'renewals' => [
        // Days before expiry that raise the renewal alert / push (the closest one wins).
        'warn_days' => [30, 14, 7, 1],
        'rdap_bootstrap' => 'https://rdap.org/domain/',
        'rdap_refresh_days' => 7,
    ],
    'reminders' => [
        'calendar_days_ahead' => 90,
    ],

    // Faz 10b: müşteri sağlığı puanı (100'den düşülen puanlar).
    'health' => [
        'drop_share' => 0.2,             // son 28 gün, önceki 28 güne göre bu oranda düşüş
        'min_conversions' => 5,
        'conversion_drop_points' => 20,
        'traffic_drop_points' => 10,
        'silent_days' => 30,
        'silence_points' => 15,
        'renewal_points' => 10,
        'unpaid_points' => 10,
        'critical_alert_points' => 15,
        'high_alert_points' => 5,
    ],
];
