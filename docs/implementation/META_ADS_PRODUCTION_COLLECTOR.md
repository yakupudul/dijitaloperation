# META ADS PRODUCTION COLLECTOR

Verification date: **2026-08-13**  
Graph / Marketing API version: **v26.0**  
Branch capability: Prompt 24 — Collection Engine DatasetExecutor for Registry `RF_META_*`

## 1. Purpose

Populate the canonical MoxDOP Meta Ads data pool with **real**, contract-backed Marketing API facts for **human-confirmed bound** `META_AD_ACCOUNT` ExternalResources. Specialist Demo UI is **not** migrated.

## 2. Contract Boundary

| Document | Path | Role |
|---|---|---|
| Meta Data Contract | `docs/data-contracts/META_ADS_DATA_CONTRACT_V1.md` | Product need |
| Unified Registry | `docs/data-contracts/MOXDOP_DATA_CONTRACT_REGISTRY_V1.json` | Requirement / RF IDs |
| Storage Contract | `docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.json` | Physical tables / NK |
| Formula Registry | `docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json` | Derived metrics (not collector) |

Marketing API capability does **not** define product need. Every live request maps to a Registry Request Family.

## 3. Binding Eligibility

`MetaAdsEligibilityGuard` requires:

- Active `CoreAssetBinding` with capability `meta_ads`
- DigitalAsset type `meta_ads`
- ExternalResource type `META_AD_ACCOUNT` (`meta_ads`) with status available
- Active Meta Integration + resolvable access token via `MetaCredentialResolver`
- `ads_read` when grant set is validated

**Discovered-only accounts: NOT COLLECTABLE.**  
**META_BUSINESS: NOT an analytical collection root.**

## 4. Credential / Permission Path

| Path | Allowed |
|---|---|
| `MetaCredentialResolver` | YES |
| Decrypt token in collector | NO |
| Token on Ad Account / Business / DigitalAsset | NO |
| Token in DatasetRun / queue / checkpoint / raw metadata | NO |
| `MetaPermissionCoverageService` preflight | YES |

## 5. Marketing API Client

Reuse `App\Services\Integrations\Meta\MetaApiClient`:

- Configured Graph version (`MetaApiConfig` / v26.0)
- GET for sync list/insights/status
- POST **only** for read-only async AdReportRun creation (`act_*/insights`)
- Pagination via `paging.next` + host validation
- Error normalization via `MetaException` → `MetaAdsProviderErrorMapper`

## 6. Request Family Architecture

Registered executor: `MetaAdsDatasetExecutor` (tag `collection.dataset_executors`).

| Request Family | Status | Kind | Datasets | Sync/Async |
|---|---|---|---|---|
| `RF_META_AD_ACCOUNT_META` | COLLECTION_READY | ad_account_meta | `meta_ad_account_snapshot` | sync |
| `RF_META_ENTITY_SNAPSHOT` | COLLECTION_READY | entity_snapshot | campaign/adset/creative snapshots | sync |
| `RF_META_INSIGHTS_SYNC` | COLLECTION_READY | insights_sync | campaign/ad daily | sync |
| `RF_META_INSIGHTS_DAILY` | COLLECTION_READY | insights_daily | campaign/adset/ad daily | sync_then_async |
| `RF_META_TYPED_ACTIONS` | COLLECTION_READY | typed_actions | `meta_typed_action_daily` | sync (async escalate via strategy thresholds) |
| `RF_META_INSIGHTS_BREAKDOWN` | COLLECTION_READY | insights_breakdown | `meta_delivery_breakdown_daily` | async preferred |
| `RF_META_ASYNC_INSIGHTS` | **DEFERRED** | — | — | planner skips; async is transport |

## 7. Ad Account Metadata

GET `act_{id}?fields=id,name,account_status,currency,timezone_name,business{id,name}`.  
Canonical account_id = digits (Prompt 22). Currency + timezone preserved. Analytical facts are **not** stored on ExternalResource metadata by this collector.

