<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Contract Registry
    |--------------------------------------------------------------------------
    */

    'registry_path' => env(
        'MOXDOP_DATA_CONTRACT_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json')
    ),

    'registry_id' => 'MOXDOP_DATA_CONTRACT_REGISTRY',

    'supported_registry_versions' => [1],

    /*
     * Frozen V1 registry + explicit provider amendments. Order is stable and
     * deterministic; later overlays may intentionally replace rows by id.
     */
    'registry_overlays' => [
        'moxdop-gbp-central.registry_overlay',
        'moxdop-google-ads-central.registry_overlay',
        'moxdop-google-ads-history.registry_overlay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue (collection control plane)
    |--------------------------------------------------------------------------
    |
    | Production collection work should use Redis + Horizon. Existing Activity
    | Center async jobs may continue on the app default (database) connection.
    | Tests use Queue::fake() and do not require Redis.
    |
    */

    'queue_connection' => env('COLLECTION_QUEUE_CONNECTION', 'redis'),

    'queue' => env('COLLECTION_QUEUE', 'collection'),

    'job_timeout_seconds' => (int) env('COLLECTION_JOB_TIMEOUT', 300),

    'job_tries' => (int) env('COLLECTION_JOB_TRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Default retry policy (provider-specific overrides later)
    |--------------------------------------------------------------------------
    */

    'default_max_attempts' => (int) env('COLLECTION_DEFAULT_MAX_ATTEMPTS', 3),

    'default_backoff_seconds' => [30, 90, 180],

    'stale_running_seconds' => (int) env('COLLECTION_STALE_RUNNING_SECONDS', 1800),

    /*
    |--------------------------------------------------------------------------
    | Fail closed when Redis collection connection is required but unavailable
    |--------------------------------------------------------------------------
    */

    'require_queue_connection' => (bool) env('COLLECTION_REQUIRE_QUEUE', true),

];
