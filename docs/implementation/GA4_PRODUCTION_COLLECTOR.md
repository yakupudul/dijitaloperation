# GA4 PRODUCTION COLLECTOR

## 1. Purpose

Prompt 18 implements the production Google Analytics 4 collector: human-confirmed bound GA4 Properties populate the canonical MoxDOP data pool with real GA4 provider facts through the shared Collection Engine.

## 2. Contract Boundary

The collector implements only:

- `docs/data-contracts/GA4_DATA_CONTRACT_V1.md`
- `docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json`
- physical storage from `docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.json`
- formula ownership remains in `docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json`

Google Analytics API capability does not expand MoxDOP product need. No Realtime, Funnel Reporting, Audience Export, or BigQuery Export without an explicit Registry request family.

Storage gap: `ga4_event_source_medium_daily` is `STORAGE_CONTRACT_GAP` — excluded from `GA4_RF_EVENT_BREAKDOWNS` collection (no invented grain).

## 3. Binding Eligibility

Collection requires:

1. Active Google Integration (usable auth status)
2. GA4 Analytics read scope via `GoogleScopeRegistry` / coverage checks
3. Discovered `GA4` / `ga4` ExternalResource with exact Property ID (`properties/{id}` → numeric `property_id`)
4. Human-confirmed active `CoreAssetBinding`
5. Tenant/Customer/Brand/DigitalAsset/Resource/Binding scope consistency

Discovered-only Properties are not collection-eligible. Property ≠ Data Stream; streams are metadata context only.

## 4. Credential Path

All API calls use `Ga4ApiClient` → `GoogleApiClient` → `GoogleCredentialBroker`.

- No token decrypt in collector
- No token refresh in collector
- No tokens in DatasetRun, queue payload, checkpoint, or raw metadata

## 5. Property Metadata

`GA4_RF_PROPERTY_METADATA` uses Analytics Admin `properties.get` + `dataStreams.list` for contract-defined context:

- Property ID, display name, reporting timezone, currency, property type
- Stream summaries as metadata only (`data_stream_is_not_collection_root = true`)

Not mirrored: entire Admin API object. Speculative Admin fields are not collected.

## 6. Metadata / Compatibility Validation

- Data API `properties/{property}/metadata` validates availability of contract dimensions/metrics
- Data API `checkCompatibility` validates required combinations before historical `runReport`
- Property-scoped cache (`config/moxdop-ga4-collector.php` TTLs); pagination pages do not re-call compatibility
- Metadata never auto-adds dimensions/metrics
- Required incompatible → terminal `CONTRACT_MISMATCH` / provider incompatible (no silent field removal)
- Arbitrary custom dimensions/metrics are rejected unless contract + metadata + compatibility all allow (V1: not contracted)

## 7. Collector Architecture

```
Human-confirmed GA4 Binding
  → CollectionPlanner (provider GA4)
  → DatasetRuns (request-family IDs)
  → Ga4DatasetExecutor
  → Eligibility + Metadata/Compatibility
  → GoogleCredentialBroker / Analytics Data|Admin API
  → RawPayloadWriter
  → Ga4Normalizer
  → NormalizedDatasetBatch
  → WarehouseWriter
  → PostgreSQL GA4 pool
  → Dataset Materialization
```

Registered via `collection.dataset_executors` tag. No second `Ga4CollectionEngine`.

`batchRunReports`: **not used**. Decision (2026-08-13): simpler per–DatasetRun `runReport` preserves failure isolation and request traceability; transport batching would risk collapsing domain isolation.

## 8. Request Family Mapping

See **Request Family Matrix** below.

## 9. GA4 Scope Semantics

- Session acquisition uses `session*` dimensions only (`sessionDefaultChannelGroup`, `sessionSourceMedium`, `sessionCampaignName`)
- First-user (`firstUser*`) acquisition is **not** in Data Contract V1 collection surface and is rejected by the request builder
- Event-scoped facts stay on event datasets; no inferred attribution joins at ingest
- GA4 campaign string ≠ Google Ads Campaign entity

## 10. Reporting Timezone

GA4 Property `timeZone` defines reporting-day semantics. Stored on facts as `source_timezone` / materialization metadata. Provider `date` → `reporting_date` (YYYY-MM-DD) without UTC or Brand timezone rebucketing.

Historical timezone changes do not rewrite prior fact dates; provenance retains property timezone observed at collection.

## 11. Currency