## 8–11. Campaigns / Ad Sets / Ads / Creatives

Entity snapshot steps: `campaigns` → `adsets` → `ads` (creative id resolution only) → `adcreatives` (batched `id IN`).

| Entity | Stable ID | Parent | Snapshot table | Performance |
|---|---|---|---|---|
| Ad Account | account_id digits | — | `meta_ad_account_snapshot` | insights levels |
| Campaign | campaign_id | account | `meta_campaign_snapshot` | `meta_campaign_daily` |
| Ad Set | adset_id | campaign_id | `meta_adset_snapshot` | `meta_adset_daily` |
| Ad | ad_id | adset/campaign | *(no snapshot table — creative relation)* | `meta_ad_daily` |
| Creative | creative_id | referenced by Ad | `meta_creative_snapshot` | via Ad daily later |

No binary media download. Instagram/Page actor IDs may be retained as provider metadata only — **no** Instagram DigitalAsset/Binding.

## 12. Provider Entity Relationships

Ad Account → Campaign → Ad Set → Ad → references Creative.  
These are **not** CoreAssetBindings.

## 13–14. Objectives / Optimization Goals

| Concept | Level | Field | Business Goal? | Business Outcome? |
|---|---|---|---|---|
| Campaign objective | Campaign | `objective` | NO | NO |
| Ad Set optimization goal | Ad Set | `optimization_goal` | NO | NO |

Kept strictly distinct (`objective_neq_optimization_goal` provenance flag).

## 15. Status Semantics

| Concept | Stored | Combined? |
|---|---|---|
| Configured `status` | YES in metadata | NO |
| `effective_status` | YES separately | NO |

Current-state snapshots (UPSERT_CURRENT_STATE). No invented historical status tables.

## 16. Budget Semantics

| Field | Level | Unit | Spend? |
|---|---|---|---|
| `daily_budget` / `lifetime_budget` | Campaign and/or Ad Set | Account minor units ÷ 100 → major decimal | NO |
| Insights `spend` | Daily facts | Provider decimal major units | YES (performance) |

**Not Google Ads micros.** No FX. Campaign budget is not copied onto Ad Sets.

## 17. Destination Semantics

| Type | Provider fields | Business Action? | Business Outcome? |
|---|---|---|---|
| Website | `destination_type`, creative `link_url` / `object_story_spec.link_data.link` | NO | NO |
| Lead Form | destination_type / form refs if returned | NO (no lead rows) | NO |
| Messaging | destination_type | NO (no messages) | NO |
| App / Page / IG actor | provider ids in creative metadata | NO | NO |

No inference from names/copy.

## 18–20. Insights / Daily Facts / Actions

- Levels: campaign / adset / ad (and account for breakdowns)
- `time_increment=1`, explicit `time_range` from CollectionPlan
- Provider `date_start` preserved (account TZ reporting date)
- Typed `actions[]` / `action_values[]` by `action_type`
- **No** generic Results total
- Attribution: `use_unified_attribution_setting=true` (contract)

## 21. Attribution Semantics

| Family | Setting | Natural-key impact |
|---|---|---|
| Insights / Typed Actions | unified attribution requested | Single semantics per contract; provenance in metadata |

Different attribution modes are not collected in V1; ambiguity would fail closed rather than invent defaults.

## 22. Click Metric Semantics

`clicks` ≠ `inline_link_clicks` ≠ `outbound_clicks` — stored distinctly.

## 23. Reach / Frequency Non-Additivity

Daily reach/frequency stored as provider observations with `reach_non_additive` / `frequency_non_additive` flags. Collector never sums reach or averages frequency into period metrics.

## 24–25. Currency / Timezone

Ad Account currency + timezone are source truth. No FX. No UTC/Brand/GA4/Ads rebucketing at ingestion.

## 26. Historical Metric Availability

