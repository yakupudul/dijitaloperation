<?php

/**
 * Meta Ads professional collection overlay.
 *
 * Analytical root: bound Meta Ad Account. Business Portfolio is discovery /
 * ownership context only. A Meta Ads Digital Asset may bind multiple ad
 * accounts (active, historical, test, excluded by operator policy); facts stay
 * account-scoped and can later be aggregated at Brand level without blending
 * account identity.
 *
 * Historical policy follows the marginal-value tiers chosen for MOXDOP:
 * - CORE: 37 months (~1125 days) for account + campaign daily facts.
 * - WARM: 13 months (~395 days) for ad set, ad, actions, video and breakdowns.
 * - HOT: 90 days for hourly delivery diagnostics.
 * - Daily late-attribution reconciliation: last 7 days.
 * - Weekly reconciliation: last 35 days.
 */

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'dimension', mixed $default = null): array => array_filter([
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
    'default' => $default,
], static fn (mixed $value, string $key): bool => $key !== 'default' || $value !== null, ARRAY_FILTER_USE_BOTH);

$provenance = static function () use ($column): array {
    return [
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

$dailyBase = static function (string $table, array $dimensions, array $grain) use ($column, $provenance): array {
    return [
        'table' => $table,
        'provider_or_source' => 'META_ADS',
        'storage_class' => 'NORMALIZED_FACT',
        'write_mode' => 'UPSERT_DAILY_FACT',
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => [
            $column('digital_asset_id', 'bigint', true, 'scope'),
            $column('external_resource_id', 'bigint', false, 'scope'),
            $column('account_id', 'text', false, 'identity'),
            $column('reporting_date', 'date', false, 'identity'),
            ...$dimensions,
            ...$provenance(),
        ],
    ];
};

$snapshotBase = static function (string $table, array $dimensions, array $grain) use ($column, $provenance): array {
    return [
        'table' => $table,
        'provider_or_source' => 'META_ADS',
        'storage_class' => 'NORMALIZED_SNAPSHOT',
        'write_mode' => 'UPSERT_CURRENT_STATE',
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => [
            $column('digital_asset_id', 'bigint', true, 'scope'),
            $column('external_resource_id', 'bigint', false, 'scope'),
            $column('account_id', 'text', false, 'identity'),
            ...$dimensions,
            ...$provenance(),
        ],
    ];
};

$metricColumns = [
    $column('spend', 'decimal', false, 'metric', '0'),
    $column('impressions', 'bigint', false, 'metric', 0),
    $column('clicks', 'bigint', false, 'metric', 0),
    $column('reach', 'bigint', true, 'metric'),
    $column('frequency', 'decimal', true, 'metric'),
    $column('inline_link_clicks', 'bigint', true, 'metric'),
    $column('outbound_clicks', 'bigint', true, 'metric'),
    $column('currency', 'char(3)', true, 'dimension'),
];

$physicalAdditions = [
    'meta_account_daily' => $dailyBase('meta_account_daily', $metricColumns, [
        'external_resource_id', 'account_id', 'reporting_date',
    ]),

    'meta_ad_snapshot' => $snapshotBase('meta_ad_snapshot', [
        $column('ad_id', 'text', false, 'identity'),
        $column('ad_name', 'text', true),
        $column('campaign_id', 'text', true),
        $column('adset_id', 'text', true),
        $column('creative_id', 'text', true),
        $column('status', 'text', true),
        $column('effective_status', 'text', true),
        $column('created_time', 'timestamptz', true),
        $column('updated_time', 'timestamptz', true),
    ], ['external_resource_id', 'account_id', 'ad_id']),

    'meta_adset_targeting_snapshot' => $snapshotBase('meta_adset_targeting_snapshot', [
        $column('adset_id', 'text', false, 'identity'),
        $column('campaign_id', 'text', true),
        $column('adset_name', 'text', true),
        $column('optimization_goal', 'text', true),
        $column('billing_event', 'text', true),
        $column('bid_strategy', 'text', true),
        $column('targeting', 'json', true, 'extension'),
        $column('promoted_object', 'json', true, 'extension'),
        $column('attribution_spec', 'json', true, 'extension'),
    ], ['external_resource_id', 'account_id', 'adset_id']),

    'meta_conversion_source_snapshot' => $snapshotBase('meta_conversion_source_snapshot', [
        $column('source_type', 'text', false, 'identity'),
        $column('source_id', 'text', false, 'identity'),
        $column('source_name', 'text', true),
        $column('event_type', 'text', true),
        $column('first_fired_time', 'timestamptz', true),
        $column('last_fired_time', 'timestamptz', true),
        $column('is_archived', 'boolean', true),
        $column('is_unavailable', 'boolean', true),
        $column('pixel_id', 'text', true),
        $column('rule', 'text', true),
    ], ['external_resource_id', 'account_id', 'source_type', 'source_id']),

    'meta_change_event' => $snapshotBase('meta_change_event', [
        $column('event_key', 'char(64)', false, 'identity'),
        $column('event_time', 'timestamptz', false, 'identity'),
        $column('event_type', 'text', true),
        $column('translated_event_type', 'text', true),
        $column('object_id', 'text', true),
        $column('object_name', 'text', true),
        $column('object_type', 'text', true),
        $column('actor_id', 'text', true),
        $column('actor_name', 'text', true),
        $column('application_id', 'text', true),
        $column('application_name', 'text', true),
    ], ['external_resource_id', 'account_id', 'event_key']),

    'meta_video_engagement_daily' => $dailyBase('meta_video_engagement_daily', [
        $column('ad_id', 'text', false, 'identity'),
        $column('metric_type', 'text', false, 'identity'),
        $column('action_type', 'text', false, 'identity'),
        $column('metric_value', 'decimal', false, 'metric', '0'),
        $column('currency', 'char(3)', true),
    ], ['external_resource_id', 'account_id', 'reporting_date', 'ad_id', 'metric_type', 'action_type']),

    'meta_analysis_breakdown_daily' => $dailyBase('meta_analysis_breakdown_daily', [
        $column('breakdown_type', 'text', false, 'identity'),
        $column('breakdown_key', 'text', false, 'identity'),
        $column('spend', 'decimal', false, 'metric', '0'),
        $column('impressions', 'bigint', false, 'metric', 0),
        $column('clicks', 'bigint', false, 'metric', 0),
        $column('reach', 'bigint', true, 'metric'),
        $column('currency', 'char(3)', true),
    ], ['external_resource_id', 'account_id', 'reporting_date', 'breakdown_type', 'breakdown_key']),

    'meta_hourly_daily' => $dailyBase('meta_hourly_daily', [
        $column('hour_bucket', 'text', false, 'identity'),
        $column('spend', 'decimal', false, 'metric', '0'),
        $column('impressions', 'bigint', false, 'metric', 0),
        $column('clicks', 'bigint', false, 'metric', 0),
        $column('reach', 'bigint', true, 'metric'),
        $column('currency', 'char(3)', true),
    ], ['external_resource_id', 'account_id', 'reporting_date', 'hour_bucket']),
];

$families = [
    'META_V2_RF_ACCOUNT_DAILY' => ['dataset' => 'meta_account_daily', 'kind' => 'insights', 'level' => 'account', 'history' => '1125d', 'volume' => 'LOW'],
    'META_V2_RF_CAMPAIGN_DAILY' => ['dataset' => 'meta_campaign_daily', 'kind' => 'insights', 'level' => 'campaign', 'history' => '1125d', 'volume' => 'MEDIUM'],
    'META_V2_RF_ADSET_DAILY' => ['dataset' => 'meta_adset_daily', 'kind' => 'insights', 'level' => 'adset', 'history' => '395d', 'volume' => 'HIGH'],
    'META_V2_RF_AD_DAILY' => ['dataset' => 'meta_ad_daily', 'kind' => 'insights', 'level' => 'ad', 'history' => '395d', 'volume' => 'VERY_HIGH'],
    'META_V2_RF_TYPED_ACTIONS' => ['dataset' => 'meta_typed_action_daily', 'kind' => 'actions', 'level' => 'ad', 'history' => '395d', 'volume' => 'VERY_HIGH'],
    'META_V2_RF_VIDEO_DAILY' => ['dataset' => 'meta_video_engagement_daily', 'kind' => 'video', 'level' => 'ad', 'history' => '395d', 'volume' => 'HIGH'],
    'META_V2_RF_BREAKDOWNS' => ['dataset' => 'meta_analysis_breakdown_daily', 'kind' => 'breakdowns', 'level' => 'account', 'history' => '395d', 'volume' => 'HIGH'],
    'META_V2_RF_HOURLY' => ['dataset' => 'meta_hourly_daily', 'kind' => 'hourly', 'level' => 'account', 'history' => '90d', 'volume' => 'HIGH'],
    'META_V2_RF_AD_SNAPSHOT' => ['dataset' => 'meta_ad_snapshot', 'kind' => 'ad_snapshot', 'level' => 'ad', 'history' => 'current', 'volume' => 'LOW'],
    'META_V2_RF_TARGETING_SNAPSHOT' => ['dataset' => 'meta_adset_targeting_snapshot', 'kind' => 'targeting_snapshot', 'level' => 'adset', 'history' => 'current', 'volume' => 'LOW'],
    'META_V2_RF_CONVERSION_SOURCES' => ['dataset' => 'meta_conversion_source_snapshot', 'kind' => 'conversion_sources', 'level' => 'account', 'history' => 'current', 'volume' => 'TINY'],
    'META_V2_RF_CHANGE_HISTORY' => ['dataset' => 'meta_change_event', 'kind' => 'change_history', 'level' => 'account', 'history' => '90d', 'volume' => 'MEDIUM'],
];

$newDatasetIds = array_keys($physicalAdditions);
$datasets = [];
$requestFamilies = [];
$requirements = [];

foreach ($families as $familyId => $def) {
    $datasetId = $def['dataset'];
    $isSnapshot = $def['history'] === 'current';
    $requirementId = str_replace('_RF_', '_', $familyId);

    if (in_array($datasetId, $newDatasetIds, true)) {
        $datasets[$datasetId] = [
            'id' => $datasetId,
            'provider_or_source' => 'META_ADS',
            'description' => 'Professional Meta Ads provider dataset: '.$datasetId,
            'storage_class' => $isSnapshot ? 'NORMALIZED_SNAPSHOT' : 'NORMALIZED_FACT',
            'grain' => $physicalAdditions[$datasetId]['grain'],
            'primary_dimensions' => [],
            'base_metrics' => [],
            'snapshot_or_timeseries' => $isSnapshot ? 'snapshot' : 'timeseries',
            'partition_candidate' => $isSnapshot ? null : 'reporting_date',
            'history_policy' => [
                'minimum_required' => $def['history'],
                'recommended_initial_backfill' => $def['history'],
                'decision_required' => false,
            ],
            'refresh_policy' => $isSnapshot
                ? ['type' => 'daily_or_on_demand', 'cadence' => 'daily']
                : [
                    'type' => 'incremental',
                    'cadence' => 'daily',
                    'late_data_recheck_days' => 7,
                    'weekly_reconciliation_days' => 35,
                ],
            'estimated_volume_class' => $def['volume'],
            'consumer_requirement_ids' => [$requirementId],
            'provenance' => 'PROVIDER_MEASURED',
            'status' => 'COLLECTION_READY',
        ];
    }

    $requestFamilies[] = [
        'id' => $familyId,
        'provider_or_source' => 'META_ADS',
        'capability_endpoint_resource' => 'Meta Marketing API /'.$def['level'],
        'requirement_ids' => [$requirementId],
        'dimensions_fields' => [],
        'metrics' => [],
        'segments_breakdowns' => [],
        'date_behavior' => $isSnapshot ? 'current snapshot' : 'ad account reporting timezone; daily time_increment',
        'pagination_streaming' => 'cursor pagination + bounded date slices',
        'sync_async_recommendation' => 'sync for bounded slices; existing legacy collector remains compatible with async insights',
        'volume_risk' => $def['volume'],
        'paid_call' => false,
        'compatibility_status' => 'META_MARKETING_API_V26_2026_08',
        'eligibility' => ['bound META_AD_ACCOUNT', 'ads_read permission'],
        'notes' => 'Read-only provider collection. Business Portfolio is not an analytical root.',
        'status' => 'COLLECTION_READY',
    ];

    $requirements[] = [
        'id' => $requirementId,
        'name' => $datasetId,
        'consumer' => [],
        'provider_or_source' => 'META_ADS',
        'source_class' => 'PROVIDER_MEASURED',
        'operating_mode' => 'CORE_BOUND_PROVIDER',
        'dataset' => $datasetId,
        'request_family' => $familyId,
        'dimensions' => [],
        'metrics' => [],
        'grain' => $physicalAdditions[$datasetId]['grain'] ?? [],
        'historical_depth' => [
            'minimum_required' => $def['history'],
            'recommended_initial_backfill' => $def['history'],
            'decision_required' => false,
        ],
        'refresh_cadence' => $isSnapshot
            ? ['type' => 'daily_or_on_demand', 'cadence' => 'daily']
            : [
                'type' => 'incremental',
                'cadence' => 'daily',
                'late_data_recheck_days' => 7,
                'weekly_reconciliation_days' => 35,
            ],
        'requirement_level' => 'REQUIRED',
        'storage_class' => $isSnapshot ? 'NORMALIZED_SNAPSHOT' : 'NORMALIZED_FACT',
        'formula' => null,
        'formula_ref' => null,
        'dependencies' => [],
        'provenance' => 'PROVIDER_MEASURED',
        'contract_version' => 1,
        'registry_version' => 1,
        'source_contract' => 'META_ADS_PROFESSIONAL_COLLECTION',
        'source_contract_version' => 2,
        'source_requirement_ids' => [$requirementId],
        'status' => 'COLLECTION_READY',
        'collection_readiness' => 'COLLECTION_READY',
        'timezone_policy' => 'meta_ad_account_timezone',
        'currency_policy' => $isSnapshot ? 'not_applicable' : 'provider_account_currency_no_fx',
        'eligibility' => ['bound ad account'],
        'additivity' => $isSnapshot ? 'NOT_APPLICABLE' : 'ADDITIVE_BASE_FACTS_ONLY_WITH_REACH_FREQUENCY_EXCEPTIONS',
        'missing_semantics' => 'NOT_COLLECTED_NEQ_ZERO',
        'notes' => 'Meta-direct fact. Meta recommendations and MOXDOP recommendations remain separate concerns.',
    ];
}

return [
    'overlay_id' => 'META_ADS_PROFESSIONAL_V2',
    'collector_policy' => [
        'core_history_days' => 1125,
        'warm_history_days' => 395,
        'hot_hourly_days' => 90,
        'daily_reconcile_days' => 7,
        'weekly_reconcile_days' => 35,
        'business_portfolio_is_analytical_root' => false,
        'ad_account_is_analytical_root' => true,
        'brand_allows_multiple_ad_accounts' => true,
    ],
    'families' => $families,
    'natural_key_overrides' => [],
    'columns_add' => [],
    'physical_additions' => $physicalAdditions,
    'registry_overlay' => [
        'overlay_id' => 'META_ADS_PROFESSIONAL_V2',
        'datasets' => array_values($datasets),
        'request_families' => $requestFamilies,
        'requirements' => $requirements,
    ],
];
