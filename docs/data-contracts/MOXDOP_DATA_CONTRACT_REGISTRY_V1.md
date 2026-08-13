# MOXDOP DATA CONTRACT REGISTRY V1

| Field | Value |
| --- | --- |
| Registry ID | `MOXDOP_DATA_CONTRACT_REGISTRY` |
| Version | `1` |
| Status | **FROZEN_FOR_COLLECTION_IMPLEMENTATION** |
| Created | 2026-08-13 |
| Source base commit | `99e4c375fc013cf6acdce43c69e5f388973b4e3d` |
| Freeze | `panel-design-freeze-v1` (`80ebef56195fa7ba04fde8c60c74959d4ab990fa`) |
| Canonical JSON | `docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json` |
| JSON Schema | `docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.schema.json` |

## 1. Purpose

Unify the six frozen V1 source data contracts into one deterministic, machine-readable registry that tells future collectors **exactly** what MoxDOP needs — so collectors never invent provider fields, grains, history, or semantics.

## 2. Canonical Status

**FROZEN_FOR_COLLECTION_IMPLEMENTATION**

Blocking product decisions that only affect presentation formulas or control-plane windows are recorded under Decisions as **NON_BLOCKING** for base-fact collection. Individual requirements may still be `DECISION_REQUIRED` / `DEFERRED` / `DEMO_ONLY`.

Base provider facts required by the freeze are collection-defined. Collectors implement READY requirements; they must not invent replacements for UNAVAILABLE/DEMO_ONLY concepts.

## 3. Source Contracts

| Contract | File | Commit | Included |
| --- | --- | --- | --- |
| `GA4_DATA_CONTRACT` | `docs/data-contracts/GA4_DATA_CONTRACT_V1.md` | `efe9028` | True |
| `SEARCH_CONSOLE_DATA_CONTRACT` | `docs/data-contracts/SEARCH_CONSOLE_DATA_CONTRACT_V1.md` | `d2be8c4` | True |
| `GOOGLE_ADS_DATA_CONTRACT` | `docs/data-contracts/GOOGLE_ADS_DATA_CONTRACT_V1.md` | `1527845` | True |
| `META_ADS_DATA_CONTRACT` | `docs/data-contracts/META_ADS_DATA_CONTRACT_V1.md` | `7f774c9` | True |
| `WEBSITE_DATA_CONTRACT` | `docs/data-contracts/WEBSITE_DATA_CONTRACT_V1.md` | `23f9f26` | True |
| `DATAFORSEO_DATA_CONTRACT` | `docs/data-contracts/DATAFORSEO_DATA_CONTRACT_V1.md` | `99e4c37` | True |

## 4. Architecture Principles

- Missing ≠ zero
- Stale ≠ current
- Estimated ≠ measured
- Derived ≠ provider fact
- Configured ≠ observed
- Observed ≠ Business Outcome
- Provider Result ≠ Business Outcome
- Top-N presentation ≠ collection limit
- Connected Integration ≠ fresh data
- Discovered External Resource ≠ bound Digital Asset
- No magic scores
- No hidden paid DataForSEO requests
- No automatic provider writes
- Meta Results must be typed
- DataForSEO is PAID_EXTERNAL_ENRICHMENT not account ingestion
- WordPress ≠ Website Digital Asset
- Domain/Hosting are not standalone Digital Assets

## 5. Registry Structure

Top-level collections: `metadata`, `enums`, `source_contracts`, `sources`, `requirements`, `datasets`, `request_families`, `dependencies`, `aliases`, `decisions`, `unsupported`, `formulas_handoff`, `traceability`, `semantic_boundaries`, `validation`.

JSON is canonical. This Markdown explains it.

## 6. Requirement ID Rules

{
  "case": "UPPER_SNAKE_CASE",
  "immutable_after_freeze": true,
  "no_reuse_for_different_semantics": true,
  "breaking_change_requires": "V2_or_amendment"
}

## 7. Provider / Source Taxonomy

| ID | Operating mode | Account ingestion | Digital Asset |
| --- | --- | --- | --- |
| `GA4` | `CORE_BOUND_PROVIDER` | True | True |
| `SEARCH_CONSOLE` | `CORE_BOUND_PROVIDER` | True | False |
| `GOOGLE_ADS` | `CORE_BOUND_PROVIDER` | True | True |
| `META_ADS` | `CORE_BOUND_PROVIDER` | True | True |
| `WEBSITE_DIRECT` | `ASSET_CAPABILITY` | False | False |
| `WORDPRESS_SITE_CONNECTOR` | `ASSET_CAPABILITY` | False | False |
| `PAGESPEED_TECHNICAL` | `TECHNICAL_CAPABILITY` | False | False |
| `DOMAIN_DNS_TLS` | `TECHNICAL_CAPABILITY` | False | False |
| `DATAFORSEO` | `PAID_EXTERNAL_ENRICHMENT` | False | False |
| `MOXDOP` | `DERIVATION` | False | False |
| `OPERATIONS_DOMAIN` | `DOMAIN_DATA` | False | False |
| `OPERATOR_MAINTAINED` | `OPERATOR_CONFIG` | False | False |