Contract depth comes from Registry + CollectionPlan. Provider-bounded completeness annotated (`PROVIDER_REPORT_BOUNDED`). Unavailable history is **not** fabricated as zero.

## 27. Breakdown Compatibility

`RF_META_INSIGHTS_BREAKDOWN` only: `age`, `gender`, `publisher_platform` (placement).  
Country / device / non-contract breakdowns: **never requested**. Separate calls per dimension.

## 28–31. Sync / Async Insights

| Mode | When | Notes |
|---|---|---|
| SYNC | Bounded families / short spans | Full pagination |
| ASYNC | High cardinality / long spans / breakdowns / sync escalate | POST AdReportRun → poll → download pages |

Provider job completion ≠ DatasetRun completion. Polling uses delayed Continue (`backoffSeconds`) — **no blocking sleep**. Browser close does not stop queue work.

## 32. Date Slicing

Configurable per family (`config/moxdop-meta-ads-collector.php`). Inclusive, gap-free slices in account timezone. Async does not mean unbounded.

## 33. Provider Quota / Rate Limits

Mapped to shared `CollectionErrorCategory::RateLimit` + RetryPolicy. No Meta-specific retry loops.

## 34–36. Raw / Normalization / Warehouse

RawPayloadWriter envelopes (no tokens) → family normalizers → `NormalizedDatasetBatch` → `DatasetWritePipeline` / WarehouseWriter. Collector does not know physical table names at write sites beyond logical dataset IDs resolved by storage registry.

## 37. Request Fingerprints

SHA-256 over API version, account, family, normalized fields/query, slice, mode, attribution. No secrets.

## 38–39. Checkpoint / Idempotency

Checkpoints advance only after durable write commit (pipeline). Natural keys from Storage Contract. Async `report_run_id` reused for duplicate-submit protection. Late corrections upsert same NK.

## 40–42. Materialization / Progress / Failure Isolation

Shared Prompt 10–12 materialization + monitoring. Dataset failures do not terminate siblings; Meta does not affect Google. Zero-row success ≠ failure.

## 43–44. Privacy / Actor Boundary

Aggregate ads reporting only. No leads/PII/messages/comments/custom audience members. Instagram/Page actors are metadata only.

## 45. Tests

`tests/Feature/Collection/MetaAdsProductionCollectorTest.php` — synthetic Http::fake only; **0** live Meta calls.

## 46. Provider Limitations

- Async job expiry → bounded resubmit using fingerprint + NK idempotency
- Metric history windows may be shorter than contract desire → explicit limitation metadata
- `RF_META_ASYNC_INSIGHTS` remains DEFERRED as a standalone family

## 47. Reality Matrix

See `docs/implementation/MILESTONE_5_PANEL_FREEZE.md` + section below.

## 48. Prompt 25 Handoff

Prompt 25 owns Meta Initial Backfill orchestration over these production Request Families. Do not hardcode recurring lookbacks here.

## 49. Definition of Done

See Prompt 24 §344 invariants — all YES for PASS.

---

## REQUEST FAMILY MATRIX

