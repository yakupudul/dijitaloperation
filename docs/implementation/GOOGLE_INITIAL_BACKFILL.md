# GOOGLE INITIAL BACKFILL

Prompt 20 — Google Initial Backfill Orchestrator.

Status: **REAL** (planning + execution + Integrations UX). Does **not** migrate specialist analytical UI. Does **not** implement recurring/incremental collection. Does **not** fabricate GBP analytical collection.

## 1. Purpose

One operator **Collect Data** action on the frozen Google Integration creates **one canonical `CollectionRun` per eligible Brand**. Each Brand-scoped run plans and executes independent Search Console, GA4, and Google Ads DatasetRuns through the shared Collection Engine. Same-brand siblings share that Brand’s run; other Brands are not discarded.

## 2. Operator Flow

```text
/app/integrations → Google → Authorized → Resources discovered
→ Human-confirmed bindings → Collect Data
→ GoogleInitialBackfillOrchestrator preflight + start
→ CollectionRun (trigger: initial_backfill)
→ ResourceRuns / DatasetRuns (background)
→ Prompt 11 MonitoringPanel (Activity tab + Integrations hub)
```

No new top-level navigation.

## 3. Relationship to Resource Selection

Prompt 16 already requires human confirmation. Collect Data uses current active bindings only. Discovered-but-unbound resources are never scheduled. Collect Data never modifies bindings.

## 4. Collection Intent

`CollectionTriggerType::InitialBackfill` (`initial_backfill`).

Persisted on `CollectionRun.trigger_type` plus `metadata.collection_intent` / `collection_intent_label` (`Initial Google Collection`). Intent is not inferred from date range.

## 5. Backfill Preflight

`GoogleCollectionPreflightService` answers without analytical provider HTTP:

- active human-confirmed Google bindings
- connector authorization / scope / Ads developer-token readiness
- production collector availability
- registry-driven planned datasets + already-satisfied datasets
- action-required and GBP collector-unavailable dispositions
- fingerprint for active-equivalent protection

## 6. Bound Resource Resolution

Server-side only under the Google `CoreIntegration`:

| Capability | Provider | Eligible for analytical backfill |
| --- | --- | --- |
| `search_console` | SEARCH_CONSOLE | When auth + scope + collector ready |
| `ga4` | GA4 | When auth + scope + collector ready |
| `google_ads` | GOOGLE_ADS | When auth + scope + developer token + collector ready |
| `google_business_profile` | GBP | Binding may exist; analytical DatasetRuns **not** planned |

## 7. Connector Readiness

See **Connector Readiness Matrix** below. One unavailable connector does not block eligible ones.

## 8. Data Contract Planning

`CollectionPlanner` reads `MOXDOP_DATA_CONTRACT_REGISTRY_V1`. No hardcoded Google dataset list. Requirement levels REQUIRED / OPTIONAL / CONDITIONAL preserved. DEFERRED / UNSUPPORTED / UNAVAILABLE / DEMO_ONLY families do not create executable DatasetRuns. Conditional URL Inspection remains `not_eligible` without priority targets.

## 9. Historical Range Resolution

`HistoricalRangeResolver` + `CollectionClock` resolve per-requirement `historical_depth` tokens (`180d`, `180d_ui_minimum`, `provider_16m_available`, `current`, priority tokens). No universal Google history window. No timezone rebucketing — collectors keep provider reporting semantics.

## 10. Existing Materialization

`CoverageSatisfactionChecker` inspects `DatasetMaterialization` before planning:

- never collected → full initial target
- partial / incomplete → continuation range (no truncate)
- fully satisfied → `already_satisfied` / DatasetRun `skipped`
- force refresh only when explicitly requested (not default Collect Data)

## 11. Plan Persistence

`CollectionRun.plan_snapshot` stores resources, datasets (requirement IDs, disposition, date_range, coverage_target), dispositions, contract version, planner version. Historical runs are not rebuilt from today’s registry.

## 12. CollectionRun Structure

One operator Collect Data → **one `CollectionRun` per eligible Brand** under the Integration. Same-brand GSC/GA4/Ads siblings share that Brand’s run (`allow_multi_asset_bindings`). Other Brands receive their own Brand-scoped CollectionRun rather than being dropped from a single first-anchor plan. Cross-customer resources never share a CollectionRun. Meta same-customer multi-brand behavior is unchanged.

Incremental refresh after initial satisfaction uses the same Brand-scoped binding IDs for **due selection**, not only the website/GSC anchor Digital Asset. `StartIncrementalCollectionService` queries `DueCollectionQueryService` with `core_asset_binding_ids` from Google preflight so a sibling GA4/Ads dataset can start even when the anchor itself is DATA CURRENT. Multi-Brand incremental results expose every Brand outcome (started / reused / DATA CURRENT / action required) rather than reporting only the first started run.