## 8. Operating Modes

- CORE_BOUND_PROVIDER: GA4, GSC, Google Ads, Meta Ads
- ASSET_CAPABILITY: Website Direct, WordPress connector
- TECHNICAL_CAPABILITY: PageSpeed, DNS/TLS
- PAID_EXTERNAL_ENRICHMENT: DataForSEO
- DOMAIN_DATA / DERIVATION / OPERATOR_CONFIG: Operations, MoxDOP, operator

## 9. Storage Classes

`CONTROL_PLANE`, `RAW_RESPONSE`, `NORMALIZED_FACT`, `NORMALIZED_SNAPSHOT`, `NORMALIZED_EDGE`, `EXTERNAL_ENRICHMENT`, `MAPPING`, `DERIVED_RUNTIME`, `OPERATIONS_DOMAIN`, `NONE`

## 10. Requirement Levels

`REQUIRED` · `OPTIONAL` · `CONDITIONAL` (must declare eligibility)

## 11. Provenance Taxonomy

`PROVIDER_MEASURED`, `PROVIDER_METADATA`, `PROVIDER_CONFIGURATION`, `PROVIDER_ESTIMATED`, `PROVIDER_OBSERVED`, `DIRECT_WEBSITE_OBSERVATION`, `CMS_CONFIGURED`, `MOXDOP_DERIVED`, `MOXDOP_MAPPING`, `MOXDOP_CLASSIFICATION`, `CROSS_ASSET`, `OPERATOR_MAINTAINED`, `OPERATIONS_DOMAIN`, `UNAVAILABLE`, `DEMO_ONLY`

## 12. Missing / Zero / Unavailable Semantics

NOT_COLLECTED, NOT_CONFIGURED, UNAVAILABLE, STALE, PARTIAL, PROVIDER_OMITTED are distinguishable from ZERO

## 13. Timezone Contract

| Source | Policy |
| --- | --- |
| GA4 | property timezone |
| GSC | Search Analytics reporting date / lag semantics |
| Google Ads | `customer.time_zone` |
| Meta | Ad Account timezone |
| Website | observation timestamp |
| DataForSEO | retrieved_at + market context |

## 14. Currency Contract

Monetary facts carry provider account currency. **No cross-currency portfolio aggregation** without future FX normalization.

## 15. Market / Language Contract

DataForSEO requirements require Website SEO market + language. **No silent US/English default.** Multi-market = separate enrichment contexts.

## 16. Canonical Dataset Registry

Total datasets: **66**