| Requirement IDs (primary) | RF | Endpoint | Level | Fields/Metrics | BD | Action BD | Attribution | Time inc | Grain | History source | Slice | Mode | Raw | Logical DS | Physical | Level |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| META_ACCOUNT_* | RF_META_AD_ACCOUNT_META | GET act_* | account | name,status,currency,tz,business | — | — | n/a | n/a | account | n/a | n/a | sync | yes | meta_ad_account_snapshot | same | Required |
| META_CAMPAIGN_* / ADSET_* / CREATIVE_* | RF_META_ENTITY_SNAPSHOT | campaigns/adsets/ads/adcreatives | entity | contract config fields | — | — | n/a | snapshot | entity | current | n/a | sync | yes | meta_*_snapshot | same | Required |
| META_OVERVIEW_* / AD_* entity | RF_META_INSIGHTS_SYNC | act_*/insights | campaign,ad | spend,impr,clicks,reach,freq,link/outbound,actions… | — | — | unified | 1 | date×entity | plan | family slice | sync | yes | meta_campaign_daily, meta_ad_daily | same | Required |
| META_*_DAILY / OVERVIEW spend | RF_META_INSIGHTS_DAILY | act_*/insights | campaign,adset,ad | same | — | — | unified | 1 | date×entity | plan | family slice | sync_then_async | yes | meta_*_daily | same | Required |
| META_ACTION_* / RESULT MIX | RF_META_TYPED_ACTIONS | act_*/insights | campaign,adset,ad | actions, action_values | — | — | unified | 1 | date×level×entity×type | plan | family slice | sync | yes | meta_typed_action_daily | same | Required |
| META_DELIVERY_AGE/GENDER/PLACEMENT | RF_META_INSIGHTS_BREAKDOWN | act_*/insights | account | spend,impr,clicks,reach | age; gender; publisher_platform | — | unified | 1 | date×dim | plan | narrow slice | async | yes | meta_delivery_breakdown_daily | same | Required |

## ENTITY MATRIX

| Provider Entity | Stable ID | Parent | Config DS | Perf DS | Snapshot/History | Bindable? |
|---|---|---|---|---|---|---|
| Ad Account | account_id | — | meta_ad_account_snapshot | — | current | YES (Binding) |
| Campaign | campaign_id | account | meta_campaign_snapshot | meta_campaign_daily | current + daily | NO |
| Ad Set | adset_id | campaign | meta_adset_snapshot | meta_adset_daily | current + daily | NO |
| Ad | ad_id | adset | (via creative rel) | meta_ad_daily | daily | NO |
| Creative | creative_id | ad ref | meta_creative_snapshot | (via ad) | current | NO |

## OBJECTIVE / OPTIMIZATION / STATUS / BUDGET / DESTINATION / INSIGHTS / ACTION / ATTRIBUTION / REACH / SYNC-ASYNC / MONEY / TIMEZONE / HISTORICAL / FORMULA / PRIVACY MATRICES

Summarized above; hard rules:

- Objective ≠ Optimization ≠ Business Outcome  
- Configured status ≠ Effective status  
- Budget ≠ Spend; Meta units ≠ Google micros; FX = NO  
- Destination ≠ Business Action/Outcome  
- Actions retain `action_type`; no generic Results; no BA mapping in collector  
- Attribution = unified setting + provenance  
- Reach/Frequency non-additive  
- Sync/Async are transport only  
- Formula Registry owns CTR/CPC/CPM/ROAS-like derived metrics  
- PII / leads / messages / CA members = not collected  

## ASYNC STATE MATRIX

| MoxDOP stage | Provider | DatasetRun | Job ID | Provider % | Operator | Next |
|---|---|---|---|---|---|---|
| ASYNC_SUBMITTED | created | Running/Queued | yes | n/a | Submitted | poll |
| WAITING_PROVIDER | Job Running | Running | yes | optional | Waiting for provider | delayed Continue |
| DOWNLOADING_RESULTS | Job Completed | Running | yes | 100% ≠ done | Downloading | page writes |
| COMPLETED | — | Completed | retained in history | — | Done | — |

## REALITY MATRIX (Prompt 24)

| Capability | State |
|---|---|
| Meta Integration / Auth / Discovery / Binding | REAL |
| Collection Engine / Data Pool / Monitoring / Retry | REAL |
| Meta Ads Production Collector | **REAL** |
| Campaign / Ad Set / Ad / Creative metadata | REAL (contract) |
| Campaign / Ad Set / Ad daily Insights | REAL |
| Typed Actions / Action Values | REAL |
| Objectives / Optimization / Budget / Status / Destination | REAL (config facts) |
| Sync Insights | REAL |
| Async Insights transport | REAL |
| Raw + Normalized Meta pool + Materialization | REAL |
| Meta specialist real-data UI | **NOT YET** |
| Meta Initial Backfill | **NOT YET** (Prompt 25) |
