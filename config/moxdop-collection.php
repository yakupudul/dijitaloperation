<?php

$dbWorkerAuthoritative = (bool) env('COLLECTION_DB_WORKER_AUTHORITATIVE', true);

return [

    /*
    |--------------------------------------------------------------------------
    | Data Contract Registry
    |--------------------------------------------------------------------------
    */

    'registry_path' => env(
        'MOXDOP_DATA_CONTRACT_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json')
    ),

    'registry_id' => 'MOXDOP_DATA_CONTRACT_REGISTRY',

    'supported_registry_versions' => [1],

    /*
     * Frozen V1 registry + explicit provider amendments. Order is stable and
     * deterministic; later overlays may intentionally replace rows by id.
     */
    'registry_overlays' => [
        'moxdop-gbp-central.registry_overlay',
        'moxdop-google-ads-central.registry_overlay',
        'moxdop-google-ads-history.registry_overlay',
        'moxdop-meta-ads-central.registry_overlay',
        'moxdop-meta-ads-legacy-retirement.registry_overlay',
        'moxdop-website-intelligence.registry_overlay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection execution control plane
    |--------------------------------------------------------------------------
    |
    | CollectionDatasetRun rows in PostgreSQL are the durable source of truth.
    | Dedicated Supervisor workers execute eligible rows directly from DB state.
    | When DB workers are authoritative, queue dispatch calls intentionally go to
    | Laravel's null sink so Redis/Horizon cannot create duplicate attempts or
    | provider quota bursts. Set COLLECTION_DB_WORKER_AUTHORITATIVE=false only on
    | a deployment that intentionally uses the legacy queue-driven execution path.
    |
    */

    'db_worker_authoritative' => $dbWorkerAuthoritative,

    'queue_connection' => $dbWorkerAuthoritative
        ? 'null'
        : env('COLLECTION_QUEUE_CONNECTION', 'redis'),

    'queue' => env('COLLECTION_QUEUE', 'collection'),

    'job_timeout_seconds' => (int) env('COLLECTION_JOB_TIMEOUT', 300),

    'job_tries' => (int) env('COLLECTION_JOB_TRIES', 3),

    /*
     * Legacy dispatch claims remain leases for compatibility with older Redis
     * deliveries. DB workers do not depend on these claims; execution locks are
     * still the final duplicate-execution guard.
     */
    'queue_dispatch_claim_lease_seconds' => (int) env('COLLECTION_DISPATCH_CLAIM_LEASE', 120),

    /*
    |--------------------------------------------------------------------------
    | Default retry policy (provider-specific overrides later)
    |--------------------------------------------------------------------------
    */

    'default_max_attempts' => (int) env('COLLECTION_DEFAULT_MAX_ATTEMPTS', 3),

    'default_backoff_seconds' => [30, 90, 180],

    'stale_running_seconds' => (int) env('COLLECTION_STALE_RUNNING_SECONDS', 1800),

    /*
    |--------------------------------------------------------------------------
    | Fail closed when the configured dispatch sink cannot be resolved
    |--------------------------------------------------------------------------
    */

    'require_queue_connection' => (bool) env('COLLECTION_REQUIRE_QUEUE', true),

    /*
    |--------------------------------------------------------------------------
    | MoxDOP v2 dataset catalogue (Faz 1 — Toplama) — the single truth
    |--------------------------------------------------------------------------
    |
    | Only these datasets are collected, for every discovered account (bound or
    | not). Every collector filters its request families through this list
    | (App\Support\Collection\CollectionDatasetCatalog); a dataset that is not
    | listed is never planned. `on_demand` datasets are fetched only when the
    | operator asks (never scheduled).
    |
    */

    'datasets' => [
        'SEARCH_CONSOLE' => [
            'gsc_site_metadata',           // permission level / active search types (no fact table)
            'gsc_property_daily',          // totals
            'gsc_query_page_daily',        // query × page daily (16 months)
            'gsc_sitemap_snapshot',        // sitemap status
        ],
        'GA4' => [
            'ga4_property_metadata',       // time zone, currency, streams
            'ga4_property_daily',          // totals
            'ga4_landing_source_daily',    // landing page × session source / medium (+ key events)
            'ga4_key_event_daily',         // key events
        ],
        'GOOGLE_BUSINESS_PROFILE' => [
            'gbp_location',                // profile: NAP, categories, description, hours
            'gbp_attributes',
            'gbp_services',
            'gbp_reviews',
            'gbp_posts',
            'gbp_performance_daily',
            'gbp_search_keywords_monthly',
            'gbp_media',                   // media dates
        ],
        'GOOGLE_ADS' => [
            'google_ads_account_monthly_history',            // activity history (plans the initial import)
            'google_ads_account_snapshot',                   // account + campaign (bidding strategy) + budget + ad group + ad (assets, policy)
            'google_ads_bidding_strategy_snapshot',
            'google_ads_campaign_negative_keyword_snapshot', // keyword snapshots (negatives; conflict check)
            'google_ads_ad_group_negative_keyword_snapshot',
            'google_ads_conversion_action_snapshot',
            'google_ads_account_daily',
            'google_ads_campaign_daily',
            'google_ads_ad_group_daily',
            'google_ads_keyword_daily',
            'google_ads_search_term_daily',
            'google_ads_ad_daily',
            'google_ads_geo_daily',
        ],
        'META_ADS' => [
            'meta_ad_account_snapshot',
            'meta_campaign_snapshot',
            'meta_adset_snapshot',                 // + attribution setting per fetch
            'meta_adset_targeting_snapshot',       // ad set targeting (region / language)
            'meta_ad_snapshot',
            'meta_creative_snapshot',              // + lead form id per creative
            'meta_account_daily',
            'meta_campaign_daily',
            'meta_adset_daily',
            'meta_ad_daily',
            'meta_typed_action_daily',             // results / conversions of the ad daily rows
            'meta_video_engagement_daily',
            'meta_analysis_breakdown_daily',       // region breakdown only
            'meta_conversion_source_snapshot',     // pixel / dataset status
        ],
        'DATAFORSEO' => [
            'serp_results',                // top-10, 30-day cache
            'query_volumes',               // search volume, 90-day cache
        ],
    ],

    'on_demand' => [
        'SEARCH_CONSOLE' => ['gsc_url_inspection_snapshot'],
    ],

    /* Meta analysis breakdowns: region only. */
    'meta_breakdowns' => ['region'],

];
