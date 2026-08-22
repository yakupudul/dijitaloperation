<?php

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'metric'): array => [
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
];

$commonColumns = static function (array $dimensions) use ($column): array {
    return [
        $column('digital_asset_id', 'bigint', true, 'scope'),
        $column('external_resource_id', 'bigint', false, 'scope'),
        $column('site_url', 'text', false, 'identity'),
        $column('reporting_date', 'date', false, 'identity'),
        $column('search_type', 'text', false, 'identity'),
        ...array_map(fn (string $name): array => $column($name, 'text', false, 'dimension'), $dimensions),
        $column('clicks', 'bigint', false, 'metric'),
        $column('impressions', 'bigint', false, 'metric'),
        $column('contract_version', 'integer', false, 'provenance'),
        $column('last_collection_run_id', 'bigint', true, 'provenance'),
        $column('last_dataset_run_id', 'bigint', true, 'provenance'),
        $column('first_collected_at', 'timestamptz', false, 'provenance'),
        $column('last_collected_at', 'timestamptz', false, 'provenance'),
        $column('source_timezone', 'text', true, 'provenance'),
        $column('record_fingerprint', 'char(64)', false, 'provenance'),
        $column('metadata', 'json', true, 'extension'),
    ];
};

$physical = static function (string $table, array $dimensions) use ($commonColumns): array {
    $grain = ['external_resource_id', 'site_url', 'reporting_date', 'search_type', ...$dimensions];

    return [
        'table' => $table,
        'provider_or_source' => 'SEARCH_CONSOLE',
        'storage_class' => 'NORMALIZED_FACT',
        'write_mode' => 'UPSERT_DAILY_FACT',
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => $commonColumns($dimensions),
    ];
};

return [
    'initial_days' => 486,
    'restatement_days' => 7,
    'final_lag_days' => 3,

    // The first central import is deliberately web-first. Optional Google search
    // surfaces are stored safely via search_type and can be enabled without a schema change.
    'initial_search_types' => ['web'],

    'natural_key_overrides' => [
        'gsc_property_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type'],
        'gsc_query_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'query'],
        'gsc_page_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'page'],
        'gsc_query_page_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'query', 'page'],
        'gsc_device_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'device'],
        'gsc_country_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'country'],
        'gsc_search_appearance_daily' => ['external_resource_id', 'site_url', 'reporting_date', 'search_type', 'searchAppearance'],
    ],

    'columns_add' => [
        'gsc_property_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_query_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_page_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_query_page_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_device_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_country_daily' => [$column('search_type', 'text', false, 'identity')],
        'gsc_search_appearance_daily' => [$column('search_type', 'text', false, 'identity')],
    ],

    'physical_additions' => [
        'gsc_page_device_daily' => $physical('gsc_page_device_daily', ['page', 'device']),
        'gsc_page_country_daily' => $physical('gsc_page_country_daily', ['page', 'country']),
        'gsc_query_device_daily' => $physical('gsc_query_device_daily', ['query', 'device']),
        'gsc_query_country_daily' => $physical('gsc_query_country_daily', ['query', 'country']),
        'gsc_search_appearance_page_daily' => $physical('gsc_search_appearance_page_daily', ['searchAppearance', 'page']),
    ],
];