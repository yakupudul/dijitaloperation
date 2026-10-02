<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Pool Storage Contract
    |--------------------------------------------------------------------------
    */

    'storage_contract_path' => env(
        'MOXDOP_DATA_POOL_STORAGE_PATH',
        base_path('docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.json')
    ),

    'storage_contract_id' => 'MOXDOP_DATA_POOL_STORAGE',

    'supported_storage_contract_versions' => [1],

    'production_database' => 'POSTGRESQL',

    /*
    |--------------------------------------------------------------------------
    | Raw ingestion (private object storage)
    |--------------------------------------------------------------------------
    */

    'raw_disk' => env('MOXDOP_RAW_INGESTION_DISK', 'raw_ingestion'),

    'raw_compression' => env('MOXDOP_RAW_COMPRESSION', 'gzip'),

    'raw_retention_policy_status' => 'REQUIRES_LATER_OPERATIONAL_DECISION',

    /*
    |--------------------------------------------------------------------------
    | Warehouse writer
    |--------------------------------------------------------------------------
    */

    'default_batch_size' => (int) env('MOXDOP_WAREHOUSE_BATCH_SIZE', 500),

    'partition_backfill_months_ahead' => (int) env('MOXDOP_PARTITION_MONTHS_AHEAD', 1),

    /*
    |--------------------------------------------------------------------------
    | Raw retention requirement when dataset disposition is RAW_ONLY / required
    |--------------------------------------------------------------------------
    |
    | When raw_write_required is true for a dataset and raw write fails,
    | normalized commit must not report complete success.
    |
    */

    'raw_required_dispositions' => ['RAW_ONLY'],

];
