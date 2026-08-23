<?php

/**
 * Google Ads professional collection overlay.
 *
 * The frozen V1 registry/storage contracts stay immutable. This runtime overlay
 * adds provider-direct datasets that a professional agency workspace needs while
 * preserving the canonical Collection Engine + Data Pool architecture.
 *
 * Ratios (CTR, CPC, CPA, ROAS, CVR) are intentionally NOT stored here. They are
 * MOXDOP-derived from additive provider facts at read time.
 */

$column = static fn (string $name, string $type, bool $nullable = true, string $role = 'dimension'): array => [
    'name' => $name,
    'type' => $type,
    'nullable' => $nullable,
    'role' => $role,
];

$commonDailyMetrics = static function () use ($column): array {
    return [
        $column('impressions', 'bigint', false, 'metric'),
        $column('clicks', 'bigint', false, 'metric'),
        $column('interactions', 'bigint', false, 'metric'),
        $column('cost_micros', 'bigint', false, 'metric'),
        $column('cost_amount', 'decimal', false, 'metric'),
        $column('conversions', 'decimal', false, 'metric'),
        $column('conversions_value', 'decimal', false, 'metric'),
        $column('all_conversions', 'decimal', false, 'metric'),
        $column('all_conversions_value', 'decimal', false, 'metric'),
        $column('view_through_conversions', 'decimal', false, 'metric'),
        $column('currency', 'char(3)', false, 'dimension'),
    ];
};

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

$dailyPhysical = static function (string $table, array $dimensions, array $grain) use ($column, $commonDailyMetrics, $provenance): array {
    return [
        'table' => $table,
        'provider_or_source' => 'GOOGLE_ADS',
        'storage_class' => 'NORMALIZED_FACT',
        'write_mode' => 'UPSERT_DAILY_FACT',
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => [
            $column('digital_asset_id', 'bigint', true, 'scope'),
            $column('external_resource_id', 'bigint', false, 'scope'),
            $column('customer_id', 'text', false, 'identity'),
            $column('reporting_date', 'date', false, 'identity'),
            ...$dimensions,
            ...$commonDailyMetrics(),
            ...$provenance(),
        ],
    ];
};

$snapshotPhysical = static function (string $table, array $dimensions, array $grain, string $writeMode = 'UPSERT_CURRENT_STATE') use ($column, $provenance): array {
    return [
        'table' => $table,
        'provider_or_source' => 'GOOGLE_ADS',
        'storage_class' => 'NORMALIZED_SNAPSHOT',
        'write_mode' => $writeMode,
        'partition_strategy' => 'NONE',
        'partition_column' => null,
        'grain' => $grain,
        'natural_key' => $grain,
        'columns' => [
            $column('digital_asset_id', 'bigint', true, 'scope'),
            $column('external_resource_id', 'bigint', false, 'scope'),
            $column('customer_id', 'text', false, 'identity'),
            ...$dimensions,
            ...$provenance(),
        ],
    ];
};

$dailyMetricNames = [
    'impressions', 'clicks', 'interactions', 'cost_micros', 'conversions',
    'conversions_value', 'all_conversions', 'all_conversions_value', 'view_through_conversions',
];

