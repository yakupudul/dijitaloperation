<?php

/*
 * Compact fact storage (PostgreSQL). High-cardinality daily facts keep their logical table name as a VIEW with the
 * same columns, while rows live in a narrow table: integer dictionary ids instead of repeated text (site, search
 * type, query, page, country, device…), int4 metrics, position as real, and one primary key. Provenance constants
 * (contract version, fingerprint, time zone, created/updated) are not stored per row.
 *
 * A Search Console query×page row went from ~1 KB (text columns, ~10 constant columns, 6 indexes, two of them
 * repeating the texts) to ~100 bytes. Readers are unchanged; PostgresWarehouseWriter writes compact rows once the
 * logical table has been converted (`php artisan moxdop:db:compact --execute`).
 *
 * logical table => [fact table, [dimension column => dictionary kind, …] in key order]
 */
return [
    'kinds' => [
        'site' => 1, 'search_type' => 2, 'query' => 3, 'page' => 4, 'country' => 5, 'device' => 6, 'searchAppearance' => 7,
    ],

    'tables' => [
        'gsc_query_daily' => ['fact' => 'gsc_f_query', 'dims' => ['query']],
        'gsc_page_daily' => ['fact' => 'gsc_f_page', 'dims' => ['page']],
        'gsc_query_page_daily' => ['fact' => 'gsc_f_query_page', 'dims' => ['query', 'page']],
        'gsc_query_country_daily' => ['fact' => 'gsc_f_query_country', 'dims' => ['query', 'country']],
        'gsc_query_device_daily' => ['fact' => 'gsc_f_query_device', 'dims' => ['query', 'device']],
        'gsc_page_country_daily' => ['fact' => 'gsc_f_page_country', 'dims' => ['page', 'country']],
        'gsc_page_device_daily' => ['fact' => 'gsc_f_page_device', 'dims' => ['page', 'device']],
        'gsc_search_appearance_page_daily' => ['fact' => 'gsc_f_appearance_page', 'dims' => ['searchAppearance', 'page']],
    ],

    /*
     * Generic compact storage (GA4, Meta, Google Ads daily facts): logical table => fact table. The layout is read
     * from the live table when it is converted (GenericCompactStore): text / json columns become dictionary ids,
     * row fingerprints and duplicate timestamps are dropped, and only the contract natural key is indexed.
     */
    'generic' => [
        'ga4_event_channel_daily' => 'ga4_f_event_channel',
        'ga4_event_campaign_daily' => 'ga4_f_event_campaign',
        'ga4_event_landing_daily' => 'ga4_f_event_landing',
        'ga4_event_daily' => 'ga4_f_event',
        'ga4_landing_page_daily' => 'ga4_f_landing_page',
        'ga4_landing_channel_daily' => 'ga4_f_landing_channel',
        'ga4_source_medium_daily' => 'ga4_f_source_medium',
        'ga4_campaign_daily' => 'ga4_f_campaign',
        'ga4_page_content_daily' => 'ga4_f_page_content',
        'ga4_geo_city_daily' => 'ga4_f_geo_city',
        'ga4_technology_daily' => 'ga4_f_technology',
        'meta_ad_daily' => 'meta_f_ad',
        'meta_adset_daily' => 'meta_f_adset',
        'meta_delivery_breakdown_daily' => 'meta_f_delivery_breakdown',
        'meta_typed_action_daily' => 'meta_f_typed_action',
        'meta_analysis_breakdown_daily' => 'meta_f_analysis_breakdown',
        'google_ads_search_term_daily' => 'gads_f_search_term',
        'google_ads_keyword_daily' => 'gads_f_keyword',
        'google_ads_ad_daily' => 'gads_f_ad',
        'google_ads_landing_page_daily' => 'gads_f_landing_page',
        'google_ads_geo_daily' => 'gads_f_geo',
        'google_ads_pmax_asset_daily' => 'gads_f_pmax_asset',
        'google_ads_shopping_product_daily' => 'gads_f_shopping_product',
    ],

    /* Constant values the view returns for columns no longer stored per row. */
    'source_timezone' => 'America/Los_Angeles',
];
