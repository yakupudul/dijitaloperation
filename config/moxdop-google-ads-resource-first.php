<?php

/**
 * Existing Google Ads core tables were originally keyed by DigitalAsset.
 * Central ingestion has no DigitalAsset yet, so provider identity is the stable
 * CoreExternalResource. These natural-key overrides make the Data Pool reusable
 * before and after a DigitalAsset binding is created.
 */
return [
    'natural_key_overrides' => [
        'google_ads_account_snapshot' => ['external_resource_id', 'customer_id'],
        'google_ads_account_daily' => ['external_resource_id', 'customer_id', 'reporting_date'],
        'google_ads_campaign_snapshot' => ['external_resource_id', 'customer_id', 'campaign_id'],
        'google_ads_campaign_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id'],
        'google_ads_ad_group_snapshot' => ['external_resource_id', 'customer_id', 'ad_group_id'],
        'google_ads_ad_snapshot' => ['external_resource_id', 'customer_id', 'ad_id'],
        'google_ads_keyword_snapshot' => ['external_resource_id', 'customer_id', 'ad_group_id', 'criterion_id'],
        'google_ads_keyword_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id'],
        'google_ads_search_term_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'search_term'],
        'google_ads_landing_page_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'landing_page'],
        'google_ads_conversion_action_snapshot' => ['external_resource_id', 'customer_id', 'conversion_action_id'],
        'google_ads_conversion_action_daily' => ['external_resource_id', 'customer_id', 'reporting_date', 'conversion_action_id'],
        'google_ads_campaign_budget_snapshot' => ['external_resource_id', 'customer_id', 'budget_id'],
        'google_ads_asset_coverage_snapshot' => ['external_resource_id', 'customer_id', 'asset_id'],
    ],
    'columns_add' => [],
    'physical_additions' => [],
];
