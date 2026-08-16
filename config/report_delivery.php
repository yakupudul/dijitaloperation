<?php

/**
 * Prompt 60 — Report PDF / Authenticated Share / Delivery configuration.
 * Centralized TTLs and policies — do not scatter magic durations.
 */
return [
    'pdf' => [
        'disk' => env('REPORT_PDF_DISK', 'local'),
        'directory' => 'report-artifacts',
        'renderer_version' => 'client_value_story_pdf_v1',
        'mime' => 'application/pdf',
    ],

    'share' => [
        'default_ttl_hours' => (int) env('REPORT_SHARE_TTL_HOURS', 72),
        'max_ttl_hours' => (int) env('REPORT_SHARE_MAX_TTL_HOURS', 720),
        'session_ttl_minutes' => (int) env('REPORT_SHARE_SESSION_TTL_MINUTES', 60),
        'otp_ttl_minutes' => (int) env('REPORT_SHARE_OTP_TTL_MINUTES', 15),
        'otp_max_attempts' => (int) env('REPORT_SHARE_OTP_MAX_ATTEMPTS', 5),
        'otp_resend_cooldown_seconds' => (int) env('REPORT_SHARE_OTP_RESEND_COOLDOWN', 60),
        'otp_request_max_per_hour' => (int) env('REPORT_SHARE_OTP_REQUEST_MAX', 10),
        'cookie' => 'moxdop_report_share_session',
    ],

    'delivery' => [
        'email_template_version' => 'report_delivery_email_v1',
        'subject_template_version' => 'report_delivery_subject_v1',
        'max_attempts' => (int) env('REPORT_DELIVERY_MAX_ATTEMPTS', 5),
        'default_mode' => 'authenticated_secure_link_with_pdf_access',
    ],

    'schedule' => [
        'default_timezone' => env('REPORT_SCHEDULE_TIMEZONE', 'Europe/Istanbul'),
        'default_share_ttl_hours' => (int) env('REPORT_SCHEDULE_SHARE_TTL_HOURS', 168),
        'dispatcher_lookback_minutes' => 30,
    ],
];