| Dataset | Source | Grain | Storage | History min | Refresh | Reqs | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `ga4_property_metadata` | `GA4` | property_id | `NORMALIZED_SNAPSHOT` | current | daily | 4 | `COLLECTION_READY` |
| `ga4_property_daily` | `GA4` | property_id × date | `NORMALIZED_FACT` | 180d | daily | 7 | `COLLECTION_READY` |
| `ga4_acquisition_channel_daily` | `GA4` | property_id × date × sessionDefaultChannelGroup | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `ga4_source_medium_daily` | `GA4` | property_id × date × sessionSource × sessionMedium | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `ga4_campaign_daily` | `GA4` | property_id × date × sessionCampaignName | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `ga4_landing_page_daily` | `GA4` | property_id × date × landingPage | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `ga4_event_daily` | `GA4` | property_id × date × eventName | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `ga4_event_channel_daily` | `GA4` | property_id × date × eventName × sessionDefaultChannelGroup | `NORMALIZED_FACT` | 180d | daily | 0 | `COLLECTION_READY` |
| `ga4_event_source_medium_daily` | `GA4` | property_id × date × eventName × sessionSource | `NORMALIZED_FACT` | 180d | daily_or_on_demand | 0 | `DECISION_REQUIRED` |
| `ga4_event_campaign_daily` | `GA4` | property_id × date × eventName × sessionCampaignName | `NORMALIZED_FACT` | 180d | daily | 0 | `COLLECTION_READY` |
| `ga4_event_landing_daily` | `GA4` | property_id × date × eventName × landingPage | `NORMALIZED_FACT` | 180d | daily | 0 | `COLLECTION_READY` |
| `ga4_device_daily` | `GA4` | property_id × date × deviceCategory | `NORMALIZED_FACT` | 180d | daily | 1 | `COLLECTION_READY` |
| `ga4_business_action_mapping` | `MOXDOP` | digital_asset_id × business_action_id | `MAPPING` | current | config | 1 | `COLLECTION_READY` |
| `ga4_funnel_definition` | `OPERATOR_MAINTAINED` | funnel_id | `MAPPING` | current | config | 2 | `DEFERRED` |
| `gsc_property_daily` | `SEARCH_CONSOLE` | site_url × date | `NORMALIZED_FACT` | provider_16m_available | daily | 14 | `COLLECTION_READY` |
| `gsc_query_daily` | `SEARCH_CONSOLE` | site_url × date × query | `NORMALIZED_FACT` | provider_16m_available | daily | 5 | `COLLECTION_READY` |
| `gsc_page_daily` | `SEARCH_CONSOLE` | site_url × date × page | `NORMALIZED_FACT` | provider_16m_available | daily | 3 | `COLLECTION_READY` |
| `gsc_query_page_daily` | `SEARCH_CONSOLE` | site_url × date × query × page | `NORMALIZED_FACT` | provider_16m_available | daily | 1 | `COLLECTION_READY` |
| `gsc_country_daily` | `SEARCH_CONSOLE` | site_url × date × country | `NORMALIZED_FACT` | provider_16m_available | daily | 1 | `COLLECTION_READY` |
| `gsc_device_daily` | `SEARCH_CONSOLE` | site_url × date × device | `NORMALIZED_FACT` | provider_16m_available | daily | 1 | `COLLECTION_READY` |
| `gsc_search_appearance_daily` | `SEARCH_CONSOLE` | site_url × date × searchAppearance | `NORMALIZED_FACT` | provider_16m_available | daily | 0 | `COLLECTION_READY` |
| `gsc_url_inspection_snapshot` | `SEARCH_CONSOLE` | site_url × page × inspected_at | `NORMALIZED_SNAPSHOT` | priority_sampled | on_demand_or_weekly | 1 | `COLLECTION_READY` |
| `gsc_sitemap_snapshot` | `SEARCH_CONSOLE` | site_url × sitemap_path × retrieved_at | `NORMALIZED_SNAPSHOT` | current | daily | 0 | `COLLECTION_READY` |
| `gsc_brand_term_map` | `MOXDOP` | brand_id × term_pattern | `MAPPING` | current | config | 1 | `COLLECTION_READY` |
| `gsc_intended_owner_map` | `MOXDOP` | site_url × query_or_cluster × intended_page | `MAPPING` | current | config | 0 | `COLLECTION_READY` |
| `google_ads_account_snapshot` | `GOOGLE_ADS` | customer_id | `NORMALIZED_SNAPSHOT` | current | daily | 5 | `COLLECTION_READY` |
| `google_ads_account_daily` | `GOOGLE_ADS` | customer_id × date | `NORMALIZED_FACT` | 180d | daily | 4 | `COLLECTION_READY` |
| `google_ads_campaign_snapshot` | `GOOGLE_ADS` | customer_id × campaign_id | `NORMALIZED_SNAPSHOT` | current | daily | 7 | `COLLECTION_READY` |
| `google_ads_campaign_daily` | `GOOGLE_ADS` | customer_id × date × campaign_id | `NORMALIZED_FACT` | 180d | daily | 5 | `COLLECTION_READY` |
| `google_ads_ad_group_snapshot` | `GOOGLE_ADS` | customer_id × ad_group_id | `NORMALIZED_SNAPSHOT` | current | daily | 1 | `COLLECTION_READY` |
| `google_ads_ad_snapshot` | `GOOGLE_ADS` | customer_id × ad_id | `NORMALIZED_SNAPSHOT` | current | daily | 6 | `COLLECTION_READY` |
| `google_ads_keyword_snapshot` | `GOOGLE_ADS` | customer_id × criterion_id | `NORMALIZED_SNAPSHOT` | current | daily | 3 | `COLLECTION_READY` |
| `google_ads_keyword_daily` | `GOOGLE_ADS` | customer_id × date × criterion_id | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `google_ads_search_term_daily` | `GOOGLE_ADS` | customer_id × date × search_term | `NORMALIZED_FACT` | 180d | daily | 4 | `COLLECTION_READY` |
| `google_ads_landing_page_daily` | `GOOGLE_ADS` | customer_id × date × landing_page | `NORMALIZED_FACT` | 180d | daily | 3 | `COLLECTION_READY` |
| `google_ads_conversion_action_snapshot` | `GOOGLE_ADS` | customer_id × conversion_action_id | `NORMALIZED_SNAPSHOT` | current | daily | 3 | `COLLECTION_READY` |
| `google_ads_conversion_action_daily` | `GOOGLE_ADS` | customer_id × date × conversion_action_id | `NORMALIZED_FACT` | 180d | daily | 0 | `COLLECTION_READY` |
| `google_ads_campaign_budget_snapshot` | `GOOGLE_ADS` | customer_id × budget_id | `NORMALIZED_SNAPSHOT` | current | daily | 1 | `COLLECTION_READY` |
| `google_ads_asset_coverage_snapshot` | `GOOGLE_ADS` | customer_id × asset_id | `NORMALIZED_SNAPSHOT` | current | daily | 1 | `COLLECTION_READY` |
| `meta_ad_account_snapshot` | `META_ADS` | account_id | `NORMALIZED_SNAPSHOT` | current | daily | 5 | `COLLECTION_READY` |
| `meta_campaign_snapshot` | `META_ADS` | account_id × campaign_id | `NORMALIZED_SNAPSHOT` | current | daily | 8 | `COLLECTION_READY` |
| `meta_adset_snapshot` | `META_ADS` | account_id × adset_id | `NORMALIZED_SNAPSHOT` | current | daily | 7 | `COLLECTION_READY` |
| `meta_creative_snapshot` | `META_ADS` | account_id × creative_id | `NORMALIZED_SNAPSHOT` | current | daily | 3 | `COLLECTION_READY` |
| `meta_campaign_daily` | `META_ADS` | account_id × date × campaign_id | `NORMALIZED_FACT` | 180d | daily | 5 | `COLLECTION_READY` |
| `meta_adset_daily` | `META_ADS` | account_id × date × adset_id | `NORMALIZED_FACT` | 180d | daily | 1 | `COLLECTION_READY` |
| `meta_ad_daily` | `META_ADS` | account_id × date × ad_id | `NORMALIZED_FACT` | 180d | daily | 2 | `COLLECTION_READY` |
| `meta_typed_action_daily` | `META_ADS` | account_id × date × entity_level × entity_id | `NORMALIZED_FACT` | 180d | daily | 11 | `COLLECTION_READY` |
| `meta_delivery_breakdown_daily` | `META_ADS` | account_id × date × entity_id × breakdown_type | `NORMALIZED_FACT` | 180d | daily | 5 | `COLLECTION_READY` |
| `meta_result_mapping` | `MOXDOP` | account_id × result_type × action_type | `MAPPING` | current | config | 0 | `COLLECTION_READY` |
| `website_asset` | `OPERATOR_MAINTAINED` | asset_id | `CONTROL_PLANE` | current | config | 1 | `COLLECTION_READY` |
| `website_url` | `WEBSITE_DIRECT` | asset_id × normalized_url | `NORMALIZED_SNAPSHOT` | first_last_seen | on_discovery | 3 | `COLLECTION_READY` |
| `website_cms_object` | `WORDPRESS_SITE_CONNECTOR` | asset_id × wp_id × post_type | `NORMALIZED_SNAPSHOT` | current | on_sync | 1 | `DEFERRED` |
| `website_http_snapshot` | `WEBSITE_DIRECT` | url × observed_at | `NORMALIZED_SNAPSHOT` | diagnosis_events | on_demand | 2 | `COLLECTION_READY` |
| `website_metadata_snapshot` | `WEBSITE_DIRECT` | url × observed_at | `NORMALIZED_SNAPSHOT` | on_change | on_demand | 2 | `COLLECTION_READY` |
| `website_heading_snapshot` | `WEBSITE_DIRECT` | url × observed_at | `NORMALIZED_SNAPSHOT` | current | on_demand | 1 | `COLLECTION_READY` |
| `website_schema_snapshot` | `WEBSITE_DIRECT` | url × observed_at | `NORMALIZED_SNAPSHOT` | current | on_demand | 1 | `COLLECTION_READY` |
| `website_content_stats` | `WORDPRESS_SITE_CONNECTOR` | url × observed_at | `NORMALIZED_SNAPSHOT` | current | on_change | 1 | `COLLECTION_READY` |
| `website_performance_measurement` | `PAGESPEED_TECHNICAL` | url × observed_at × strategy | `NORMALIZED_SNAPSHOT` | priority_urls | on_demand_daily_max | 3 | `COLLECTION_READY` |
| `website_infra_snapshot` | `DOMAIN_DNS_TLS` | asset_id × observed_at | `NORMALIZED_SNAPSHOT` | current | weekly_or_on_demand | 0 | `COLLECTION_READY` |
| `dataforseo_ranked_keyword_snapshot` | `DATAFORSEO` | target × location_code × language_code × keyword | `EXTERNAL_ENRICHMENT` | latest_valid | 5d_ttl | 2 | `COLLECTION_READY` |
| `dataforseo_keyword_site_snapshot` | `DATAFORSEO` | target × location_code × language_code × keyword | `EXTERNAL_ENRICHMENT` | latest_valid | 7d_ttl | 2 | `COLLECTION_READY` |
| `dataforseo_competitor_domain_snapshot` | `DATAFORSEO` | target × location_code × language_code × competitor_domain | `EXTERNAL_ENRICHMENT` | latest | 14d_ttl | 1 | `COLLECTION_READY` |
| `dataforseo_domain_intersection_snapshot` | `DATAFORSEO` | target1 × target2 × location_code × language_code | `EXTERNAL_ENRICHMENT` | current | 7_14d | 0 | `DEFERRED` |
| `dataforseo_relevant_page_snapshot` | `DATAFORSEO` | target × location_code × language_code × page | `EXTERNAL_ENRICHMENT` | current | 5_7d | 0 | `DEFERRED` |
| `dataforseo_serp_observation` | `DATAFORSEO` | keyword × location_code × language_code × retrieved_at | `EXTERNAL_ENRICHMENT` | current | short | 0 | `DEFERRED` |
| `dataforseo_raw_response` | `DATAFORSEO` | request_fingerprint × retrieved_at | `RAW_RESPONSE` | retain_paid | on_collect | 0 | `COLLECTION_READY` |