$families = [
    'GADS_V2_RF_AD_GROUP_DAILY' => [
        'dataset' => 'google_ads_ad_group_daily', 'kind' => 'daily', 'resource' => 'ad_group', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'date', 'campaign_id', 'ad_group_id'], 'dimensions' => ['date', 'campaign_id', 'ad_group_id'],
    ],
    'GADS_V2_RF_AD_DAILY' => [
        'dataset' => 'google_ads_ad_daily', 'kind' => 'daily', 'resource' => 'ad_group_ad', 'volume' => 'HIGH',
        'grain' => ['customer_id', 'date', 'campaign_id', 'ad_group_id', 'ad_id'], 'dimensions' => ['date', 'campaign_id', 'ad_group_id', 'ad_id'],
    ],
    'GADS_V2_RF_DEVICE_DAILY' => [
        'dataset' => 'google_ads_device_daily', 'kind' => 'daily', 'resource' => 'customer + segments.device', 'volume' => 'LOW',
        'grain' => ['customer_id', 'date', 'device'], 'dimensions' => ['date', 'device'],
    ],
    'GADS_V2_RF_HOUR_DAILY' => [
        'dataset' => 'google_ads_hour_daily', 'kind' => 'daily', 'resource' => 'customer + segments.hour', 'volume' => 'LOW',
        'grain' => ['customer_id', 'date', 'day_of_week', 'hour'], 'dimensions' => ['date', 'day_of_week', 'hour'],
    ],
    'GADS_V2_RF_NETWORK_DAILY' => [
        'dataset' => 'google_ads_network_daily', 'kind' => 'daily', 'resource' => 'customer + segments.ad_network_type', 'volume' => 'LOW',
        'grain' => ['customer_id', 'date', 'ad_network_type'], 'dimensions' => ['date', 'ad_network_type'],
    ],
    'GADS_V2_RF_USER_LOCATION_DAILY' => [
        'dataset' => 'google_ads_user_location_daily', 'kind' => 'daily', 'resource' => 'user_location_view', 'volume' => 'LOW',
        'grain' => ['customer_id', 'date', 'country_criterion_id', 'targeting_location'], 'dimensions' => ['date', 'country_criterion_id', 'targeting_location'],
    ],
    'GADS_V2_RF_AGE_RANGE_DAILY' => [
        'dataset' => 'google_ads_age_range_daily', 'kind' => 'daily', 'resource' => 'age_range_view', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'date', 'ad_group_id', 'criterion_id'], 'dimensions' => ['date', 'campaign_id', 'ad_group_id', 'criterion_id'],
    ],
    'GADS_V2_RF_GENDER_DAILY' => [
        'dataset' => 'google_ads_gender_daily', 'kind' => 'daily', 'resource' => 'gender_view', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'date', 'ad_group_id', 'criterion_id'], 'dimensions' => ['date', 'campaign_id', 'ad_group_id', 'criterion_id'],
    ],
    'GADS_V2_RF_CAMPAIGN_AUDIENCE_DAILY' => [
        'dataset' => 'google_ads_campaign_audience_daily', 'kind' => 'daily', 'resource' => 'campaign_audience_view', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'date', 'campaign_id', 'criterion_id'], 'dimensions' => ['date', 'campaign_id', 'criterion_id'],
    ],
    'GADS_V2_RF_AD_GROUP_AUDIENCE_DAILY' => [
        'dataset' => 'google_ads_ad_group_audience_daily', 'kind' => 'daily', 'resource' => 'ad_group_audience_view', 'volume' => 'HIGH',
        'grain' => ['customer_id', 'date', 'campaign_id', 'ad_group_id', 'criterion_id'], 'dimensions' => ['date', 'campaign_id', 'ad_group_id', 'criterion_id'],
    ],
    'GADS_V2_RF_CAMPAIGN_NEGATIVE_KEYWORDS' => [
        'dataset' => 'google_ads_campaign_negative_keyword_snapshot', 'kind' => 'snapshot', 'resource' => 'campaign_criterion', 'volume' => 'LOW',
        'grain' => ['customer_id', 'campaign_id', 'criterion_id'], 'dimensions' => ['campaign_id', 'criterion_id'],
    ],
    'GADS_V2_RF_AD_GROUP_NEGATIVE_KEYWORDS' => [
        'dataset' => 'google_ads_ad_group_negative_keyword_snapshot', 'kind' => 'snapshot', 'resource' => 'ad_group_criterion', 'volume' => 'LOW',
        'grain' => ['customer_id', 'ad_group_id', 'criterion_id'], 'dimensions' => ['campaign_id', 'ad_group_id', 'criterion_id'],
    ],
    'GADS_V2_RF_BIDDING_STRATEGIES' => [
        'dataset' => 'google_ads_bidding_strategy_snapshot', 'kind' => 'snapshot', 'resource' => 'bidding_strategy', 'volume' => 'TINY',
        'grain' => ['customer_id', 'bidding_strategy_id'], 'dimensions' => ['bidding_strategy_id'],
    ],
    'GADS_V2_RF_PMAX_ASSET_GROUPS' => [
        'dataset' => 'google_ads_pmax_asset_group_snapshot', 'kind' => 'snapshot', 'resource' => 'asset_group', 'volume' => 'LOW',
        'grain' => ['customer_id', 'campaign_id', 'asset_group_id'], 'dimensions' => ['campaign_id', 'asset_group_id'],
    ],
    'GADS_V2_RF_PMAX_ASSET_DAILY' => [
        'dataset' => 'google_ads_pmax_asset_daily', 'kind' => 'daily', 'resource' => 'asset_group_asset', 'volume' => 'HIGH',
        'grain' => ['customer_id', 'date', 'campaign_id', 'asset_group_id', 'asset_id', 'field_type'], 'dimensions' => ['date', 'campaign_id', 'asset_group_id', 'asset_id', 'field_type'],
    ],
    'GADS_V2_RF_SHOPPING_PRODUCT_DAILY' => [
        'dataset' => 'google_ads_shopping_product_daily', 'kind' => 'daily', 'resource' => 'shopping_performance_view', 'volume' => 'VERY_HIGH',
        'grain' => ['customer_id', 'date', 'product_key'], 'dimensions' => ['date', 'product_key'],
    ],
    'GADS_V2_RF_VIDEO_DAILY' => [
        'dataset' => 'google_ads_video_daily', 'kind' => 'daily', 'resource' => 'video', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'date', 'video_id', 'ad_format_type'], 'dimensions' => ['date', 'video_id', 'ad_format_type'],
    ],
    'GADS_V2_RF_RECOMMENDATIONS' => [
        'dataset' => 'google_ads_recommendation_snapshot', 'kind' => 'observed_snapshot', 'resource' => 'recommendation', 'volume' => 'LOW',
        'grain' => ['customer_id', 'observed_date', 'recommendation_resource_name'], 'dimensions' => ['observed_date', 'recommendation_resource_name'],
    ],
    'GADS_V2_RF_CHANGE_EVENTS' => [
        'dataset' => 'google_ads_change_event', 'kind' => 'change_event', 'resource' => 'change_event', 'volume' => 'MEDIUM',
        'grain' => ['customer_id', 'event_key'], 'dimensions' => ['event_key', 'changed_at'],
        'history' => '30d',
    ],
];