## 13. ResourceRuns

One ResourceRun per eligible binding (GSC property / GA4 property / Ads customer). Multiple resources per connector supported.

## 14. DatasetRuns

Created from registry request families for each ResourceRun. Per-dataset `metadata.date_range` / `coverage_target` / `plan_disposition` / `requirement_ids`. Collectors prefer dataset `metadata.date_range` over run envelope.

## 15. Parallel Execution

No serial GSC→GA4→Ads chain. Independent DatasetRuns fan out via Prompt 9 queue topology subject to Prompt 12 concurrency/rate limits.

## 16. Provider Failure Isolation

Dataset / resource / connector failures do not terminate unrelated siblings. Parent aggregates to PARTIAL when required failures coexist with success.

## 17. Retry / Resume

Shared Prompt 12 retry/resume/checkpoints. Orchestrator does not interpret provider checkpoints. Successful siblings are not recollected by retry-failed-parts.

## 18. Browser Independence

Start returns after persist + dispatch. Browser close / navigation away does not cancel. Monitoring reconstructs from DB.

## 19. Progress UX

Reuse `MonitoringPanel` + `CollectionRunMonitorQuery`. Connector/resource percentages are **DATASET-PLAN COMPLETION** (`CollectionProgressPresenter::connectorPlanCompletion`). No fake Google row-transfer percentage.

## 20. Materialization / Coverage

Prompt 10 `MaterializationService` remains owner. Failed refresh does not erase prior usable pool state. Coverage gaps remain explicit (`partial`).

## 21. Idempotency

- Active equivalent fingerprint → reuse non-terminal run
- Idempotency key protects double-submit races
- Terminal prior run allows intentional later collection with a new key
- Normalized facts remain upsert-idempotent (CollectionRun is not part of fact identity)

## 22. Cancellation

Shared `CancellationService`. Cooperative. Completed data preserved. Delayed jobs observe cancellation before provider work.

## 23. Historical Runs

Initial Google Collection appears in Prompt 11 history with trigger label and immutable plan snapshot.

## 24. GBP Boundary

Discovery/binding may be REAL. Analytical production collector is **not** present → disposition `collector_unavailable`, no analytical DatasetRuns, does not block GSC/GA4/Ads, no fake “0 rows”.

## 25. Security

Only authorized operators start collection. Bindings resolved server-side. Plans/jobs carry IDs only — never access/refresh/developer tokens.

## 26. Tests

`tests/Feature/Collection/GoogleInitialBackfillOrchestratorTest.php` + `tests/Unit/Collection/HistoricalRangeResolverTest.php`. Automated suite makes **0** live Google calls.

## 27. Reality Matrix

See Milestone 5 Capability Reality Matrix (Prompt 20 rows).

## 28. Prompt 26 / 27 Handoff

- Prompt 26: Integrity & Reconciliation (coverage gaps preserved)
- Prompt 27: Data Freshness & Incremental Collection (not this milestone)
- Prompts 28–30: specialist real-data UI migration (not this milestone)

## 29. Definition of Done

Selected Google resources → contract-driven initial backfill plan → one CollectionRun → independent GSC/GA4/Ads DatasetRuns → raw + normalized pool + materializations → persistent monitoring/history — without rewriting collectors or fabricating GBP analytics.

---

## CONNECTOR READINESS MATRIX

| Connector | Authorization | External app | ExternalResource | Binding | Production collector | Initial backfill eligible? | Action-required cases |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Search Console | Google OAuth + webmasters.readonly | Client ID/Secret | GSC property | Active human-confirmed | REAL (Prompt 17) | YES when ready | Auth/scope missing |
| GA4 | Google OAuth + analytics.readonly | Client ID/Secret | GA4 property | Active human-confirmed | REAL (Prompt 18) | YES when ready | Auth/scope missing |
| Google Ads | Google OAuth + adwords | Client ID/Secret + **developer token** | Ads customer (non-manager for perf) | Active human-confirmed | REAL (Prompt 19) | YES when ready | Auth/scope/developer-token missing |
| GBP | May be authorized | Client ID/Secret | GBP location | May be bound | **NO analytical collector** | **NO** | Collector unavailable (not a binding failure) |

## BACKFILL RANGE MATRIX