## 17. Canonical Request Family Registry

Total request families: **49**

| Request Family | Provider | Capability | Reqs | Date | Pagination | Sync/Async | Cost | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `GA4_RF_PROPERTY_METADATA` | `GA4` | Admin properties.get + streams | 4 | n/a | n/a | sync | None | `COLLECTION_READY` |
| `GA4_RF_PROPERTY_DAILY` | `GA4` | Data API runReport property daily | 6 | date range incremental | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_CHANNEL_DAILY` | `GA4` | runReport channel×date | 1 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_SOURCE_MEDIUM_DAILY` | `GA4` | runReport source/medium×date | 2 | date range | pageToken required | sync | None | `COLLECTION_READY` |
| `GA4_RF_CAMPAIGN_DAILY` | `GA4` | runReport campaign×date | 2 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_LANDING_PAGE_DAILY` | `GA4` | runReport landingPage×date | 1 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_EVENT_DAILY` | `GA4` | runReport eventName×date | 4 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_EVENT_BREAKDOWNS` | `GA4` | runReport event×dim×date | 0 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_DEVICE_DAILY` | `GA4` | runReport device×date | 1 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GA4_RF_GENERIC_REPORT` | `GA4` | compatibility-aware runReport | 2 | date range | pageToken | sync | None | `COLLECTION_READY` |
| `GSC_RF_PROPERTY_DAILY` | `SEARCH_CONSOLE` | searchanalytics.query date | 13 | date; GSC lag | rowLimit/startRow | sync | None | `COLLECTION_READY` |
| `GSC_RF_QUERY_DAILY` | `SEARCH_CONSOLE` | searchanalytics.query dimension=query | 5 | date | pagination/slicing | sync | None | `COLLECTION_READY` |
| `GSC_RF_PAGE_DAILY` | `SEARCH_CONSOLE` | searchanalytics.query dimension=page | 4 | date | pagination/slicing | sync | None | `COLLECTION_READY` |
| `GSC_RF_QUERY_PAGE_DAILY` | `SEARCH_CONSOLE` | searchanalytics.query query+page | 1 | date | heavy slicing | sync | None | `COLLECTION_READY` |
| `GSC_RF_COUNTRY_DAILY` | `SEARCH_CONSOLE` | dimension=country | 1 | date | pagination | sync | None | `COLLECTION_READY` |
| `GSC_RF_DEVICE_DAILY` | `SEARCH_CONSOLE` | dimension=device | 1 | date | pagination | sync | None | `COLLECTION_READY` |
| `GSC_RF_APPEARANCE_DAILY` | `SEARCH_CONSOLE` | dimension=searchAppearance | 0 | date | pagination | sync | None | `COLLECTION_READY` |
| `GSC_RF_URL_INSPECTION` | `SEARCH_CONSOLE` | urlInspection.inspect | 1 | point-in-time | per URL | sync | None | `COLLECTION_READY` |
| `GSC_RF_SITEMAPS` | `SEARCH_CONSOLE` | sitemaps.list | 1 | snapshot | n/a | sync | None | `COLLECTION_READY` |
| `GSC_RF_SEARCH_ANALYTICS` | `SEARCH_CONSOLE` | searchanalytics.query generic | 3 | date | pagination | sync | None | `COLLECTION_READY` |
| `GADS_RF_ENTITY_SNAPSHOT` | `GOOGLE_ADS` | GAQL entity resources | 26 | snapshot | page | sync | None | `COLLECTION_READY` |
| `GADS_RF_ACCOUNT_DAILY` | `GOOGLE_ADS` | customer daily metrics | 0 | customer TZ date | page | Search/SearchStream | None | `COLLECTION_READY` |
| `GADS_RF_CAMPAIGN_DAILY` | `GOOGLE_ADS` | campaign daily metrics | 5 | customer TZ date | SearchStream preferred | SearchStream | None | `COLLECTION_READY` |
| `GADS_RF_KEYWORD` | `GOOGLE_ADS` | keyword_view metrics | 1 | customer TZ date | SearchStream | SearchStream | None | `COLLECTION_READY` |
| `GADS_RF_SEARCH_TERM` | `GOOGLE_ADS` | search_term_view | 3 | customer TZ date | SearchStream | SearchStream | None | `COLLECTION_READY` |
| `GADS_RF_LANDING_PAGE` | `GOOGLE_ADS` | landing_page_view | 2 | customer TZ date | SearchStream | SearchStream | None | `COLLECTION_READY` |
| `GADS_RF_CONVERSION_ACTION` | `GOOGLE_ADS` | conversion_action + metrics | 0 | customer TZ date | page | sync | None | `COLLECTION_READY` |
| `GADS_RF_SEARCH_STREAM` | `GOOGLE_ADS` | GoogleAdsService SearchStream | 5 | customer TZ date | streaming | SearchStream | None | `COLLECTION_READY` |
| `RF_META_AD_ACCOUNT_META` | `META_ADS` | GET act_* fields | 5 | n/a | n/a | sync | None | `COLLECTION_READY` |
| `RF_META_ENTITY_SNAPSHOT` | `META_ADS` | campaigns/adsets/ads/creatives | 16 | snapshot | cursor | sync | None | `COLLECTION_READY` |
| `RF_META_INSIGHTS_SYNC` | `META_ADS` | Insights sync | 9 | account TZ; time_increment | cursor | sync | None | `COLLECTION_READY` |
| `RF_META_INSIGHTS_DAILY` | `META_ADS` | Insights time_increment=1 | 8 | account TZ daily | cursor/async later | sync_then_async | None | `COLLECTION_READY` |
| `RF_META_TYPED_ACTIONS` | `META_ADS` | Insights actions[] normalize | 9 | account TZ daily | with insights | sync | None | `COLLECTION_READY` |
| `RF_META_INSIGHTS_BREAKDOWN` | `META_ADS` | Insights breakdowns | 0 | account TZ daily | cursor/async | async_recommended | None | `COLLECTION_READY` |
| `RF_META_ASYNC_INSIGHTS` | `META_ADS` | Async Insights jobs | 0 | account TZ | job paging | async | None | `DEFERRED` |
| `WEB_RF_HTTP_HTML_DIAGNOSIS` | `WEBSITE_DIRECT` | Public HTTP/HTML diagnosis | 5 | observed_at | per URL | sync | None | `COLLECTION_READY` |
| `WEB_RF_WP_REST` | `WORDPRESS_SITE_CONNECTOR` | WordPress REST read | 5 | cms dates | pagination | sync | None | `DEFERRED` |
| `WEB_RF_PAGESPEED` | `PAGESPEED_TECHNICAL` | PSI v5 / CrUX | 2 | observed_at | per URL | sync | MEDIUM | `COLLECTION_READY` |
| `WEB_RF_DNS_TLS` | `DOMAIN_DNS_TLS` | DNS/TLS probes | 1 | observed_at | per domain | sync | None | `COLLECTION_READY` |
| `WEB_RF_PUBLIC_CRAWL` | `WEBSITE_DIRECT` | Bounded public discovery crawl | 0 | observed_at | bounded URLs | sync/async | None | `COLLECTION_READY` |
| `DFS-FREE-USER` | `DATAFORSEO` | GET appendix/user_data | 1 | n/a | n/a | sync | LOW | `COLLECTION_READY` |
| `DFS-FREE-MARKETS` | `DATAFORSEO` | GET locations_and_languages | 1 | n/a | n/a | sync | LOW | `COLLECTION_READY` |
| `DFS-RK-LIVE` | `DATAFORSEO` | POST ranked_keywords/live | 4 | retrieved_at; Labs weekly | limit/offset max1000 | sync Live + async job UX | LOW_MEDIUM | `COLLECTION_READY` |
| `DFS-KFS-LIVE` | `DATAFORSEO` | POST keywords_for_site/live | 2 | retrieved_at | limit/offset | sync Live + async UX | LOW_MEDIUM | `COLLECTION_READY` |
| `DFS-COMP-DOMAIN-LIVE` | `DATAFORSEO` | POST competitors_domain/live | 1 | retrieved_at | limit≤10 | sync | LOW | `COLLECTION_READY` |
| `DFS-DOMAIN-INTERSECT-LIVE` | `DATAFORSEO` | POST domain_intersection/live | 0 | retrieved_at | limit | sync | MEDIUM | `DEFERRED` |
| `DFS-RELEVANT-PAGES-LIVE` | `DATAFORSEO` | POST relevant_pages/live | 0 | retrieved_at | limit | sync | LOW_MEDIUM | `DEFERRED` |
| `DFS-SERP-ORGANIC` | `DATAFORSEO` | SERP google organic | 0 | retrieved_at | per SERP | Live/Standard | HIGH | `DEFERRED` |
| `DFS-OPP-CROSS` | `MOXDOP` | CrossSourceKeywordOpportunities derived | 2 | parent freshness | n/a | runtime | None | `COLLECTION_READY` |

## 18. Cross-Asset Reuse Rules

- One provider dataset may serve many UI consumers (e.g. `gsc_page_daily` → GSC Pages + Website Visibility).
- Website requirements that display GSC/GA4/DFS facts are **consumers**, not duplicate collections.
- Do not create `WEBSITE_GSC_*` datasets that copy GSC truth.

## 19. Derived Data Rules

- CTR/CPC/rates/engagement rates are formulas (Prompt 8), not independent collections.
- `DFS-OPP-CROSS` is MOXDOP_DERIVED from RK + KFS + GSC Evidence.

## 20. Business Action Boundary

Business Actions are MoxDOP mappings over provider events/conversions/typed actions. Not provider-native metrics.

## 21. Business Outcome Boundary

Qualified Lead / Sale / Patient / Revenue outcomes are **not** provider collection truth.

## 22. DataForSEO Cost / Cache Boundary

- Operating mode: `PAID_EXTERNAL_ENRICHMENT`
- Cache-first mandatory; fingerprint + TTL
- Render/mount/browser-refresh triggers **FORBIDDEN**
- Market + language required
- Provider estimates labeled estimated

## 23. Website Multi-Source Boundary

Website DA consumes Direct + WordPress + GA4 + GSC + DFS + technical + infra with preserved provenance. WordPress ≠ Website. No Health Score. Domain/Hosting not standalone DAs.

## 24. Unsupported / Demo-Only Registry

- `UNSUP_GA4_USER_LEVEL_JOURNEYS`: No user-level/PII journey paths
- `UNSUP_GSC_SITEWIDE_INDEX_TOTALS`: GSC API does not provide honest sitewide Indexed/Excluded totals
- `UNSUP_WEBSITE_HEALTH_SCORE`: Forbidden magic score
- `UNSUP_META_UNIVERSAL_RESULTS_TOTAL`: Result Mix locked; heterogeneous Results must not sum
- `UNSUP_DFS_AS_ACCOUNT_INGESTION`: DataForSEO is enrichment not account ingestion
- `UNSUP_DOMAIN_HOSTING_STANDALONE_DA`: Domain/Hosting are not standalone Digital Assets

Demo-only requirements (51): `GA4_OVERVIEW_JOURNEY_SNAPSHOT`, `GA4_OVERVIEW_OPPORTUNITY`, `GA4_JOURNEY_AGG_PATHS`, `GSC_OVERVIEW_MOMENTUM`, `GSC_OVERVIEW_DISCOVERABILITY`, `GSC_DEMAND_CLUSTERS`, `GSC_DEMAND_MOMENTUM`, `GSC_INDEX_COVERAGE_TOTALS`, `GADS_OVERVIEW_NEEDS_ATTENTION`, `GADS_OVERVIEW_OPPORTUNITIES`, `GADS_OVERVIEW_OUTCOMES`, `GADS_CAMPAIGN_CONTEXT`, `GADS_CAMPAIGN_DEVICE_STUB`, `GADS_KEYWORD_OBSERVED_ALIGNMENT`, `GADS_SEARCH_TERM_CLASSIFICATION`, `GADS_SEARCH_DECISION_INBOX`, `GADS_SEARCH_INTENT_DISTRIBUTION`, `GADS_SEARCH_INTENT_DRIFT`, `GADS_SEARCH_REVIEWABLE_SPEND`, `GADS_AD_MESSAGE_MATCH`, `GADS_LANDING_MESSAGE_MATCH`, `GADS_MEASUREMENT_MATRIX`, `GADS_BUSINESS_ACTION_MAPPING`, `GADS_MEASUREMENT_HEALTH`, `GADS_GA4_LINKAGE`, `GADS_OPS_`, `META_OVERVIEW_BUDGET_PACING`, `META_OVERVIEW_NEEDS_ATTENTION`, `META_OVERVIEW_OPPORTUNITIES`, `META_CAMPAIGN_PACING`, `META_CREATIVE_CLASSIFICATION`, `META_FUNNEL_DESTINATION`, `META_FUNNEL_WEBSITE`, `META_MEASUREMENT_MAPPING`, `META_MEASUREMENT_HEALTH`, `META_OPS_`, `WEB_OVERVIEW_ATTENTION`, `WEB_HEALTH_AVAILABILITY`, `WEB_VIS_LOCAL`, `WEB_VIS_AI`…

## 25. Decisions

| ID | Blocking | Question |
| --- | --- | --- |
| `DEC_GA4_USERS_METRIC` | NON_BLOCKING | Overview Users = totalUsers vs activeUsers |
| `DEC_GA4_ENGAGEMENT_TIME_DIVISOR` | NON_BLOCKING | Avg engagement time divisor |
| `DEC_GA4_COMPARISON_PERIOD_TZ` | NON_BLOCKING | Adapt ComparisonPeriod to property TZ |
| `DEC_GA4_BACKFILL_GT_180` | NON_BLOCKING | Backfill >180d |
| `DEC_GA4_EVENT_SM_PERSIST` | NON_BLOCKING | Persist event×source/medium daily |
| `DEC_GSC_INSPECTION_TTL` | NON_BLOCKING | URL Inspection cache TTL + priority set |
| `DEC_GSC_OWNERSHIP_METHOD` | NON_BLOCKING | Query ownership methodology |
| `DEC_META_LATE_ACTION_WINDOW` | NON_BLOCKING | Late-action reprocessing window N days |
| `DEC_META_BUDGET_UNITS` | NON_BLOCKING | Budget field unit scaling confirmation |
| `DEC_META_BREAKDOWN_PARAMS` | NON_BLOCKING | Exact breakdown param names on current Graph |
| `DEC_META_ATTRIBUTION_DEFAULT` | NON_BLOCKING | Confirm default attribution on Graph version |
| `DEC_META_WEBSITE_LEAD_ACTIONS` | NON_BLOCKING | Website lead action_type set per account |
| `DEC_DFS_COLLECTION_LIMITS` | NON_BLOCKING | Confirm V1 limits 100/10 vs expansion |
| `DEC_DFS_SCHEDULE` | NON_BLOCKING | Whether scheduled SEO refresh allowed |
| `DEC_DFS_SPEND_GUARDS` | NON_BLOCKING | Daily/monthly DFS spend guards |
| `DEC_WEB_WP_INVENTORY` | NON_BLOCKING | Productionize WP content inventory |

## 26. Traceability

Traceability rows: **261** (one per canonical requirement).

Full matrix is in JSON `traceability` array. Columns: Canonical ID → Source Contract → Source Requirement IDs → Consumers → Provider → Dataset → Request Family → Status.

## 27. Collector Contract

Future collectors must:
1. Resolve eligible READY requirements for a bound resource
2. Select request families
3. Respect history, freshness, eligibility, cache, cost
4. Write only listed datasets
5. Preserve provenance and missing≠zero
6. Never collect DEMO_ONLY/UNSUPPORTED as provider truth
7. Never trigger paid DataForSEO on page render

## 28. Versioning Rules

- Semantic change to existing requirement → amendment or V2
- Additive consumer of existing dataset → allowed
- New unused provider field → do not add automatically
- Breaking grain/provenance → new version

## 29. Validation Results

- Requirement count: 269
- By status: {'COLLECTION_READY': 143, 'NO_COLLECTION_REQUIRED': 37, 'DEMO_ONLY': 51, 'DECISION_REQUIRED': 16, 'UNAVAILABLE': 6, 'DEFERRED': 8}
- By level: {'REQUIRED': 196, 'CONDITIONAL': 52, 'OPTIONAL': 13}
- By provider: {'GA4': 36, 'MOXDOP': 18, 'SEARCH_CONSOLE': 43, 'GOOGLE_ADS': 61, 'META_ADS': 64, 'OPERATOR_MAINTAINED': 1, 'OPERATIONS_DOMAIN': 5, 'WORDPRESS_SITE_CONNECTOR': 8, 'WEBSITE_DIRECT': 7, 'PAGESPEED_TECHNICAL': 3, 'DATAFORSEO': 11, 'DOMAIN_DNS_TLS': 4}
- Datasets: 66
- Request families: 49
- Validation passed: True
- Validation errors: []

## 30. Definition of Done

- Six source contracts included with lineage: YES
- Machine-readable JSON + schema + Markdown: YES
- Requirements/datasets/request families cross-linked: YES
- Cross-asset dedupe principles recorded: YES
- Semantic boundaries preserved: YES
- DataForSEO non-ingestion preserved: YES
- Meta typed actions preserved: YES
- No Website Health Score: YES
- Decisions recorded without silent guessing: YES
- Future collectors can determine what/when/how much for base facts: YES

## Summary Counts

- total requirements: **269**
- collection-ready: **147**
- no-collection-required: **37**
- decision-required: **17**
- unavailable: **6**
- demo-only: **51**
- deferred: **11**
- required/optional/conditional: **{'REQUIRED': 196, 'CONDITIONAL': 52, 'OPTIONAL': 13}**

## Formula Handoff (Prompt 8)

- `FORMULA_GA4_ENGAGEMENT_RATE` ← GA4_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_GA4_AVG_ENGAGEMENT_TIME` ← GA4_DATA_CONTRACT · DECISION_REQUIRED · conflicts=['divisor unresolved']
- `FORMULA_GSC_CTR` ← SEARCH_CONSOLE_DATA_CONTRACT, WEBSITE_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_GSC_AVG_POSITION` ← SEARCH_CONSOLE_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_GADS_CTR` ← GOOGLE_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_GADS_CPC` ← GOOGLE_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_GADS_CPA` ← GOOGLE_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_META_CTR` ← META_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=['click≠link_click']
- `FORMULA_META_LINK_CTR` ← META_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_META_FREQUENCY` ← META_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]
- `FORMULA_META_COST_PER_RESULT` ← META_ADS_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=['requires result_type']
- `FORMULA_DFS_CROSS_SOURCE_OPP` ← DATAFORSEO_DATA_CONTRACT · READY_FOR_PROMPT_8 · conflicts=[]

