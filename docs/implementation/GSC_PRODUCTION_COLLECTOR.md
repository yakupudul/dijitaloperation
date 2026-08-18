# SEARCH CONSOLE PRODUCTION COLLECTOR

## 1. Purpose

Prompt 17 implements the first production provider analytical collector: human-confirmed bound Search Console properties populate the canonical MoxDOP data pool with real Search Console provider data through the shared Collection Engine.

## 2. Contract Boundary

The collector implements only:

- `docs/data-contracts/SEARCH_CONSOLE_DATA_CONTRACT_V1.md`
- `docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json`
- physical storage from `docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.json`
- formula ownership remains in `docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json`

It does not invent extra Search Console reports from API capability alone.

Source-contract exclusion: `searchAppearance` / `GSC_RF_APPEARANCE_DAILY` is not collected in V1 (source contract §25).

## 3. Bound Resource Eligibility

Collection requires:

1. Active Google Integration (usable auth status)
2. Search Console OAuth scope granted when scopes are known
3. `search_console` ExternalResource (`GSC_PROPERTY`) with provider `siteUrl` identity
4. Human-confirmed active `CoreAssetBinding`
5. Tenant/Customer/Brand/DigitalAsset/Resource/Binding scope consistency

Discovered-only resources are not collection-eligible.

## 4. Credential Path

All API calls use `GoogleApiClient` → `GoogleCredentialBroker`.

- No token decrypt in collector
- No token refresh in collector
- No tokens in DatasetRun, queue payload, checkpoint, or raw metadata

## 5. Collector Architecture

```
Human-confirmed GSC Binding
  → CollectionPlanner (provider SEARCH_CONSOLE)
  → DatasetRuns (request-family IDs)
  → SearchConsoleDatasetExecutor
  → GoogleCredentialBroker / Search Console API
  → RawPayloadWriter
  → SearchConsoleNormalizer
  → NormalizedDatasetBatch
  → WarehouseWriter
  → PostgreSQL GSC pool
  → Dataset Materialization
```

Registered via `collection.dataset_executors` tag. No second `GscCollectionEngine`.

## 6. Request Family Mapping

See **Request Family Matrix** below.

## 7. Search Analytics Semantics

- `type=web` only
- `dataState=final` (primary)
- Contract dimensions only: date / query / page / device / country
- Aggregation preserved (`byProperty`, `byPage`, `auto`) including `responseAggregationType` when present
- Provider CTR retained only as `metadata.provider_ctr` (not canonical Formula Registry CTR)
- Position retained as `metadata.provider_average_position` (Storage V1 has no position column; not renamed to rank)

## 8. Reporting Date Semantics

Provider Search Console reporting dates (America/Los_Angeles / PT) are preserved as `reporting_date`. No rebucket into server UTC or customer timezone.

## 9. Date Slicing

Config-driven (`config/moxdop-gsc-collector.php`):

| Family | Default slice days |
| --- | --- |
| Property / Device | 28 |
| Country | 7 |
| Query / Page / Query×Page | 1 |

Inclusive, contiguous, non-overlapping. Historical range comes from `StartCollectionRequest.dateRange` / CollectionPlan — collector does not invent “last 16 months”.

## 10. Pagination

Official Search Analytics: `rowLimit` (1–25000) + `startRow` (zero-based). Centralized via config / `SearchConsoleProviderCapabilities::MAX_ROW_LIMIT`. Termination when returned rows < page size (no total-row assumption).

## 11. Provider Completeness Limitation

Successful pagination ≠ exhaustive Google query universe.

Materialization `freshness_metadata`:

- `provider_completeness = PROVIDER_TOP_ROWS_LIMITED`
- `execution_completeness = REQUEST_EXECUTION_COMPLETE`
- `provider_universe_exhaustive = false`
- `missing_query_equals_zero = false`

## 12. Property Daily Facts

`GSC_RF_PROPERTY_DAILY` → direct property aggregation (`dimensions=[date]`, `aggregationType=byProperty`). Not reconstructed from query rows.

## 13. Query Facts

`GSC_RF_QUERY_DAILY` — exact provider query text preserved; daily slices; pagination.

## 14. Page Facts

`GSC_RF_PAGE_DAILY` — provider page URL preserved; no Website URL rewrite at ingest.

## 15. Query × Page Facts

`GSC_RF_QUERY_PAGE_DAILY` — daily slices + pagination; high-cardinality load-aware; provider completeness limitation retained.

## 16. Sitemaps

Read-only `sitemaps.list`. No submit/delete. Deprecated `contents[].indexed` ignored for canonical indexing. Submitted ≠ indexed.

## 17. URL Inspection

Controlled: only explicit `request_context.context.url_inspection_targets`. Budget via `url_inspection_max_targets_per_run`. Property validation before call. Not a live-URL test; not site-wide inventory. Google canonical ≠ user canonical.

## 18. Quota / Load Handling