$physicalAdditions = [
    'google_ads_ad_group_daily' => $dailyPhysical('google_ads_ad_group_daily', [
        $column('campaign_id', 'text', false), $column('ad_group_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id']),
    'google_ads_ad_daily' => $dailyPhysical('google_ads_ad_daily', [
        $column('campaign_id', 'text', false), $column('ad_group_id', 'text', false), $column('ad_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id', 'ad_id']),
    'google_ads_device_daily' => $dailyPhysical('google_ads_device_daily', [
        $column('device', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'device']),
    'google_ads_hour_daily' => $dailyPhysical('google_ads_hour_daily', [
        $column('day_of_week', 'text', false), $column('hour', 'integer', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'day_of_week', 'hour']),
    'google_ads_network_daily' => $dailyPhysical('google_ads_network_daily', [
        $column('ad_network_type', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'ad_network_type']),
    'google_ads_user_location_daily' => $dailyPhysical('google_ads_user_location_daily', [
        $column('country_criterion_id', 'text', false), $column('targeting_location', 'boolean', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'country_criterion_id', 'targeting_location']),
    'google_ads_age_range_daily' => $dailyPhysical('google_ads_age_range_daily', [
        $column('campaign_id', 'text', true), $column('ad_group_id', 'text', false), $column('criterion_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id']),
    'google_ads_gender_daily' => $dailyPhysical('google_ads_gender_daily', [
        $column('campaign_id', 'text', true), $column('ad_group_id', 'text', false), $column('criterion_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id']),
    'google_ads_campaign_audience_daily' => $dailyPhysical('google_ads_campaign_audience_daily', [
        $column('campaign_id', 'text', false), $column('criterion_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'criterion_id']),
    'google_ads_ad_group_audience_daily' => $dailyPhysical('google_ads_ad_group_audience_daily', [
        $column('campaign_id', 'text', false), $column('ad_group_id', 'text', false), $column('criterion_id', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id', 'criterion_id']),
    'google_ads_campaign_negative_keyword_snapshot' => $snapshotPhysical('google_ads_campaign_negative_keyword_snapshot', [
        $column('campaign_id', 'text', false), $column('criterion_id', 'text', false), $column('keyword_text', 'text', false),
        $column('match_type', 'text', true), $column('status', 'text', true),
    ], ['external_resource_id', 'customer_id', 'campaign_id', 'criterion_id']),
    'google_ads_ad_group_negative_keyword_snapshot' => $snapshotPhysical('google_ads_ad_group_negative_keyword_snapshot', [
        $column('campaign_id', 'text', true), $column('ad_group_id', 'text', false), $column('criterion_id', 'text', false),
        $column('keyword_text', 'text', false), $column('match_type', 'text', true), $column('status', 'text', true),
    ], ['external_resource_id', 'customer_id', 'ad_group_id', 'criterion_id']),
    'google_ads_bidding_strategy_snapshot' => $snapshotPhysical('google_ads_bidding_strategy_snapshot', [
        $column('bidding_strategy_id', 'text', false), $column('name', 'text', true), $column('strategy_type', 'text', true),
        $column('status', 'text', true), $column('campaign_count', 'bigint', true),
    ], ['external_resource_id', 'customer_id', 'bidding_strategy_id']),
    'google_ads_pmax_asset_group_snapshot' => $snapshotPhysical('google_ads_pmax_asset_group_snapshot', [
        $column('campaign_id', 'text', false), $column('asset_group_id', 'text', false), $column('name', 'text', true),
        $column('status', 'text', true),
    ], ['external_resource_id', 'customer_id', 'campaign_id', 'asset_group_id']),
    'google_ads_pmax_asset_daily' => $dailyPhysical('google_ads_pmax_asset_daily', [
        $column('campaign_id', 'text', false), $column('asset_group_id', 'text', false), $column('asset_id', 'text', false),
        $column('field_type', 'text', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'asset_group_id', 'asset_id', 'field_type']),
    'google_ads_shopping_product_daily' => $dailyPhysical('google_ads_shopping_product_daily', [
        $column('product_key', 'char(64)', false),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'product_key']),
    'google_ads_video_daily' => $dailyPhysical('google_ads_video_daily', [
        $column('video_id', 'text', false), $column('ad_format_type', 'text', false),
        $column('video_views', 'bigint', false, 'metric'), $column('video_quartile_p25_rate', 'decimal', true, 'metric'),
        $column('video_quartile_p50_rate', 'decimal', true, 'metric'), $column('video_quartile_p75_rate', 'decimal', true, 'metric'),
        $column('video_quartile_p100_rate', 'decimal', true, 'metric'),
    ], ['external_resource_id', 'customer_id', 'reporting_date', 'video_id', 'ad_format_type']),
    'google_ads_recommendation_snapshot' => $snapshotPhysical('google_ads_recommendation_snapshot', [
        $column('observed_date', 'date', false), $column('recommendation_resource_name', 'text', false),
        $column('recommendation_type', 'text', true), $column('campaign_resource_name', 'text', true),
    ], ['external_resource_id', 'customer_id', 'observed_date', 'recommendation_resource_name'], 'UPSERT_CURRENT_STATE'),
    'google_ads_change_event' => $snapshotPhysical('google_ads_change_event', [
        $column('event_key', 'char(64)', false), $column('changed_at', 'timestamptz', false),
        $column('change_resource_name', 'text', true), $column('change_resource_type', 'text', true),
        $column('operation', 'text', true), $column('client_type', 'text', true), $column('user_email', 'text', true),
    ], ['external_resource_id', 'customer_id', 'event_key'], 'UPSERT_CURRENT_STATE'),
];

$datasets = [];
$requestFamilies = [];
$requirements = [];

foreach ($families as $familyId => $def) {
    $datasetId = $def['dataset'];
    $isDaily = $def['kind'] === 'daily';
    $isChangeEvent = $def['kind'] === 'change_event';
    $history = $def['history'] ?? ($isDaily ? '180d' : 'current');
    $requirementId = str_replace('_RF_', '_', $familyId);

    $datasets[] = [
        'id' => $datasetId,
        'provider_or_source' => 'GOOGLE_ADS',
        'description' => 'Professional Google Ads provider dataset: '.$datasetId,
        'storage_class' => $isDaily ? 'NORMALIZED_FACT' : 'NORMALIZED_SNAPSHOT',
        'grain' => $def['grain'],
        'primary_dimensions' => $def['dimensions'],
        'base_metrics' => $isDaily ? $dailyMetricNames : [],
        'snapshot_or_timeseries' => $isDaily ? 'timeseries' : 'snapshot',
        'partition_candidate' => $isDaily ? 'date' : null,
        'history_policy' => [
            'minimum_required' => $history,
            'recommended_initial_backfill' => $history,
            'decision_required' => false,
        ],
        'refresh_policy' => $isDaily
            ? ['type' => 'incremental', 'cadence' => 'daily', 'late_data_recheck' => 'provider_dependent']
            : ['type' => 'on_demand_or_daily', 'cadence' => 'daily'],
        'estimated_volume_class' => $def['volume'],
        'consumer_requirement_ids' => [$requirementId],
        'cross_asset_keys' => [],
        'provenance' => 'PROVIDER_MEASURED',
        'completeness_limitations' => $isChangeEvent ? 'Google ChangeEvent is limited to the most recent 30 days and 10,000 rows per query.' : null,
        'status' => 'COLLECTION_READY',
    ];

    $requestFamilies[] = [
        'id' => $familyId,
        'provider_or_source' => 'GOOGLE_ADS',
        'capability_endpoint_resource' => $def['resource'],
        'requirement_ids' => [$requirementId],
        'dimensions_fields' => $def['dimensions'],
        'metrics' => $isDaily ? $dailyMetricNames : [],
        'segments_breakdowns' => $isDaily ? ['date'] : [],
        'date_behavior' => $isDaily ? 'customer TZ date' : ($isChangeEvent ? 'last 30 days only' : 'snapshot'),
        'pagination_streaming' => $isDaily ? 'SearchStream + bounded date slices' : 'Search paged',
        'sync_async_recommendation' => $isDaily ? 'SearchStream' : 'Search',
        'volume_risk' => $def['volume'],
        'cost_class' => null,
        'paid_call' => false,
        'compatibility_status' => 'VERIFIED_GOOGLE_ADS_API_V25_2026_08_23',
        'eligibility' => ['bound non-manager Google Ads customer', 'Google Ads OAuth scope', 'developer token'],
        'notes' => 'Read-only provider-direct collection. No automatic Google Ads mutations.',
        'status' => 'COLLECTION_READY',
    ];

    $requirements[] = [
        'id' => $requirementId,
        'name' => $datasetId,
        'consumer' => [],
        'provider_or_source' => 'GOOGLE_ADS',
        'source_class' => 'PROVIDER_MEASURED',
        'operating_mode' => 'CORE_BOUND_PROVIDER',
        'dataset' => $datasetId,
        'request_family' => $familyId,
        'dimensions' => $def['dimensions'],
        'metrics' => $isDaily ? $dailyMetricNames : [],
        'grain' => $def['grain'],
        'historical_depth' => [
            'minimum_required' => $history,
            'recommended_initial_backfill' => $history,
            'decision_required' => false,
        ],
        'refresh_cadence' => $isDaily
            ? ['type' => 'incremental', 'cadence' => 'daily', 'late_data_recheck' => 'provider_dependent']
            : ['type' => 'on_demand_or_daily', 'cadence' => 'daily'],
        'requirement_level' => 'REQUIRED',
        'storage_class' => $isDaily ? 'NORMALIZED_FACT' : 'NORMALIZED_SNAPSHOT',
        'formula' => null,
        'formula_ref' => null,
        'dependencies' => [],
        'provenance' => 'PROVIDER_MEASURED',
        'contract_version' => 1,
        'registry_version' => 1,
        'source_contract' => 'GOOGLE_ADS_PROFESSIONAL_COLLECTION',
        'source_contract_version' => 2,
        'source_requirement_ids' => [$requirementId],
        'status' => 'COLLECTION_READY',
        'collection_readiness' => 'COLLECTION_READY',
        'timezone_policy' => 'google_ads_customer_time_zone',
        'currency_policy' => $isDaily ? 'provider_account_currency_no_fx' : 'not_applicable',
        'market' => null,
        'language' => null,
        'eligibility' => ['bound customer'],
        'cache_policy' => null,
        'cost_class' => null,
        'additivity' => $isDaily ? 'ADDITIVE_BASE_FACTS_ONLY' : 'NOT_APPLICABLE',
        'missing_semantics' => 'NOT_COLLECTED_NEQ_ZERO',
        'completeness_limitations' => $isChangeEvent ? '30 day provider retention; 10k rows/query' : null,
        'cross_asset_keys' => [],
        'notes' => 'Google-direct provider fact. Interpretation and rates belong to MOXDOP derivations.',
    ];
}

return [
    'overlay_id' => 'GOOGLE_ADS_PROFESSIONAL_V2',

    'natural_key_overrides' => [],
    'columns_add' => [],
    'physical_additions' => $physicalAdditions,

    'registry_overlay' => [
        'overlay_id' => 'GOOGLE_ADS_PROFESSIONAL_V2',
        'datasets' => $datasets,
        'request_families' => $requestFamilies,
        'requirements' => $requirements,
    ],

    'families' => $families,

    'date_slice_days' => [
        'default' => 7,
        'HIGH' => 1,
        'VERY_HIGH' => 1,
        'MEDIUM' => 3,
        'LOW' => 7,
        'TINY' => 30,
    ],
];
