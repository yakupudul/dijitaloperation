<?php

$dbWorkerAuthoritative = (bool) env('COLLECTION_DB_WORKER_AUTHORITATIVE', true);

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
        'moxdop-meta-ads-central.registry_overlay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection execution control plane
    |--------------------------------------------------------------------------
    |
    | CollectionDatasetRun rows in PostgreSQL are the durable source of truth.
    | Dedicated Supervisor workers execute eligible rows directly from DB state.
    | When DB workers are authoritative, queue dispatch calls intentionally go to
    | Laravel's null sink so Redis/Horizon cannot create duplicate attempts or
    | provider quota bursts. Set COLLECTION_DB_WORKER_AUTHORITATIVE=false only on
    | a deployment that intentionally uses the legacy queue-driven execution path.
    |
    */

    'db_worker_authoritative' => $dbWorkerAuthoritative,

    'queue_connection' => $dbWorkerAuthoritative
        ? 'null'
        : env('COLLECTION_QUEUE_CONNECTION', 'redis'),

    'queue' => env('COLLECTION_QUEUE', 'collection'),

    'job_timeout_seconds' => (int) env('COLLECTION_JOB_TIMEOUT', 300),

    'job_tries' => (int) env('COLLECTION_JOB_TRIES', 3),

    /*
     * Legacy dispatch claims remain leases for compatibility with older Redis
     * deliveries. DB workers do not depend on these claims; execution locks are
     * still the final duplicate-execution guard.
     */
    'queue_dispatch_claim_lease_seconds' => (int) env('COLLECTION_DISPATCH_CLAIM_LEASE', 120),

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
    | Fail closed when the configured dispatch sink cannot be resolved
    |--------------------------------------------------------------------------
    */

    'require_queue_connection' => (bool) env('COLLECTION_REQUIRE_QUEUE', true),

];