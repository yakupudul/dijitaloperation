# META INITIAL BACKFILL

Prompt 25 — Meta Initial Backfill Orchestrator.

Status: **REAL** (planning + execution + Integrations UX). Does **not** migrate Meta specialist analytical UI. Does **not** implement recurring/incremental collection. Does **not** collect unbound Ad Accounts or treat META_BUSINESS as an analytical root.

## 1. Purpose

One operator **Collect Data** action on the frozen Meta Integration starts a complete contract-driven initial historical import for all currently eligible human-confirmed Meta Ad Account Bindings through the shared Collection Engine.

## 2. Relationship to Google Initial Backfill

Google Prompt 20 and Meta Prompt 25 share Collection Engine primitives (`CollectionIntent` / `InitialBackfill`, `CollectionPlanner`, `HistoricalRangeResolver`, `CoverageSatisfactionChecker`, `StartCollectionService`, ResourceRun/DatasetRun fan-out, Prompt 11 monitoring, Prompt 12 retry/resume/cancel).

They do **not** share provider request semantics. Meta retains Marketing API, typed Actions, attribution, Reach/Frequency, sync/async Insights transport, and Meta history limits — owned by Prompt 24.

## 3. Shared Collection Orchestration

```text
CollectionIntent (initial_backfill)
  + Meta Integration scope
  + human-confirmed META_AD_ACCOUNT Bindings
  + Data Contract Registry
  + DatasetMaterialization state
        ↓
CollectionPlanner (provider-neutral)
        ↓
META_ADS DatasetRuns → MetaAdsDatasetExecutor (Prompt 24)
```

Thin coordinator classes:

- `MetaCollectionPreflightService`
- `MetaInitialBackfillOrchestrator`

No second Meta ingestion / retry / monitoring platform.

## 4. Meta-Specific Provider Boundaries

| Concern | Owner |
| --- | --- |
| Insights fields / levels / breakdowns | Prompt 24 collector |
| Sync vs async transport | Prompt 24 `MetaInsightsRetrievalStrategy` |
| Action / attribution parsing | Prompt 24 |
| Date slice widths for provider requests | Prompt 24 |
| Ad Account eligibility / Binding | Prompt 23 + preflight |
| Dataset selection / historical targets | Registry + shared planner |

## 5. Operator Flow

```text
/app/integrations → Meta → Authorized → Businesses/Ad Accounts discovered
→ Human-confirmed META_AD_ACCOUNT Bindings → Collect Data
→ MetaInitialBackfillOrchestrator preflight + start
→ ONE CollectionRun (trigger: initial_backfill)
→ N ResourceRuns (one per eligible Ad Account)
→ DatasetRuns (registry-driven)
→ Prompt 11 MonitoringPanel (Activity tab)
```

No new top-level navigation. No resource reselection at Collect Data time.

## 6. Collection Intent

`CollectionTriggerType::InitialBackfill` (`initial_backfill`).

Persisted with `metadata.collection_intent = meta_initial_backfill` and
`metadata.collection_intent_label = Initial Meta Ads Collection`.

## 7. Preflight

`MetaCollectionPreflightService` is control-plane only:

- active human-confirmed `meta_ads` Bindings to `META_AD_ACCOUNT`
- Integration authorization / permission readiness
- registry-driven planned + already-satisfied datasets
- action-required resource dispositions
- plan fingerprint for active-equivalent protection

**0** analytical Marketing API / Insights calls during preflight or page render.

## 8. Binding Resolution

Server-side only under the Meta `CoreIntegration`:

| Resource | Analytical ResourceRun? |
| --- | --- |
| Bound `META_AD_ACCOUNT` (active) | YES when auth/permission/access ready |
| Discovered-but-unbound Ad Account | NO |
| Inactive Binding | NO |
| `META_BUSINESS` | NO (display/discovery context only) |
| Facebook Page / Instagram | NO for this collector |

## 9. Authorization / Permission Readiness

Global Integration auth/token invalid → no new analytical work (`reauth_required`).

Missing required read permission → `permission_required` / action-required.

