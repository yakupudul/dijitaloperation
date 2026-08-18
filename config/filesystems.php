<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | MoxDOP raw ingestion (Prompt 10)
        |--------------------------------------------------------------------------
        |
        | PRIVATE object storage for provider payloads. Local disk in development/
        | tests; S3-compatible in production via env (no public ACL / permanent URLs).
        |
        */
        'raw_ingestion' => [
            'driver' => env('MOXDOP_RAW_INGESTION_DRIVER', 'local'),
            'root' => env('MOXDOP_RAW_INGESTION_ROOT', storage_path('app/raw_ingestion')),
            'throw' => true,
            'report' => false,
            'visibility' => 'private',
            // S3-compatible (used when MOXDOP_RAW_INGESTION_DRIVER=s3):
            'key' => env('MOXDOP_RAW_INGESTION_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('MOXDOP_RAW_INGESTION_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('MOXDOP_RAW_INGESTION_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('MOXDOP_RAW_INGESTION_BUCKET', env('AWS_BUCKET')),
            'url' => env('MOXDOP_RAW_INGESTION_URL'),
            'endpoint' => env('MOXDOP_RAW_INGESTION_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('MOXDOP_RAW_INGESTION_PATH_STYLE', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
