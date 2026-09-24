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
];
