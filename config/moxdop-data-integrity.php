<?php

return [

    'integrity_registry_path' => env(
        'MOXDOP_DATA_INTEGRITY_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_DATA_INTEGRITY_REGISTRY_V1.json')
    ),

    'integrity_registry_id' => 'MOXDOP_DATA_INTEGRITY_REGISTRY',

    'supported_integrity_registry_versions' => [1],

    'audit_rules_version' => 1,

    'default_mode' => 'LOCAL_INTEGRITY',

    /*
    |--------------------------------------------------------------------------
    | Provider reconciliation (explicit opt-in only)
    |--------------------------------------------------------------------------
    |
    | Never enabled by page render. Automated tests must keep this false
    | unless using fake provider clients.
    |
    */

    'allow_provider_reconciliation' => (bool) env('MOXDOP_INTEGRITY_PROVIDER_RECONCILE', false),

    'automatic_repair' => false,

    'numeric_quality_score' => false,

];
