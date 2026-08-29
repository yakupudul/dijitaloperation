<?php

$historical = static function (string $datasetId, array $grain, int $maxSpanDays = 35, int $reprocessDays = 7, ?array $weekly = null): array {
    return [
        'dataset_id' => $datasetId,
        'provider_or_source' => 'META_ADS',
        'policy_version' => 1,
        'collection_mode' => 'HISTORICAL_INCREMENTAL',
        'incremental_applicable' => true,
        'non_applicable_reason' => null,
        'reporting_grain' => $grain,
        'timezone_source' => 'meta_ad_account_timezone',
        'current_period_collectable' => false,
        'safe_collection_lag_days' => 1,
        'freshness_sla_hours' => 30,
        'expected_refresh_cadence' => 'daily',
        'late_data_reprocessing' => [
            'strategy' => 'fixed_recent_reporting_window',
            'window_days' => $reprocessDays,
            'window_source' => 'MOXDOP Meta Ads attribution reconciliation policy',
            'overlap_existing_coverage_allowed' => true,
        ],
        'weekly_reconciliation' => $weekly ?? [
            'enabled' => true,
            'window_days' => 35,
            'iso_weekday' => 1,
        ],
        'catch_up_policy' => 'coverage_gap_to_collectable_end',
        'max_bounded_incremental_span_days' => $maxSpanDays,
        'snapshot_policy' => null,
        'provider_history_limitation_ref' => 'meta_marketing_api_history_policy',
        'integrity_dependency' => [
            'integrity_registry_id' => 'MOXDOP_DATA_INTEGRITY_REGISTRY',
            'blocks_trusted_fresh_on_migration_blocking_fail' => true,
        ],
        'contract_refresh_policy' => [
            'type' => 'incremental',
            'cadence' => 'daily',
            'late_data_recheck_days' => $reprocessDays,
            'weekly_reconciliation_days' => (int) (($weekly['window_days'] ?? null) ?: 35),
        ],
        'contract_history_policy' => null,
        'period_non_additive_notes' => 'Reach and frequency are non-additive; never sum them across dimensions or accounts.',
        'policy_source' => [
            'data_contract_dataset_id' => $datasetId,
            'integrity_profile' => true,
            'provider_documentation_verified_at' => '2026-08-26',
            'provider_documentation' => [
                'notes' => 'MOXDOP re-reads recent Meta reporting dates because attributed outcomes can settle after the first daily pull.',
            ],
        ],
    ];
};

$snapshot = static function (string $datasetId, array $grain, int $slaHours = 30): array {
    return [
        'dataset_id' => $datasetId,
        'provider_or_source' => 'META_ADS',
        'policy_version' => 1,
        'collection_mode' => 'CURRENT_SNAPSHOT',
        'incremental_applicable' => true,
        'non_applicable_reason' => null,
        'reporting_grain' => $grain,
        'timezone_source' => 'meta_ad_account_timezone',
        'current_period_collectable' => false,
        'safe_collection_lag_days' => null,
        'freshness_sla_hours' => $slaHours,
        'expected_refresh_cadence' => 'daily',
        'late_data_reprocessing' => [
            'strategy' => 'replace_current_snapshot',
            'window_days' => null,
            'window_source' => 'not_applicable',
            'overlap_existing_coverage_allowed' => false,
        ],
        'catch_up_policy' => 'snapshot_refresh_if_stale',
        'max_bounded_incremental_span_days' => null,
        'snapshot_policy' => [
            'freshness_basis' => 'last_successful_collection_at',
            'freshness_sla_hours' => $slaHours,
            'historical_watermark_applicable' => false,
            'reprocessing_applicable' => false,
        ],
        'provider_history_limitation_ref' => null,
        'integrity_dependency' => [
            'integrity_registry_id' => 'MOXDOP_DATA_INTEGRITY_REGISTRY',
            'blocks_trusted_fresh_on_migration_blocking_fail' => true,
        ],
        'contract_refresh_policy' => ['type' => 'daily_or_on_demand', 'cadence' => 'daily'],
        'contract_history_policy' => ['minimum_required' => 'current', 'recommended_initial_backfill' => 'current', 'decision_required' => false],
        'period_non_additive_notes' => null,
        'policy_source' => [
            'data_contract_dataset_id' => $datasetId,
            'integrity_profile' => true,
            'provider_documentation_verified_at' => '2026-08-26',
        ],
    ];
};

return [
    'overlay_id' => 'META_ADS_FRESHNESS_V2',
    'dataset_policies' => [
        $historical('meta_account_daily', ['account_id', 'date'], 35, 7),
        $historical('meta_campaign_daily', ['account_id', 'date', 'campaign_id'], 35, 7),
        $historical('meta_adset_daily', ['account_id', 'date', 'adset_id'], 35, 7),
        $historical('meta_ad_daily', ['account_id', 'date', 'ad_id'], 35, 7),
        $historical('meta_typed_action_daily', ['account_id', 'date', 'entity_level', 'entity_id', 'action_type'], 35, 7),
        $historical('meta_video_engagement_daily', ['account_id', 'date', 'ad_id', 'metric_type', 'action_type'], 35, 7),
        $historical('meta_analysis_breakdown_daily', ['account_id', 'date', 'breakdown_type', 'breakdown_key'], 35, 7),
        $historical('meta_hourly_daily', ['account_id', 'date', 'hour_bucket'], 14, 7, [
            'enabled' => false,
            'window_days' => 0,
            'iso_weekday' => 1,
        ]),
        $snapshot('meta_ad_snapshot', ['account_id', 'ad_id']),
        $snapshot('meta_adset_targeting_snapshot', ['account_id', 'adset_id']),
        $snapshot('meta_conversion_source_snapshot', ['account_id', 'source_type', 'source_id']),
        $snapshot('meta_change_event', ['account_id', 'event_key']),
    ],
];
