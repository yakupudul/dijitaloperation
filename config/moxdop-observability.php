<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt 66 — Observability & Operations
    |--------------------------------------------------------------------------
    | Explicit dimensions. No overall health score. No fake quotas.
    */

    'enabled' => env('MOXDOP_OBSERVABILITY_ENABLED', true),

    'liveness_path' => env('MOXDOP_OPS_LIVENESS_PATH', '/up/liveness'),
    'readiness_path' => env('MOXDOP_OPS_READINESS_PATH', '/up/readiness'),

    /*
    | Worker heartbeat — deployment-specific expected counts live in env.
    | Never hard-code developer laptop process counts as production truth.
    */
    'worker' => [
        'heartbeat_stale_seconds' => (int) env('MOXDOP_OPS_WORKER_HEARTBEAT_STALE_SECONDS', 180),
        'expected_supervisors' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MOXDOP_OPS_EXPECTED_SUPERVISORS', '')),
        ))),
    ],

    'queue' => [
        // Workload-class backlog: wait age matters more than raw count.
        'interactive_oldest_age_alert_seconds' => (int) env('MOXDOP_OPS_QUEUE_INTERACTIVE_AGE_SECONDS', 300),
        'background_oldest_age_alert_seconds' => (int) env('MOXDOP_OPS_QUEUE_BACKGROUND_AGE_SECONDS', 3600),
        'hold_duration_seconds' => (int) env('MOXDOP_OPS_QUEUE_HOLD_SECONDS', 120),
    ],

    'collection' => [
        // Workload-aware stuck policies (seconds without progress).
        'stuck_incremental_no_progress_seconds' => (int) env('MOXDOP_OPS_STUCK_INCREMENTAL_SECONDS', 900),
        'stuck_backfill_no_progress_seconds' => (int) env('MOXDOP_OPS_STUCK_BACKFILL_SECONDS', 7200),
        'stuck_default_no_progress_seconds' => (int) env('MOXDOP_OPS_STUCK_DEFAULT_SECONDS', 1800),
    ],

    'provider_api' => [
        'window_seconds' => 900,
        'error_rate_minimum_attempts' => (int) env('MOXDOP_OPS_PROVIDER_MIN_ATTEMPTS', 20),
        'error_rate_threshold' => (float) env('MOXDOP_OPS_PROVIDER_ERROR_RATE', 0.35),
        'rate_limit_minimum_attempts' => (int) env('MOXDOP_OPS_RATE_LIMIT_MIN_ATTEMPTS', 10),
        'rate_limit_threshold' => (float) env('MOXDOP_OPS_RATE_LIMIT_RATE', 0.25),
        'counter_retention_hours' => (int) env('MOXDOP_OPS_PROVIDER_COUNTER_RETENTION_HOURS', 72),
    ],

    'dataset' => [
        // Stale alerts use Prompt27 state + operational hold — not universal hours.
        'stale_hold_seconds' => (int) env('MOXDOP_OPS_STALE_HOLD_SECONDS', 1800),
    ],

    'scheduler' => [
        'dispatcher_stale_seconds' => (int) env('MOXDOP_OPS_SCHEDULER_STALE_SECONDS', 600),
    ],

    'alert' => [
        'notify_on_open' => (bool) env('MOXDOP_OPS_ALERT_NOTIFY_ON_OPEN', true),
        'notify_on_resolve' => (bool) env('MOXDOP_OPS_ALERT_NOTIFY_ON_RESOLVE', false),
        // Explicit internal recipient user IDs. Empty = Admin role users only.
        // Zero recipients: Alert stays OPEN, no notify-all fallback.
        'recipient_user_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('MOXDOP_OPS_ALERT_RECIPIENT_USER_IDS', '')),
        ))),
    ],

    /*
    | Versioned operational alert rules (deterministic; no SQL/PHP expressions).
    | Thresholds that are NOT_CONFIGURED stay disabled until deployment sets env.
    */
    'rules' => [
        [
            'key' => 'queue_interactive_backlog',
            'version' => 1,
            'type' => 'QUEUE_BACKLOG',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'QUEUE',
            'hold_seconds' => null, // uses queue.hold_duration_seconds
            'recovery' => 'oldest_age_below_threshold',
        ],
        [
            'key' => 'worker_heartbeat_missing',
            'version' => 1,
            'type' => 'QUEUE_WORKER_UNAVAILABLE',
            'enabled' => true,
            'severity' => 'CRITICAL',
            'signal_family' => 'WORKER',
            'hold_seconds' => null,
            'recovery' => 'heartbeat_fresh',
        ],
        [
            'key' => 'collection_stuck',
            'version' => 1,
            'type' => 'COLLECTION_STUCK',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'COLLECTION',
            'hold_seconds' => 0,
            'recovery' => 'no_stuck_candidates',
        ],
        [
            'key' => 'collection_repeated_failure',
            'version' => 1,
            'type' => 'COLLECTION_REPEATED_FAILURE',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'COLLECTION',
            'min_failures' => 3,
            'window_seconds' => 3600,
            'recovery' => 'no_recent_failures',
        ],
        [
            'key' => 'provider_rate_limited',
            'version' => 1,
            'type' => 'PROVIDER_RATE_LIMITED',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'PROVIDER_API',
            'recovery' => 'rate_below_threshold',
        ],
        [
            'key' => 'provider_error_rate',
            'version' => 1,
            'type' => 'PROVIDER_ERROR_RATE',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'PROVIDER_API',
            'recovery' => 'error_rate_below_threshold',
        ],
        [
            'key' => 'credential_reconnect_required',
            'version' => 1,
            'type' => 'PROVIDER_AUTH_FAILURE',
            'enabled' => true,
            'severity' => 'CRITICAL',
            'signal_family' => 'CREDENTIAL',
            'recovery' => 'credential_active',
        ],
        [
            'key' => 'dataset_stale',
            'version' => 1,
            'type' => 'DATASET_STALE',
            'enabled' => true,
            'severity' => 'WARNING',
            'signal_family' => 'DATASET',
            'recovery' => 'freshness_current',
        ],
    ],
];