Resource-specific access unavailable → that account is action-required; eligible siblings still plan.

## 10. Contract-Driven Planning

`CollectionPlanner` reads `MOXDOP_DATA_CONTRACT_REGISTRY_V1` with `providerSources: ['META_ADS']`.

No hardcoded Meta dataset array in the orchestrator.

REQUIRED / OPTIONAL / CONDITIONAL preserved. DEFERRED / UNSUPPORTED (e.g. `RF_META_ASYNC_INSIGHTS`) do not create executable DatasetRuns.

## 11. Historical Coverage

`HistoricalRangeResolver` + materialization inspection — per requirement. No universal “Meta = 12 months” window.

## 12. Meta Provider History Limits

Planner respects registry/provider capability metadata via shared range resolver. Unavailable history is never fabricated as zero rows. REQUIRED contract mismatches surface honestly (Prompt 24 semantics).

## 13. Existing Materializations

`CoverageSatisfactionChecker`:

- never collected → plan full collectable target
- partial → continuation / missing coverage
- already satisfied → skip / `already_satisfied`
- stale with full initial history → not turned into recurring refresh
- contract version change → only new/missing work

Destructive truncate on Collect Data: **NO**.

## 14. CollectionRun Structure

One operator Collect Data → normally **one** `CollectionRun` containing all eligible Meta Ad Account ResourceRuns for that Integration.

## 15. Multi-Account ResourceRuns

One ResourceRun per eligible bound Meta Ad Account. Independent execution. Business grouping is display-only and does not merge data, currency, or timezone.

## 16. DatasetRuns

Created from Registry request families per ResourceRun. Persist requirement IDs, family ID, dataset ID, disposition, coverage targets, contract version in plan snapshot / DatasetRun metadata.

## 17. Sync / Async Ownership

Orchestrator does **not** choose sync/async. Prompt 24 collector decides per request family / workload. Async provider job state lives in DatasetRun checkpoints.

## 18. Background Execution

Start persists plan, fans out DatasetRun jobs, returns. Browser may close. No `CollectAllMetaDataJob`. No mandatory serial account chain.

## 19. Failure Isolation

Dataset failure ≠ sibling termination. Account failure ≠ other accounts. Meta ≠ Google. Parent may be PARTIAL.

## 20. Retry

Shared Prompt 12 only. Orchestrator does not implement Meta-specific retry loops. Async polling remains Prompt 24.

## 21. Resume

Shared resume + Prompt 24 checkpoints / provider job IDs. Orchestrator does not interpret page cursors or report IDs.

## 22. Cancellation

Shared `CancellationService`. Completed facts preserved. Delayed poll observes cancellation. Provider report may still exist remotely.

## 23. Progress Semantics

Reuse Prompt 11 `MonitoringPanel` + `CollectionRunMonitorQuery`.

Dataset-plan completion % = completed executable DatasetRuns / planned executable DatasetRuns.

## 24. Provider Async Progress

Provider report % and result-transfer rows remain distinct from plan completion. No fake global transfer percentage when totals are unknown.

## 25. Materialization / Coverage

Per resource × dataset. Gaps, partial, provider-limited, zero-row success, and failed-latest-with-prior-AVAILABLE remain first-class. No global `meta_imported` truth flag.

## 26. Binding Changes During Active Runs

Persisted ResourceRun/DatasetRun provider identity is immutable for that run. Rebind affects **future** plans only. Historical facts are not rewritten.

## 27. History

Shared Collection History with label **Initial Meta Ads Collection**. Original plan snapshot retained — not rebuilt from today’s Registry.

## 28. Notifications

Reuse existing run-level notification architecture. No per-Dataset spam.

## 29. Security

Admin/authorized operator only. Bindings resolved server-side. Plans/jobs/history/checkpoints never contain access tokens or App Secret.

## 30. Privacy

Initial backfill does not expand beyond Data Contract datasets. No leads, messages, comments, Custom Audience members, or other PII scopes.

## 31. Tests

