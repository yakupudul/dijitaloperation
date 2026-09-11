<?php

return [
    'enabled' => true,
    'queue_connection' => env('RESOURCE_AUTOMATION_QUEUE_CONNECTION', env('APP_ENV') === 'staging' ? 'redis' : env('QUEUE_CONNECTION', 'database')),
    'max_active_collections' => 2,
    'accounts_per_tick' => 10,
    'query_accounts_per_tick' => 10,
    'max_active_query_imports' => 4,
    'query_rows_per_chunk' => 100,
];
