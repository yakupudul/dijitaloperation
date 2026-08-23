<?php

/**
 * Google Ads lifetime activity discovery and monthly history overlay.
 *
 * Google Ads granular date/hour/week reporting has a provider lookback boundary,
 * while month/quarter/year segments can represent older history. MOXDOP therefore
 * discovers lifetime activity at monthly grain and uses that result to bound the
 * expensive daily-detail backfill.
 */

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'dimension'): array => [
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
];

$datasetId = 'google_ads_account_monthly_history';
$familyId = 'GADS_CENTRAL_RF_ACCOUNT_MONTHLY_HISTORY';
$requirementId = 'REQ_GADS_ACCOUNT_MONTHLY_HISTORY_V1';

return [
    'overlay_id' => 'GOOGLE_ADS_HISTORY_V1',

    // Lifetime discovery is intentionally broad but low-volume (one row/month).
    'discovery_start_date' => env('MOXDOP_GOOGLE_ADS_HISTORY_START_DATE', '2000-01-01'),

    // Provider-safe daily-detail boundary. Lifetime history older than this is
    // still retained monthly instead of being silently discarded.
    'granular_lookback_months' => (int) env('MOXDOP_GOOGLE_ADS_GRANULAR_LOOKBACK_MONTHS', 37),

    'natural_key_overrides' => [],
    'columns_add' => [],
    'physical_additions' => [
        $datasetId => [
            'table' => $datasetId,
            'provider_or_source' => 'GOOGLE_ADS',
            'storage_class' => 'NORMALIZED_FACT',
            'write_mode' => 'UPSERT_DAILY_FACT',
            'partition_strategy' => 'NONE',
            'partition_column' => null,
            'grain' => ['external_resource_id', 'customer_id', 'reporting_month'],
            'natural_key' => ['external_resource_id', 'customer_id', 'reporting_month'],
            'columns' => [
                $column('digital_asset_id', 'bigint', true, 'scope'),
                $column('external_resource_id', 'bigint', false, 'scope'),
                $column('customer_id', 'text', false, 'identity'),
                $column('reporting_month', 'date', false, 'identity'),
                $column('impressions', 'bigint', false, 'metric'),
                $column('clicks', 'bigint', false, 'metric'),
                $column('cost_micros', 'bigint', false, 'metric'),
                $column('cost_amount', 'decimal', false, 'metric'),
                $column('conversions', 'decimal', false, 'metric'),
                $column('conversions_value', 'decimal', false, 'metric'),
                $column('currency', 'char(3)', false, 'dimension'),
                $column('activity_detected', 'boolean', false, 'dimension'),
                $column('contract_version', 'integer', false, 'provenance'),
                $column('last_collection_run_id', 'bigint', true, 'provenance'),
                $column('last_dataset_run_id', 'bigint', true, 'provenance'),
                $column('first_collected_at', 'timestamptz', false, 'provenance'),
                $column('last_collected_at', 'timestamptz', false, 'provenance'),
                $column('source_timezone', 'text', true, 'provenance'),
                $column('record_fingerprint', 'char(64)', false, 'provenance'),
                $column('metadata', 'json', true, 'extension'),
            ],
        ],
    ],

    'registry_overlay' => [
        'overlay_id' => 'GOOGLE_ADS_HISTORY_V1',
        'datasets' => [[
            'id' => $datasetId,
            'name' => 'Google Ads account monthly history',
            'provider_or_source' => 'GOOGLE_ADS',
            'source_class' => 'PROVIDER_MEASURED',
            'grain' => ['external_resource_id', 'customer_id', 'reporting_month'],
            'dimensions' => ['reporting_month'],
            'metrics' => ['impressions', 'clicks', 'cost_micros', 'conversions', 'conversions_value'],
            'status' => 'COLLECTION_READY',
            'contract_version' => 1,
        ]],
        'request_families' => [[
            'id' => $familyId,
            'name' => 'Google Ads lifetime monthly activity history',
            'provider_or_source' => 'GOOGLE_ADS',
            'dataset' => $datasetId,
            'retrieval' => 'SEARCH_STREAM',
            'eligibility' => ['non-manager Google Ads customer'],
            'status' => 'COLLECTION_READY',
        ]],
        'requirements' => [[
            'id' => $requirementId,
            'name' => $datasetId,
            'consumer' => [],
            'provider_or_source' => 'GOOGLE_ADS',
            'source_class' => 'PROVIDER_MEASURED',
            'operating_mode' => 'CORE_PROVIDER_RESOURCE_FIRST',
            'dataset' => $datasetId,
            'request_family' => $familyId,
            'dimensions' => ['reporting_month'],
            'metrics' => ['impressions', 'clicks', 'cost_micros', 'conversions', 'conversions_value'],
            'grain' => ['external_resource_id', 'customer_id', 'reporting_month'],
            'historical_depth' => [
                'minimum_required' => 'lifetime_monthly_where_provider_available',
                'recommended_initial_backfill' => 'lifetime_monthly_where_provider_available',
                'decision_required' => false,
            ],
            'refresh_cadence' => ['type' => 'baseline_then_on_demand', 'cadence' => 'on_demand'],
            'requirement_level' => 'REQUIRED',
            'storage_class' => 'NORMALIZED_FACT',
            'provenance' => 'PROVIDER_MEASURED',
            'contract_version' => 1,
            'registry_version' => 1,
            'source_contract' => 'GOOGLE_ADS_HISTORY_COLLECTION',
            'source_contract_version' => 1,
            'status' => 'COLLECTION_READY',
            'collection_readiness' => 'COLLECTION_READY',
            'timezone_policy' => 'google_ads_customer_time_zone',
            'currency_policy' => 'provider_account_currency_no_fx',
            'additivity' => 'ADDITIVE_BASE_FACTS_ONLY',
            'missing_semantics' => 'NOT_COLLECTED_NEQ_ZERO',
            'notes' => 'Monthly lifetime activity map used to drive activity-aware detailed backfill.',
        ]],
    ],
];