Property `currencyCode` retained in metadata for monetary context. No FX conversion. No mixed-currency aggregation in collector. V1 property-daily metrics are non-monetary counts/durations.

## 12. Historical Backfill

Historical depth comes from CollectionPlan / `StartCollectionRequest.dateRange` + contract policy. Collector does not hardcode “last N months”. Prompt 20 owns coordinated Google initial backfill orchestration.

## 13. Date Slicing

Config-driven (`config/moxdop-ga4-collector.php`):

| Family | Default slice days |
| --- | --- |
| Property / Device / Generic range users | 28 |
| Channel | 7 |
| Source/Medium, Campaign, Landing, Event, Event breakdowns | 1 |

Inclusive, contiguous, non-overlapping. Retries may re-read slices (idempotent upserts).

## 14. Pagination

Official Core Reporting: `limit` + `offset` + `rowCount` (verified 2026-08-13). Max limit centralized (`Ga4ProviderCapabilities::MAX_ROW_LIMIT = 250000`). Default page size 10000. Checkpoint stores `slice_index`, `offset`, row/page totals. Entire backfill is never loaded into PHP memory.

## 15. Property Daily Facts

`GA4_RF_PROPERTY_DAILY` — dimensions `[date]`; metrics sessions, engagedSessions, screenPageViews, userEngagementDuration, totalUsers, activeUsers. Direct property grain — not summed from lower-grain rows.

## 16. Acquisition

Session-scoped families only:

- Channel → `sessionDefaultChannelGroup`
- Source/Medium → `sessionSourceMedium` split into `sessionSource` / `sessionMedium` for storage
- Campaign → `sessionCampaignName`

Provider values `(direct)`, `(not set)`, `Unassigned` preserved. No first-user families in V1.

## 17. Behavior

Property daily + device daily supply engagement/views base facts. Aggregate reporting only — no user-level trails, clientId, userId, userPseudoId, or advertising IDs.

## 18. Landing Pages

`GA4_RF_LANDING_PAGE_DAILY` uses exact dimension `landingPage` (not `landingPagePlusQueryString` / pagePath). Provider value preserved; no Website URL canonicalization or join at ingest.

## 19. Events

`GA4_RF_EVENT_DAILY`: property × date × eventName × eventCount. Exact provider event names. Missing row ≠ zero. No arbitrary event parameters. No rename/cluster/classify at ingest.

## 20. Key Events

Key-event provider facts, when present in contracted metrics/metadata, remain GA4 measurement facts. Hard rule: Key Event ≠ Business Outcome. Metadata flags `key_event_is_business_outcome = false`.

## 21. Business Action Inputs

Collector stores event provider facts as mapping **inputs** only.

- Mapping applied during collection: **NO**
- Missing mapping → not written as Business Actions = 0
- Business Action Rate / Business Outcomes: Formula / mapping layers later — not collector

## 22. Journeys Boundary

Frozen UI “Journeys” does not authorize Funnel Reporting API. No funnel/path person-level export. Aggregate families only as Registry defines. Funnel definition dataset remains DEFERRED.

## 23. Provider Response Metadata

Operational/useful fields only: timezone, currency, thresholding/sampling indicators when present, propertyQuota when `returnPropertyQuota` requested, empty/zero-row success. Thresholded/omitted ≠ zero.

## 24. Quota / Concurrency

Uses current Data API property token / concurrency / server-error semantics (see Quota Matrix). Errors map to Prompt 12 categories. No blocking sleep / private retry loops. Sibling DatasetRuns isolated across families and vs GSC/Ads.

## 25. Raw Payload

`RawPayloadWriter` when disposition requires / pipeline writes. Envelope: provider GA4, run IDs, family, property, date slice, offset, request fingerprint, captured_at, safe metadata. No Authorization / tokens.

## 26. Normalization

`Ga4Normalizer` validates dimension/metric headers against request family; converts types; preserves special provider strings; no physical table names.

## 27. Warehouse Persistence

`DatasetWritePipeline` → `WarehouseWriter` upsert by Storage Contract natural keys. Collector never references `ga4_*` table names for writes. CollectionRun is provenance (`last_collection_run_id`), not natural key.

## 28. Checkpoint / Resume

Advance only after durable WriteReceipt (or successful zero-row page + raw). Fields: slice_index, offset, pages/rows totals, timezone, completeness markers. No secrets.

## 29. Idempotency

