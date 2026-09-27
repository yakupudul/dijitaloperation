<?php

/*
|--------------------------------------------------------------------------
| Activity-aware collection (operator decision 2026-09)
|--------------------------------------------------------------------------
|
| Collection follows real account activity, like the website crawl skips
| unchanged pages. Each bound account/property gets a tier computed from its
| stored facts (Ads: spend, GA4: sessions, Search Console: clicks):
|
|   active  — activity in the last `active_days` days → full daily collection
|   idle    — no activity for active_days..dormant_days → weekly light set
|   dormant — no activity for dormant_days+ days (or operator pause) → weekly
|             cheap check of account-level totals for the last `check_days`
|
| Structure datasets (campaign / ad group / ad / creative snapshots) are
| collected only when the provider reports a change, at most weekly otherwise.
|
*/

return [

    'enabled' => (bool) env('MOXDOP_COLLECTION_ACTIVITY_ENABLED', true),

    'active_days' => 7,

    'dormant_days' => 30,

    /* Idle / dormant accounts are collected at most once per this many days. */
    'light_interval_days' => 7,

    /* Dormant cheap check window (account-level daily totals only). */
    'check_days' => 7,

    /* Structure snapshots are re-collected at least this often even without a reported change. */
    'structure_safety_net_days' => 7,

    /* Google Ads daily window is short; a deeper restatement for late conversions runs at most weekly. */
    'google_ads_deep_restatement_days' => 30,

    'deep_restatement_interval_days' => 7,

    /* Initial load when an account is first bound (months; Search Console keeps 16, Meta 37). */
    'initial_backfill_months' => 13,

    /* Planning-pass log retention for the savings metric. */
    'pass_log_retention_days' => 30,

    /*
     * Signal per provider: fact table + metric that proves activity, and the provider reporting lag in days
     * (the latest day that can hold final data is today - lag).
     */
    'signals' => [
        'GOOGLE_ADS' => ['table' => 'google_ads_account_daily', 'metric' => 'cost_amount', 'lag_days' => 1],
        'META_ADS' => ['table' => 'meta_account_daily', 'metric' => 'spend', 'lag_days' => 1],
        'GA4' => ['table' => 'ga4_property_daily', 'metric' => 'sessions', 'lag_days' => 1],
        'SEARCH_CONSOLE' => ['table' => 'gsc_property_daily', 'metric' => 'clicks', 'lag_days' => 3],
    ],

    /* Resource type (core_external_resources.resource_type / binding capability) → provider. */
    'resource_types' => [
        'google_ads' => 'GOOGLE_ADS',
        'meta_ads' => 'META_ADS',
        'ga4' => 'GA4',
        'search_console' => 'SEARCH_CONSOLE',
    ],

    /*
     * Light set: account/property-level daily totals — enough to notice activity resuming.
     * Everything else (search terms, geo, device, hour, placements, creatives, landing pages…) is skipped.
     */
    'light_families' => [
        'GOOGLE_ADS' => ['GADS_CENTRAL_RF_ACCOUNT_DAILY'],
        'META_ADS' => ['META_V2_RF_ACCOUNT_DAILY'],
        'GA4' => ['GA4_RF_PROPERTY_DAILY'],
        'SEARCH_CONSOLE' => ['GSC_RF_PROPERTY_DAILY'],
    ],

    /* Structure snapshots gated on provider change signals. */
    'structure_families' => [
        'GOOGLE_ADS' => [
            'GADS_CENTRAL_RF_ENTITY_SNAPSHOT',
            'GADS_CENTRAL_RF_CAMPAIGN_NEGATIVE_KEYWORDS',
            'GADS_CENTRAL_RF_AD_GROUP_NEGATIVE_KEYWORDS',
            'GADS_CENTRAL_RF_BIDDING_STRATEGIES',
            'GADS_CENTRAL_RF_PMAX_ASSET_GROUPS',
        ],
        'META_ADS' => [
            'RF_META_ENTITY_SNAPSHOT',
            'META_V2_RF_AD_SNAPSHOT',
            'META_V2_RF_TARGETING_SNAPSHOT',
        ],
    ],
];