| Dataset ID (examples) | Provider | Resource type | Initial depth source | Snapshot vs historical | Range resolver | Existing materialization | Provider limitation | Collector owner |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `gsc_*_daily` | SEARCH_CONSOLE | GSC property | Registry `180d_ui_minimum` / `provider_16m_available` | historical | HistoricalRangeResolver | skip/continue via CoverageSatisfactionChecker | 16m ceiling token | SearchConsoleDatasetExecutor |
| `gsc_sitemap_snapshot` | SEARCH_CONSOLE | GSC property | registry / none | snapshot-ish | family semantics | satisfied if collected | provider list | SearchConsoleDatasetExecutor |
| `ga4_property_metadata` | GA4 | GA4 property | `current` | snapshot | HistoricalRangeResolver | skip if AVAILABLE | n/a | Ga4DatasetExecutor |
| `ga4_*_daily` | GA4 | GA4 property | `180d` | historical | HistoricalRangeResolver | skip/continue | property TZ | Ga4DatasetExecutor |
| `google_ads_*_snapshot` | GOOGLE_ADS | Ads customer | `current` | snapshot | HistoricalRangeResolver | skip if AVAILABLE | customer TZ | GoogleAdsDatasetExecutor |
| `google_ads_*_daily` | GOOGLE_ADS | Ads customer | `180d` | historical | HistoricalRangeResolver | skip/continue | customer TZ | GoogleAdsDatasetExecutor |

Exact dataset membership always comes from the Registry — this matrix documents policy ownership, not a second catalog.

## PLAN DISPOSITION MATRIX

| Disposition | Creates executable DatasetRun? | Notes |
| --- | --- | --- |
| `eligible` | YES (`queued`) | Work remains |
| `not_eligible` | NO (status `not_eligible`) | e.g. URL Inspection without targets — not a failure |
| `already_satisfied` | NO (status `skipped`) | Materialization covers initial target |
| `action_required` | NO | Connector config/auth/scope; siblings may proceed |
| `collector_unavailable` | NO | GBP analytical collector absent |
| `unsupported` / `deferred` | NO | Registry family status |
| `skipped_provider_filter` / `skipped_source_contract` | NO | Scope filter / GSC appearance exclusion |

## MATERIALIZATION DECISION MATRIX

| Current pool state | Initial target | Action |
| --- | --- | --- |
| NOT_COLLECTED | any | Schedule full target |
| AVAILABLE full coverage | historical/snapshot | `already_satisfied` |
| PARTIAL / incomplete bounds | historical | Schedule missing/continuation range |
| STALE with usable coverage | historical | Continue/refresh missing only (no wipe) |
| Previous failed refresh | prior AVAILABLE | Preserve prior; schedule unfinished work |
| Old contract version missing new dataset | new requirement | Schedule new requirement only |
| Zero-row successful dataset | snapshot/historical complete | Remains COMPLETED / AVAILABLE — not “missing” |

Destructive restart: **NO**.

## FAILURE ISOLATION MATRIX

| Failure | Affected dataset | Affected resource | Sibling datasets | Other connectors | Parent | Existing data |
| --- | --- | --- | --- | --- | --- | --- |
| GSC required dataset terminal | that DatasetRun | may PARTIAL | continue | continue | PARTIAL if siblings ok | preserved |
| GA4 auth/access | GA4 datasets | GA4 resource | continue | GSC/Ads continue | PARTIAL/FAILED per rules | preserved |
| Ads developer-token at preflight | Ads not planned | n/a | n/a | GSC/GA4 collect | n/a (no empty success run) | preserved |
| Optional failure | that DatasetRun | may still COMPLETED | continue | continue | may COMPLETED | preserved |
| Explicit dependency failure | dependents blocked/skipped | — | independents continue | continue | aggregate | preserved |

## PROGRESS SEMANTICS MATRIX

| Level | Denominator | Percentage permitted? | Records shown? | Meaning |
| --- | --- | --- | --- | --- |
| CollectionRun | executable planned DatasetRuns | YES (dataset-plan) | sum rows received/written | DATASET-PLAN COMPLETION |
| Connector | executable DatasetRuns across resources | YES | optional sums | DATASET-PLAN COMPLETION |
| ResourceRun | executable DatasetRuns on resource | YES | optional sums | DATASET-PLAN COMPLETION |
| DatasetRun | ProgressMode total when known | only when mode allows + total known | rows received/written | transfer/work progress — not Google universe % |

## GBP BOUNDARY MATRIX

| State | Reality |
| --- | --- |
| GBP OAuth | REAL (when scope enabled/granted) |
| GBP resource discovery | REAL (Prompt 15) |
| GBP resource Binding | REAL if Prompt 16 supports it |
| GBP analytical production collector | **NOT REAL** |
| GBP initial analytical backfill | **NO** |
| Fake zero-data presentation | **NO** |
| Blocks GSC/GA4/Ads | **NO** |
