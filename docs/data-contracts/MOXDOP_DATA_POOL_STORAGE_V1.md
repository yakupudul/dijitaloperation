# MOXDOP Data Pool Storage V1

Status: **FROZEN_FOR_WAREHOUSE_IMPLEMENTATION**

## Relationship

- `MOXDOP_DATA_CONTRACT_REGISTRY_V1` = **what** logical datasets exist / are required
- `MOXDOP_DATA_POOL_STORAGE_V1` = **how** storage-relevant datasets are persisted in the V1 data pool
- `MOXDOP_FORMULA_REGISTRY_V1` = derived metrics (query-time; not silently persisted as facts)

## Metadata

- **storage_contract_id**: `MOXDOP_DATA_POOL_STORAGE`
- **version**: `1`
- **status**: `FROZEN_FOR_WAREHOUSE_IMPLEMENTATION`
- **data_contract_registry_id**: `MOXDOP_DATA_CONTRACT_REGISTRY`
- **data_contract_registry_version**: `1`
- **data_contract_registry_commit**: `b498c8c41b8af8daccf26ab4c3519159f9e9900a`
- **formula_registry_version**: `1`
- **collection_engine_reference**: `cursor/collection-engine-foundation-ea01@9661351`
- **production_database**: `POSTGRESQL`
- **raw_storage**: `PRIVATE_OBJECT_STORAGE_VIA_LARAVEL_FILESYSTEM`

## Layer definitions

| Layer | Question | V1 store |
| --- | --- | --- |
| Control plane | What work is happening? | CollectionRun / ResourceRun / DatasetRun |
| Raw | What did the provider return? | Private object storage + `raw_ingestion_objects` |
| Normalized | What canonical facts does MoxDOP store? | PostgreSQL typed tables |
| Derived | What deterministic metrics are calculated? | Formula Registry (not fact columns by default) |
| Evidence | What source-backed analytical statement can MoxDOP reason from? | Later milestone |

## Disposition coverage

Total logical datasets: **66**

- `DEFERRED`: 5
- `DOMAIN_DATA`: 4
- `NO_STORAGE_REQUIRED`: 1
- `PHYSICAL_TABLE`: 54
- `RAW_ONLY`: 1
- `STORAGE_CONTRACT_GAP`: 1

### Gaps

- `ga4_event_source_medium_daily` — STORAGE_CONTRACT_GAP — Prompt 7 DECISION_REQUIRED — no invented grain/storage (blocking=False)

## Naming

Canonical table prefix style: `ga4_`, `gsc_`, `google_ads_`, `meta_`, `website_`, `dataforseo_`.

## Write modes

- `UPSERT_DAILY_FACT`
- `UPSERT_CURRENT_STATE`
- `APPEND_SNAPSHOT`
- `UPSERT_EDGE`
- `APPEND_OBSERVATION`

## Partitioning

- Strategy: `NONE` or `RANGE_MONTHLY` by `reporting_date`
- Partition key is **never** `collection_run_id`
- No default partition in V1 — `PartitionManager` must ensure months before write
- SQLite/tests: equivalent non-partitioned tables

Partitioned physical tables: **20**

## Physical datasets by source

### DATAFORSEO

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `dataforseo_ranked_keyword_snapshot` | `dataforseo_ranked_keyword_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | target, location_code, language_code, keyword, retrieved_at |
| `dataforseo_keyword_site_snapshot` | `dataforseo_keyword_site_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | target, location_code, language_code, keyword, retrieved_at |
| `dataforseo_competitor_domain_snapshot` | `dataforseo_competitor_domain_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | target, location_code, language_code, competitor_domain, retrieved_at |

### DOMAIN_DNS_TLS

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `website_infra_snapshot` | `website_infra_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, asset_id, observed_at |

### GA4

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `ga4_property_metadata` | `ga4_property_metadata` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, property_id |
| `ga4_property_daily` | `ga4_property_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, property_id, reporting_date |
| `ga4_acquisition_channel_daily` | `ga4_acquisition_channel_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, property_id, reporting_date, sessionDefaultChannelGroup |
| `ga4_source_medium_daily` | `ga4_source_medium_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, sessionSource, sessionMedium |
| `ga4_campaign_daily` | `ga4_campaign_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, sessionCampaignName |
| `ga4_landing_page_daily` | `ga4_landing_page_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, landingPage |
| `ga4_event_daily` | `ga4_event_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, eventName |
| `ga4_event_channel_daily` | `ga4_event_channel_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, eventName, sessionDefaultChannelGroup |
| `ga4_event_campaign_daily` | `ga4_event_campaign_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, eventName, sessionCampaignName |
| `ga4_event_landing_daily` | `ga4_event_landing_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, property_id, reporting_date, eventName, landingPage |
| `ga4_device_daily` | `ga4_device_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, property_id, reporting_date, deviceCategory |