Provider errors map into Prompt 12 categories (`RATE_LIMIT`, `QUOTA`, …). No busy `sleep()` in collector. Sibling DatasetRuns isolated.

## 19. Raw Payloads

`RawPayloadWriter` with safe metadata only. Deterministic batch keys for retry reuse.

## 20. Normalization

`SearchConsoleNormalizer` emits logical records only — no physical table names.

## 21. Warehouse Persistence

`DatasetWritePipeline` → `WarehouseWriter` upsert by Storage Contract natural keys. Collector never `INSERT INTO gsc_...`.

## 22. Checkpoint / Resume

Checkpoint advances only after durable commit (WriteReceipt) or successful zero-row page. Fields: `slice_index`, `start_row`, page/row totals. No secrets.

## 23. Idempotency

Natural keys exclude CollectionRun ID. Retry/replay/late correction upsert safely.

## 24. Materialization / Coverage

Coverage from written `reporting_date`s. Provider limitation metadata persisted. Failed refresh must not erase prior Available/Partial state (Prompt 10 service).

## 25. Progress / Monitoring

Counted slice progress when slice count known; page/row counters; no fake overall row percentage when page total unknown. Prompt 11 monitoring can show real GSC DatasetRun state. Specialist GSC UI remains Demo (Prompt 29).

## 26. Security

Tenant scope checks; no tokens in logs/raw/checkpoints; synthetic fixtures only; read-only provider surface.

## 27. Tests

`tests/Feature/Collection/GscProductionCollectorTest.php`  
`tests/Unit/Collection/SearchConsoleDateSlicerTest.php`  

0 live Google calls in automated suite.

## 28. Provider Limitations

Verified 2026-08-13 against official Search Analytics docs: top-rows limitation, ~50k/day/type guidance, PT dates, `dataState`, pagination.

## 29. Reality Matrix

See Milestone 5 Capability Reality Matrix update: GSC Production Collector / Search Analytics / Sitemaps / Controlled Inspection / normalized pool = REAL; specialist UI migration = NOT YET.

## 30. Prompt 20 / Prompt 29 Handoff

- Prompt 20: Google Initial Backfill Orchestration (multi-provider)
- Prompt 29: Search Console Real Data UI Migration
- Prompt 27: incremental freshness scheduling
- Prompt 38: Evidence canonicalization

## 31. Definition of Done

Human-confirmed GSC Binding → CollectionPlan → GSC DatasetRuns → Search Analytics / Sitemaps / Controlled Inspection → date slicing + pagination → raw → normalizer → WarehouseWriter → real GSC pool → materialization.

---

## REQUEST FAMILY MATRIX

| Requirement IDs (representative) | Request Family | Logical Dataset | API | Dimensions | Metrics (base) | Search Type | Aggregation | Data State | Date Slice | Pagination | Raw | Physical Storage | Required Level |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GSC_PERF_*/OVERVIEW_* / WEB_VIS_* | GSC_RF_PROPERTY_DAILY | gsc_property_daily | searchanalytics.query | date | clicks, impressions (+ position/ctr in metadata) | web | byProperty | final | 28d | startRow | yes | gsc_property_daily | REQUIRED |
| GSC_DEMAND_QUERY_EXPLORER | GSC_RF_QUERY_DAILY | gsc_query_daily | searchanalytics.query | date,query | same | web | auto | final | 1d | startRow | yes | gsc_query_daily | REQUIRED |
| GSC_PAGES_* / OVERVIEW_PAGE_PULSE | GSC_RF_PAGE_DAILY | gsc_page_daily | searchanalytics.query | date,page | same | web | byPage | final | 1d | startRow | yes | gsc_page_daily | REQUIRED |
| GSC_DEMAND_OWNERSHIP | GSC_RF_QUERY_PAGE_DAILY | gsc_query_page_daily | searchanalytics.query | date,query,page | same | web | auto | final | 1d | startRow | yes | gsc_query_page_daily | CONDITIONAL |
| GSC_PERF_DEVICE | GSC_RF_DEVICE_DAILY | gsc_device_daily | searchanalytics.query | date,device | same | web | byProperty | final | 28d | startRow | yes | gsc_device_daily | REQUIRED |
| GSC_PERF_COUNTRY | GSC_RF_COUNTRY_DAILY | gsc_country_daily | searchanalytics.query | date,country | same | web | byProperty | final | 7d | startRow | yes | gsc_country_daily | REQUIRED |
| GSC_INDEX_SITEMAPS | GSC_RF_SITEMAPS | gsc_sitemap_snapshot | sitemaps.list | — | sitemap metadata | — | — | snapshot | n/a | n/a | yes | gsc_sitemap_snapshot | REQUIRED |
| GSC_INDEX_URL_INSPECTION_TABLE | GSC_RF_URL_INSPECTION | gsc_url_inspection_snapshot | urlInspection.index.inspect | — | index status fields in metadata | — | — | point-in-time | n/a | per URL | yes | gsc_url_inspection_snapshot | CONDITIONAL |
| GSC_SHELL_IDENTITY / RELATIONSHIPS | GSC_RF_SEARCH_ANALYTICS | (no physical fact table) | sites.get | — | site metadata raw | — | — | — | n/a | n/a | yes | raw only | REQUIRED shell |
| — | GSC_RF_APPEARANCE_DAILY | — | — | — | — | — | — | — | — | — | — | NOT COLLECTED (source V1) | excluded |