## Cross-Source Semantic Boundary Table

| Concept | Can merge? | Canonical rule |
| --- | --- | --- |
| Users | False | Preserve both metrics; UI lock pending |
| Campaign | False | No silent merge |
| Conversion | False | Never one CONVERSION concept |
| Result | False | Must have result_type |
| Business Action | False | Not provider-native |
| Business Outcome | False | Not provider collection |
| Click | False | Keep typed |
| Link Click | False | ≠ generic click |
| Reach | False | Do not sum daily reach |
| Impression | False | Provenance required |
| Search Term | False | ≠ keyword ≠ GSC query |
| Keyword | False | Distinct IDs |
| GSC Query | False | ≠ DFS keyword ≠ Ads search term |
| Search Volume | False | ≠ GSC impressions |
| Demand | False | Keep labels |
| Average Position | False | ≠ DFS rank |
| SERP Rank | False | ≠ GSC position |
| Website URL | join | Website URL identity |
| Declared Canonical | False | May disagree with normalized URL |
| Indexability | False | ≠ indexing |
| Indexing | False | ≠ indexability |
| Lab Performance | False | ≠ field |
| Field Performance | False | ≠ lab |
| Business Competitor | False | ≠ organic search competitor |
| Organic Search Competitor | False | Needs Accept to promote |

---

Future MoxDOP collectors must implement the requirements defined by `MOXDOP_DATA_CONTRACT_REGISTRY_V1`.