`tests/Feature/Collection/MetaInitialBackfillOrchestratorTest.php` — **0** live Meta calls (`Http::fake`).

## 32. Reality Matrix

See Milestone 5 Capability Reality Matrix (Prompt 25 rows) and matrices below.

## 33. Next Phase Handoff

- Prompt 26: Data Pool Integrity & Reconciliation (`docs/implementation/DATA_POOL_INTEGRITY_RECONCILIATION.md`)
- Prompt 27: recurring / incremental freshness (not this milestone)
- Specialist real-data UI migration (not this milestone)

## 34. Definition of Done

Eligible human-confirmed Meta Ad Account Bindings → contract-driven initial historical import → one persistent CollectionRun → independent ResourceRuns/DatasetRuns → raw + normalized Meta pool + materializations → professional monitoring/retry/history — without rewriting Prompt 24, without collecting unbound accounts, without Business analytical roots, without browser-owned lifetime.

---

## SHARED GOOGLE / META ORCHESTRATION MATRIX

| Concern | Google Prompt 20 | Meta Prompt 25 | Shared abstraction? | Provider-specific? |
| --- | --- | --- | --- | --- |
| CollectionIntent | `initial_backfill` + `google_initial_backfill` metadata | `initial_backfill` + `meta_initial_backfill` metadata | YES (trigger enum) | Label/intent string |
| Preflight | `GoogleCollectionPreflightService` | `MetaCollectionPreflightService` | Pattern shared | Auth/binding queries |
| Binding resolution | GSC/GA4/Ads capabilities | `meta_ads` → `META_AD_ACCOUNT` | Shared Binding model | Resource types |
| Registry planning | `CollectionPlanner` | `CollectionPlanner` | YES | Provider filter `META_ADS` |
| Materialization inspection | `CoverageSatisfactionChecker` | same | YES | Dataset IDs |
| Historical coverage | `HistoricalRangeResolver` | same | YES | Registry depths |
| CollectionRun | `StartCollectionService` | same | YES | metadata intent |
| ResourceRun | per Google binding | per Meta Ad Account binding | YES | provider_or_source |
| DatasetRun | registry families | registry RF_META_* | YES | Prompt 24 executor |
| Background dispatch | ExecuteDatasetRunJob fan-out | same | YES | — |
| Progress | Prompt 11 presenter | same | YES | labels |
| Retry / resume / cancel | Prompt 12 | same | YES | Meta async checkpoints in P24 |
| History / notifications | shared | shared | YES | labels |

## META RESOURCE PLAN MATRIX (synthetic)

| ExternalResource | DigitalAsset | Binding | Customer/Brand | Eligible? | Action required? | Planned datasets | Already satisfied | Provider-limited | ResourceRun |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `act_11110001` Main Ads | Meta Ads A | active meta_ads | Brand A | YES | NO | Registry families | 0 unless materialized | per family | YES |
| `act_22220002` International | Meta Ads B | active meta_ads | Brand B | YES | NO | Registry families | … | … | YES |
| `act_33330003` unbound | — | none | — | NO | — | none | — | — | NO |
| `biz_a` Business | — | n/a | — | NO analytical | — | none | — | — | NO |
| `act_X` access lost | Meta Ads C | active | Brand C | NO | YES RESOURCE_UNAVAILABLE | none for C | prior data kept | — | NO for this plan |

## HISTORICAL COVERAGE MATRIX

| Dataset ID | Requirement / Family | Historical policy | Snapshot/history | Requested initial | Provider-supported | Existing Materialization | Missing | Plan action | Provider limitation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `meta_ad_account_snapshot` | RF_META_AD_ACCOUNT_META | current | snapshot | current | current | AVAILABLE → skip | — | already_satisfied / eligible | n/a |
| `meta_campaign_snapshot` etc. | RF_META_ENTITY_SNAPSHOT | current | snapshot | current | current | same | — | same | n/a |
| `meta_campaign_daily` | RF_META_INSIGHTS_DAILY | 180d | historical | registry | provider max | PARTIAL → continue | gap | eligible continuation | preserve limitation provenance |
| `meta_ad_daily` | RF_META_INSIGHTS_DAILY / SYNC | 180d | historical | registry | may differ by family | independent | gap | independent plan | metric/family specific |
| `meta_typed_action_daily` | RF_META_TYPED_ACTIONS | 180d | historical | registry | family capability | independent | gap | eligible | no zero fabrication |
| `meta_delivery_breakdown_daily` | RF_META_INSIGHTS_BREAKDOWN | registry | historical | registry | async-capable | independent | gap | eligible | collector owns async |