## DATASET MATRIX

| Dataset | Grain | Natural Key | Provider facts | Partition | Write mode | History | Coverage | Provider completeness | Consumers |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| gsc_property_daily | property×date | digital_asset_id, site_url, reporting_date | clicks, impressions; position/ctr in metadata | NONE | UPSERT_DAILY_FACT | upsert | date coverage | TOP_ROWS_LIMITED | Overview/Performance |
| gsc_query_daily | query×date | + query | same | RANGE_MONTHLY | UPSERT_DAILY_FACT | upsert | date | TOP_ROWS_LIMITED | Demand |
| gsc_page_daily | page×date | + page | same | RANGE_MONTHLY | UPSERT_DAILY_FACT | upsert | date | TOP_ROWS_LIMITED | Pages |
| gsc_query_page_daily | query×page×date | + query,page | same | RANGE_MONTHLY | UPSERT_DAILY_FACT | upsert | date | TOP_ROWS_LIMITED | Ownership |
| gsc_device_daily | device×date | + device | same | NONE | UPSERT_DAILY_FACT | upsert | date | TOP_ROWS_LIMITED | Performance |
| gsc_country_daily | country×date | + country | same | NONE | UPSERT_DAILY_FACT | upsert | date | TOP_ROWS_LIMITED | Performance |
| gsc_sitemap_snapshot | sitemap×retrieved_at | site_url, sitemap_path, retrieved_at | path, submitted/downloaded, warnings/errors | NONE | UPSERT_CURRENT_STATE | snapshot NK | n/a | metadata snapshot | Indexing |
| gsc_url_inspection_snapshot | url×inspected_at | site_url, page, inspected_at | index-status fields in metadata | NONE | UPSERT_CURRENT_STATE | observation NK | n/a | CONTROLLED_SAMPLE_ONLY | Indexing |

## SEARCH ANALYTICS LIMIT MATRIX

| Topic | Verified value | Verification date |
| --- | --- | --- |
| Docs | https://developers.google.com/webmaster-tools/v1/searchanalytics/query | 2026-08-13 |
| rowLimit | 1–25,000 (default 1,000) | 2026-08-13 |
| Pagination | startRow zero-based offset | 2026-08-13 |
| Date semantics | Inclusive Y-m-d in PT | 2026-08-13 |
| dataState | final / all / hourly_all | 2026-08-13 |
| Ordering | by clicks desc; by date asc when date dimension | 2026-08-13 |
| Completeness | API does not guarantee all rows; top ones | 2026-08-13 |
| Load/quota | Load-based limits; page/query grouping costlier | 2026-08-13 |

## URL INSPECTION MATRIX

| Requirement | Eligibility | Target source | Provider call | Quota class | Snapshot/history | Stored fields | Proves | Does NOT prove |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GSC_INDEX_URL_INSPECTION_TABLE | verified property + priority URL targets | Collection start context `url_inspection_targets` only | urlInspection.index.inspect | per-run budget config | NK includes inspected_at | verdict, coverage, robots, indexing, crawl, google/user canonical, fetch state, link | Point-in-time index-status observation for inspected URL | Site-wide indexed totals; live URL test; exhaustive inventory |

## SITEMAP SEMANTIC MATRIX

| Field | Stored? | Meaning | Can infer indexing? | Notes |
| --- | --- | --- | --- | --- |
| path | yes (sitemap_path) | Sitemap identity | no | |
| lastSubmitted / lastDownloaded | metadata | Provider timestamps | no | |
| isPending / isSitemapsIndex / type | metadata | Provider flags | no | |
| warnings / errors | metadata | Provider counts | no | |
| contents[].submitted | metadata | Submitted content counts | **no** | Submitted ≠ indexed |
| contents[].indexed | **no** (ignored) | Deprecated | **no** | Hard rule: not canonical |

## COMPLETENESS MATRIX

| Dataset | Request execution complete? | Provider universe exhaustive? | Potential omission? | Materialization interpretation |
| --- | --- | --- | --- | --- |
| Search Analytics datasets | Yes when slices/pages finish | **No** | anonymized queries, top-row ceiling, load drops | AVAILABLE/PARTIAL for execution/coverage; metadata marks TOP_ROWS_LIMITED |
| Sitemaps | Yes after list | N/A (metadata snapshot) | provider omit | Snapshot available |
| URL Inspection | Yes for planned targets | No (sample only) | unlisted URLs | Controlled sample only |