Natural keys exclude CollectionRun. Retry / replay / late correction upsert. Same page after crash-before-checkpoint does not duplicate facts.

## 30. Materialization / Coverage

Per-dataset coverage from successfully written reporting dates. Partial slices remain. Failed refresh preserves prior Available/Partial. Zero-row success ≠ NOT_COLLECTED.

## 31. Progress / Monitoring

Slice completion when planned; offset/rowCount for current report; rows received/written. No fake overall API percentage. Prompt 11 Integrations monitoring may show real GA4 DatasetRun state. Specialist GA4 UI remains Demo until Prompt 28.

## 32. Privacy

Aggregate reports only. No user-level export, visitor PII, form content, or raw event streams. Synthetic fixtures in tests.

## 33. Tests

- `tests/Feature/Collection/Ga4ProductionCollectorTest.php`
- `tests/Unit/Collection/Ga4DateSlicerTest.php`

0 live Google calls in automated suite.

## 34. Provider Limitations

Verified 2026-08-13 against official Analytics Data API / Admin docs: runReport limit/offset/rowCount, getMetadata, checkCompatibility, property timezone, propertyQuota, keepEmptyRows. High-cardinality families use daily slices. Provider report bounded ≠ exhaustive universe guarantee.

## 35. Reality Matrix

See Milestone 5 Capability Reality Matrix: GA4 Production Collector / Metadata Compatibility / Property Daily / Acquisition / Behavior / Landing / Events / BA inputs / normalized pool = REAL; specialist UI = NOT YET; Google Ads collector = NOT YET.

## 36. Prompt 20 / Prompt 28 Handoff

- Prompt 19: Google Ads Production Collector
- Prompt 20: coordinated Google Initial Backfill Orchestration
- Prompt 27: incremental freshness policy
- Prompt 28: GA4 specialist UI real-data migration
- Prompt 38: Evidence canonicalization

## 37. Definition of Done

Human-confirmed GA4 Binding → CollectionPlan → contract GA4 DatasetRuns → metadata + compatibility → runReport slices + pagination → raw → normalizer → WarehouseWriter → real GA4 pool → materialization. Provider events remain inputs for later Business Action mapping.

---

## REQUEST FAMILY MATRIX

| Requirement IDs (representative) | Request Family | Consumer / Dataset | Dimensions | Metrics | Scope | Date slice | Pagination | Compat | Raw | Physical storage | Level |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4 property config | GA4_RF_PROPERTY_METADATA | ga4_property_metadata | — | Admin get + streams | property_config | n/a | n/a | n/a | yes | ga4_property_metadata | REQUIRED |
| Overview / behavior base | GA4_RF_PROPERTY_DAILY | ga4_property_daily | date | sessions, engagedSessions, screenPageViews, userEngagementDuration, totalUsers, activeUsers | property | 28d | offset/limit | yes | yes | ga4_property_daily | REQUIRED |
| Acquisition channel | GA4_RF_CHANNEL_DAILY | ga4_acquisition_channel_daily | date, sessionDefaultChannelGroup | sessions, engagedSessions | session | 7d | offset/limit | yes | yes | ga4_acquisition_channel_daily | REQUIRED |
| Acquisition source/medium | GA4_RF_SOURCE_MEDIUM_DAILY | ga4_source_medium_daily | date, sessionSourceMedium | sessions | session | 1d | offset/limit | yes | yes | ga4_source_medium_daily | REQUIRED |
| Acquisition campaign | GA4_RF_CAMPAIGN_DAILY | ga4_campaign_daily | date, sessionCampaignName | sessions | session | 1d | offset/limit | yes | yes | ga4_campaign_daily | REQUIRED |
| Landing | GA4_RF_LANDING_PAGE_DAILY | ga4_landing_page_daily | date, landingPage | sessions, engagedSessions | session_entry | 1d | offset/limit | yes | yes | ga4_landing_page_daily | REQUIRED |
| Events | GA4_RF_EVENT_DAILY | ga4_event_daily | date, eventName | eventCount | event | 1d | offset/limit | yes | yes | ga4_event_daily | REQUIRED |
| Event breakdowns | GA4_RF_EVENT_BREAKDOWNS | event×channel/campaign/landing | date + scoped dim + eventName | eventCount | event×session dim | 1d | offset/limit | yes | yes | ga4_event_*_daily (excl. source_medium gap) | REQUIRED |
| Device | GA4_RF_DEVICE_DAILY | ga4_device_daily | date, deviceCategory | sessions | device | 28d | offset/limit | yes | yes | ga4_device_daily | REQUIRED |
| Overview users (range) | GA4_RF_GENERIC_REPORT | raw / range users | — (no date dim) | totalUsers, activeUsers | property_range | 28d | single/bounded | yes | yes | raw (not daily fact table) | REQUIRED shell |