Exact membership always comes from the Registry — this matrix documents policy ownership, not a second catalog.

## PLAN DISPOSITION MATRIX

| Disposition | Creates DatasetRun? | Affects parent? | Operator action |
| --- | --- | --- | --- |
| `eligible` | YES queued | counts toward plan | none (work runs) |
| `already_satisfied` | skipped DatasetRun may exist | not failure | none / message if all satisfied |
| `not_eligible` | non-executable | not failure | none |
| `action_required` / permission / resource unavailable | no executable for that resource | siblings may proceed | reauth / permission / access |
| `provider_history_limited` (coverage provenance) | may still complete collectable range | not automatic failure | informational |
| `unsupported` / `deferred` | no executable | none | none |
| `reauth_required` (global) | no new run | blocks start | Connect / Reauthorize Meta |

## FAILURE ISOLATION MATRIX

| Failure | Affected account | Affected DatasetRun | Sibling DatasetRuns | Other Meta accounts | Google | Existing data | Parent |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Ad Actions fail | A | that run | continue | continue | unchanged | preserved | PARTIAL |
| Account A access lost | A | A datasets action/fail | n/a on A | B continues | unchanged | preserved | PARTIAL |
| Async report fail | owning account | that DatasetRun | continue | continue | unchanged | preserved | PARTIAL/retry |
| Global credential invalid | all outstanding Meta | action-required | stop new provider work | same Integration | unchanged | preserved | action-required |

## PROGRESS MATRIX

| Level | Denominator | Percentage allowed? | Meaning | Rows shown? | State source |
| --- | --- | --- | --- | --- | --- |
| CollectionRun | planned executable DatasetRuns | YES plan % | DATASET-PLAN COMPLETION | sum written when known | DB aggregates |
| Meta Integration summary | same / last run | YES plan % | operator summary | optional | read model + monitor |
| ResourceRun (Ad Account) | DatasetRuns on resource | YES | per-account plan | optional | ResourceRun counters |
| DatasetRun | ProgressMode total if known | only when known | dataset work | rows written | DatasetRun |
| Provider async report | provider job % | YES when provider gives % | provider job only | no | checkpoint / Prompt 24 |
| Result ingestion | unknown total → no % | NO fake % | storage progress | rows written | DatasetRun progress |

## MATERIALIZATION MATRIX

| Current state | Initial planner action |
| --- | --- |
| NOT_COLLECTED | Schedule full collectable target |
| AVAILABLE full coverage | already_satisfied / skip |
| PARTIAL | Schedule missing/safe replay |
| STALE with full initial history | Do not schedule recurring refresh in Prompt 25 |
| failed latest collection | Preserve prior AVAILABLE; schedule unfinished |
| zero-row successful | Remains complete / AVAILABLE — not missing |
| provider-history-limited | Plan collectable; retain limitation provenance |
| old contract version | Plan only new/missing requirements |

## ACTIVE BINDING CHANGE MATRIX

| Scenario | Existing run resource identity | Future run identity | Cancellation | Historical data |
| --- | --- | --- | --- | --- |
| Binding unchanged | original Ad Account | same | n/a | unchanged |
| Replaced during queued/active run | remains original ExternalResource | new Ad Account | no auto cancel | original facts retained |
| Binding removed during run | original identity retained | unbound excluded | optional explicit cancel | preserved |
| Resource access lost | identity unchanged | may become action-required | DatasetRun fails/action | preserved |
| Business context changed | ResourceRun identity unchanged | display context may update | n/a | unchanged |