### GOOGLE_ADS

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `google_ads_account_snapshot` | `google_ads_account_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id |
| `google_ads_account_daily` | `google_ads_account_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, customer_id, reporting_date |
| `google_ads_campaign_snapshot` | `google_ads_campaign_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, campaign_id |
| `google_ads_campaign_daily` | `google_ads_campaign_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, customer_id, reporting_date, campaign_id |
| `google_ads_ad_group_snapshot` | `google_ads_ad_group_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, ad_group_id |
| `google_ads_ad_snapshot` | `google_ads_ad_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, ad_id |
| `google_ads_keyword_snapshot` | `google_ads_keyword_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, criterion_id |
| `google_ads_keyword_daily` | `google_ads_keyword_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, customer_id, reporting_date, criterion_id |
| `google_ads_search_term_daily` | `google_ads_search_term_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, customer_id, reporting_date, search_term |
| `google_ads_landing_page_daily` | `google_ads_landing_page_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, customer_id, reporting_date, landing_page |
| `google_ads_conversion_action_snapshot` | `google_ads_conversion_action_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, conversion_action_id |
| `google_ads_conversion_action_daily` | `google_ads_conversion_action_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, customer_id, reporting_date, conversion_action_id |
| `google_ads_campaign_budget_snapshot` | `google_ads_campaign_budget_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, budget_id |
| `google_ads_asset_coverage_snapshot` | `google_ads_asset_coverage_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, customer_id, asset_id |

### META_ADS

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `meta_ad_account_snapshot` | `meta_ad_account_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, account_id |
| `meta_campaign_snapshot` | `meta_campaign_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, account_id, campaign_id |
| `meta_adset_snapshot` | `meta_adset_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, account_id, adset_id |
| `meta_creative_snapshot` | `meta_creative_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, account_id, creative_id |
| `meta_campaign_daily` | `meta_campaign_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, account_id, reporting_date, campaign_id |
| `meta_adset_daily` | `meta_adset_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, account_id, reporting_date, adset_id |
| `meta_ad_daily` | `meta_ad_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, account_id, reporting_date, ad_id |
| `meta_typed_action_daily` | `meta_typed_action_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, account_id, reporting_date, entity_level, entity_id, action_type |
| `meta_delivery_breakdown_daily` | `meta_delivery_breakdown_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, account_id, reporting_date, entity_id, breakdown_type, breakdown_value |

### PAGESPEED_TECHNICAL

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `website_performance_measurement` | `website_performance_measurement` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at, strategy |

### SEARCH_CONSOLE

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `gsc_property_daily` | `gsc_property_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, site_url, reporting_date |
| `gsc_query_daily` | `gsc_query_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, site_url, reporting_date, query |
| `gsc_page_daily` | `gsc_page_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, site_url, reporting_date, page |
| `gsc_query_page_daily` | `gsc_query_page_daily` | `UPSERT_DAILY_FACT` | `RANGE_MONTHLY` | digital_asset_id, site_url, reporting_date, query, page |
| `gsc_country_daily` | `gsc_country_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, site_url, reporting_date, country |
| `gsc_device_daily` | `gsc_device_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, site_url, reporting_date, device |
| `gsc_search_appearance_daily` | `gsc_search_appearance_daily` | `UPSERT_DAILY_FACT` | `NONE` | digital_asset_id, site_url, reporting_date, searchAppearance |
| `gsc_url_inspection_snapshot` | `gsc_url_inspection_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, site_url, page, inspected_at |
| `gsc_sitemap_snapshot` | `gsc_sitemap_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, site_url, sitemap_path, retrieved_at |

### WEBSITE_DIRECT

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `website_url` | `website_url` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, asset_id, normalized_url |
| `website_http_snapshot` | `website_http_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at |
| `website_metadata_snapshot` | `website_metadata_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at |
| `website_heading_snapshot` | `website_heading_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at |
| `website_schema_snapshot` | `website_schema_snapshot` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at |

### WORDPRESS_SITE_CONNECTOR

| Table | Logical dataset | Write mode | Partition | Natural key (abbrev) |
| --- | --- | --- | --- | --- |
| `website_content_stats` | `website_content_stats` | `UPSERT_CURRENT_STATE` | `NONE` | digital_asset_id, url, observed_at |

## Control tables

- `raw_ingestion_objects` — Raw payload manifest (not payload bytes)
- `dataset_write_batches` — Durable normalized write commits / idempotency
- `dataset_materializations` — Per resource/dataset pool state (≠ CollectionRun)

## Money / ratios / identity

- Canonical money: `decimal` / `numeric` (no float)
- Google Ads micros preserved alongside canonical `cost_amount`
- Currency preserved per row; no FX / mixed aggregation
- Formula Registry ratios (CTR, CPC, CPM, …) are **not** persisted as fact columns by default
- `collection_run_id` is provenance only (`last_collection_run_id`), never natural-key identity
- Provider external IDs stored as `text`
- `reporting_date` ≠ `collected_at` / `last_collected_at`

## Raw retention

**RAW RETENTION POLICY REQUIRES LATER OPERATIONAL DECISION** — capability field exists; automatic cleanup is not implemented in Prompt 10.

## BigQuery

Not required for V1. `WarehouseWriter` boundary allows a future `BigQueryWarehouseWriter` without collector changes. Production implementation: **NONE**.

## Invariants

- RAW_DATA_DISTINCT_FROM_NORMALIZED
- NORMALIZED_DISTINCT_FROM_EVIDENCE
- NO_GENERIC_EAV_METRICS_TABLE
- COLLECTION_RUN_IS_PROVENANCE_NOT_FACT_IDENTITY
- RETRY_IDEMPOTENT_VIA_NATURAL_KEYS
- CHECKPOINT_AFTER_DURABLE_COMMIT
- REDIS_NOT_WAREHOUSE
- BIGQUERY_NOT_REQUIRED_V1
- MISSING_NEVER_EQUALS_ZERO
- NO_FLOAT_MONEY