## ACQUISITION SEMANTICS MATRIX

| Dataset | Dimension | Scope | Meaning | NOT equivalent to | Consumer |
| --- | --- | --- | --- | --- | --- |
| ga4_acquisition_channel_daily | sessionDefaultChannelGroup | Session | Session channel group | firstUserDefaultChannelGroup | Acquisition |
| ga4_source_medium_daily | sessionSource / sessionMedium (from sessionSourceMedium) | Session | Session source/medium | firstUserSource/Medium; event-scoped traffic source | Acquisition |
| ga4_campaign_daily | sessionCampaignName | Session | Session campaign string | Google Ads Campaign entity; firstUserCampaignName | Acquisition |
| ga4_event_*_daily | session* dims × eventName | Event × session dim | Event counts by session context | Causal ads attribution; Business Outcome | Behavior / BA inputs |
| — | firstUser* | First-user | User acquisition | Not collected in V1 | — |

## METADATA / COMPATIBILITY MATRIX

| Request Family | Required dims/metrics | Metadata availability | Compatibility | Cache | Failure behavior | Contract impact |
| --- | --- | --- | --- | --- | --- | --- |
| All run_report / breakdowns / range_users | Catalog definition | getMetadata must list each | checkCompatibility before first page of family | property + family + dim/metric set + contract version | Required incompatible → terminal mismatch; no field stripping | Semantics preserved |
| PROPERTY_METADATA | Admin fields | Admin get | n/a | property context cache | Auth/scope failures → action-required categories | Config context only |

## BUSINESS ACTION INPUT MATRIX

| GA4 Provider Input | Normalized Dataset | Provider Meaning | BA Mapping Required? | Mapping in Collector? | Business Outcome? |
| --- | --- | --- | --- | --- | --- |
| eventName + eventCount | ga4_event_daily (+ breakdowns) | Provider event aggregates | Yes (later) | **NO** | **NO** |
| Key event indicators (if present) | metadata / provider facts | GA4 measurement | Mapping separate | **NO** | **NO** |

## TIMEZONE MATRIX

| Property | Provider TZ source | Stored fact date | collected_at | Brand TZ impact | Server UTC impact | Historical TZ change |
| --- | --- | --- | --- | --- | --- | --- |
| Bound GA4 Property | Admin `timeZone` | Provider reporting `date` → `reporting_date` | Wall-clock collection timestamp | **No rebucket** | **No rebucket** | Do not rewrite prior dates; provenance explains semantics |

## FORMULA BOUNDARY MATRIX

| Metric | Provider base? | Provider ratio? | MoxDOP derived? | Stored? | Formula ID | Reaggregation |
| --- | --- | --- | --- | --- | --- | --- |
| sessions / engagedSessions / views / users / eventCount | Yes | — | No | Yes (facts) | — | Sum-safe bases per Storage |
| userEngagementDuration | Yes | — | Avg engagement uses Formula later | Yes | GA4 engagement formulas | Do not average rates across days in collector |
| Engagement Rate | Bases yes | Provider rate not canonical | Yes (Formula Registry) | Bases only | Formula Registry | Canonical reaggregation via Formula |
| Business Action Rate | Event inputs | — | Yes after mapping | Events only | Formula Registry | Mapping layer separate |
| Period deltas | — | — | Yes | No in collector | Formula Registry | Not materialised at ingest |

## QUOTA MATRIX

| Topic | Verified semantics | Verification date |
| --- | --- | --- |
| Core docs | Analytics Data API Core Reporting / runReport | 2026-08-13 |
| Pagination | limit, offset, rowCount; max limit 250,000 | 2026-08-13 |
| returnPropertyQuota | Request flag; propertyQuota on response | 2026-08-13 |
| Quota model | Property token / concurrent request / server-error categories (official Data API quota docs) | 2026-08-13 |
| High cardinality | Increases cost; bounded by family + daily slices | 2026-08-13 |
| Retry | Shared Prompt 12 RetryPolicy; no busy loop | 2026-08-13 |
| Concurrency | Shared provider/resource policy; no unbounded fan-out per Property | 2026-08-13 |
