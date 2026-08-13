# DATA POOL INTEGRITY & RECONCILIATION

Prompt 26 — Data Pool Integrity & Reconciliation.

Status: **REAL** (integrity framework + migration readiness gate).  
Actual Real-Pool Verification: **NOT EXECUTED** in the current Cloud Agent environment (normalized fact tables empty) — Prompt status must remain PARTIAL until a populated pool is audited.

## 1. Purpose

Prove whether the normalized production data pool is structurally, semantically and operationally trustworthy **before** any frozen specialist Demo → real-data migration (Prompts 28–31).

## 2. Why Collection Success Is Not Data Trust

`CollectionRun` completed ≠ integrity.  
`DatasetMaterialization` AVAILABLE ≠ integrity.  
Rows exist ≠ correct natural keys / coverage / timezone / currency.  
Provider async 100% ≠ Dataset complete.

## 3. Integrity Architecture

```text
DataIntegrityRegistry (profiles)
        ↓
DataPoolIntegrityAuditor
        ↓
DatasetIntegrityChecker (+ MetricAggregationGuard, CoverageIntervalSet)
        ↓
DataIntegrityAuditRun / DataIntegrityCheckResult
        ↓
RealDataMigrationReadinessService
        ↓
Provider / Dataset READY | BLOCKED | UNVERIFIED
```

No per-provider quality systems. No numeric score. No automatic repair.

## 4. Audit Run

Persisted as `data_integrity_audit_runs` + `data_integrity_check_results`.  
Modes: `LOCAL_INTEGRITY` (default, 0 provider calls) · `PROVIDER_RECONCILIATION` (explicit opt-in).  
Command: `php artisan moxdop:data-pool-audit`.

## 5. Integrity Rule Registry

`docs/data-contracts/MOXDOP_DATA_INTEGRITY_REGISTRY_V1.json` (+ schema).  
References Dataset IDs from Data Contract / Storage Contract. Does **not** redefine formulas.

## 6. Dataset Inventory

44 production logical datasets across SEARCH_CONSOLE, GA4, GOOGLE_ADS, META_ADS (including `ga4_event_source_medium_daily` STORAGE_CONTRACT_GAP).  
Every profile lists grain, natural key, coverage mode, pagination mode, row-accounting mode, TZ/currency sources, additive/non-additive metrics, freshness SLA, required + migration-blocking checks.

## 7. Structural Integrity

Natural-key duplicate scans (SQL grouped counts). CollectionRun must not be in natural key. Referential checks for DigitalAsset orphans and provider/resource identity.

## 8. Natural-Key Duplicates

Exact duplicate groups detected. Auditor never deletes. Conflicting same-key values surface as duplicate groups.

## 9. Referential Integrity

Facts must reference valid DigitalAssets; resource provider must match dataset provider family.

## 10. Provenance

`last_collection_run_id` (and related) treated as provenance, not identity.

## 11. Raw → Normalized Accounting

WriteReceipt / `dataset_write_batches`: received = inserted + updated + unchanged (ONE_TO_ONE).  
`ONE_TO_MANY_TYPED_ACTIONS` allows written > received. Unexplained loss/expansion → FAIL.

## 12. Raw Payload Integrity

Registry `raw_required` defaults false for V1 physical facts (raw often optional per Storage). When required, missing raw fails.

## 13. WriteReceipt Integrity

Committed batches must balance. Completion without receipts fails.

## 14. Checkpoint Integrity

Checkpoint/progress claiming complete without durable writes fails. Meta async provider 100% with incomplete download fails.

## 15. Coverage Model

`CoverageIntervalSet` from successful collection dates (including zero-row success). Never min/max alone.

## 16. Why Missing Fact Rows Are Not Missing Collection Dates

Coverage comes from collection evidence (`freshness_metadata.successful_coverage_dates` / `zero_row_success_dates`), not fact-row presence.

## 17. DatasetMaterialization Reconciliation

AVAILABLE without interval evidence → UNVERIFIED/WARNING. Wrong resource → FAIL. Partial remains visible.

## 18. Pagination / Streaming Completeness

Provider-aware modes: GSC_START_ROW, GA4_OFFSET_ROWCOUNT, GADS_SEARCH_PAGED, GADS_SEARCH_STREAM, META_SYNC_OR_ASYNC.

## 19. GSC Completeness

Terminal pagination + provider limitation → PASS_WITH_LIMITATION when marked.

## 20. GA4 Completeness

`provider_row_count` vs `rows_received_total` exact match required on completed runs.

## 21. Google Ads Search / SearchStream Completeness

Paged search terminal; SearchStream uses normal termination evidence — no invented totals.

## 22. Meta Sync / Async Completeness

Sync: cursor exhaustion. Async: provider complete ∧ result pages downloaded ∧ DatasetRun success. Provider 100% alone never PASS.

## 23. Row Count Semantics

Layers: provider declared / received / written inserts/updates/unchanged / expanded children. Never one ambiguous `row_count`.

## 24. Timezone Integrity

Source: GA4 property / Ads customer / Meta ad account / GSC reporting-date semantics. No Brand/server rebucket.

## 25. Currency Integrity

Source resource currency. No FX. No cross-currency aggregation in a resource scope.

## 26. Provider Total Reconciliation

Local/same-run preferred. Live mode opt-in only. Compatible metric/grain/range/TZ/currency/attribution required.

## 27. Additive vs Non-Additive Metrics

`MetricAggregationGuard` forbids summing Reach, Frequency, GA4 users, etc.

## 28. Contract Completeness

PHYSICAL_TABLE mapping required. STORAGE_CONTRACT_GAP blocks. `collection_evidence` required for READY.

## 29. Partial Collection

Partial coverage warnings block migration while preserving sibling provider independence.

## 30. Provider Historical Limitations

PASS_WITH_LIMITATION when explicitly marked; never fabricated zeros.

## 31. Freshness / Stale State

SLA from Integrity Registry hours. Stale ≠ corrupt. Prompt 26 never schedules refresh.

## 32. Formula Reconciliation

Formula Registry unchanged; integrity references it. No new formulas invented.

## 33. Real-Data Migration Readiness Gate

`RealDataMigrationReadinessService` → READY_FOR_REAL_UI | READY_WITH_PROVIDER_LIMITATION | BLOCKED_* | UNVERIFIED.  
UNVERIFIED ≠ PASS. No score.

## 34. Local Audit Mode

Default. Zero provider API calls.

## 35. Provider Reconciliation Mode

`--provider-reconcile` + config allow. Read-only, bounded. Disabled by default.

## 36. Security

No tokens in audit records. Counts/statuses/safe IDs only.

## 37. Performance

Grouped SQL duplicate scans; scoped audits; no full-table PHP hydration.

## 38. Tests

`tests/Feature/DataPool/DataPoolIntegrityReconciliationTest.php`.

## 39. Actual Real-Pool Audit

Current environment fact tables: **0 rows**. Framework executed; **REAL POOL VERIFICATION NOT EXECUTED**.

## 40. Reality Matrix

See Milestone 5 Capability Reality Matrix (Prompt 26 rows).

## 41. Prompt 27 Handoff

Prompt 27 owns freshness scheduling / incremental collection. Prompt 26 only audits freshness state.

## 42. Definition of Done

Integrity architecture production-complete; migration gate queryable; real-pool trust claimed only after populated-pool audit passes.

---

## DATASET INTEGRITY MATRIX

See `MOXDOP_DATA_INTEGRITY_REGISTRY_V1.json` `dataset_profiles[]` — authoritative per-dataset profile (44 entries). Summary:

| Provider | PHYSICAL datasets | Snapshot | Historical | Gap |
| --- | ---: | ---: | ---: | ---: |
| SEARCH_CONSOLE | 9 | 2 | 7 | 0 |
| GA4 | 11 (+1 gap) | 1 | 10 | 1 (`ga4_event_source_medium_daily`) |
| GOOGLE_ADS | 14 | 8 | 6 | 0 |
| META_ADS | 9 | 4 | 5 | 0 |

## ROW ACCOUNTING MATRIX

| Mode | Datasets | Equality | Notes |
| --- | --- | --- | --- |
| ONE_TO_ONE | most daily/snapshot | received = written components | FAIL on silent loss/expansion |
| ONE_TO_MANY_TYPED_ACTIONS | `meta_typed_action_daily` | written ≥ received | Expansion expected |
| SNAPSHOT_UPSERT | `*_snapshot`, metadata | upsert accounting | No daily intervals |

## PAGINATION COMPLETENESS MATRIX

| Provider | Mode | Total available? | Terminal | Failure |
| --- | --- | --- | --- | --- |
| GSC | GSC_START_ROW | often no | empty/terminal page | incomplete pages |
| GA4 | GA4_OFFSET_ROWCOUNT | yes rowCount | offset covers rowCount | mismatch |
| Google Ads Search | GADS_SEARCH_PAGED | page tokens | no next token | loop/incomplete |
| Google Ads Stream | GADS_SEARCH_STREAM | **no** | stream end | interrupted |
| Meta Sync | META_SYNC_CURSOR | cursors | cursor end | partial |
| Meta Async | META_SYNC_OR_ASYNC | provider % ≠ complete | result_complete + writes | provider 100% / half download |

## COVERAGE MATRIX

Coverage = interval set of successful collection dates ∪ zero-row success dates, compared to target; provider-limited ranges → PASS_WITH_LIMITATION; snapshots → N/A.

## TIMEZONE MATRIX

| Provider | Source | Rebucket Brand/UTC? |
| --- | --- | --- |
| GA4 | property timezone | NO |
| Google Ads | customer timezone | NO |
| Meta | ad account timezone | NO |
| GSC | reporting-date semantics | NO |

## CURRENCY MATRIX

| Provider | Source | Unit | FX | Cross-currency |
| --- | --- | --- | --- | --- |
| Google Ads | customer | normalized decimal from micros | NO | NO |
| Meta | ad account | provider money unit | NO | NO |
| GA4 | property/report when present | — | NO | NO |
| GSC | N/A | — | — | — |

## PROVIDER RECONCILIATION MATRIX

Local/same-run first. Live opt-in. Additive only when registry allows. Tolerance only when explicitly configured (default null).

## NON-ADDITIVE METRIC MATRIX

| Metric | Sum dates? | Average blindly? | Period method |
| --- | --- | --- | --- |
| GA4 users / totalUsers / activeUsers | NO | NO | direct period observation |
| Meta Reach | NO | NO | period Insights |
| Meta Frequency | NO | NO | period Insights |
| GSC position | NO | NO | provider semantics |

## CONTRACT COMPLETENESS MATRIX

Eligible REQUIRED datasets need implementation + evidence + integrity pass (or accepted limitation). Optional missing non-blocking. Conditional NOT_ELIGIBLE ≠ failure. STORAGE_CONTRACT_GAP blocks.

## MATERIALIZATION RECONCILIATION MATRIX

| State | Integrity |
| --- | --- |
| never collected | collection_evidence UNVERIFIED |
| zero-row success w/ evidence | PASS |
| AVAILABLE + interval evidence | PASS |
| AVAILABLE without intervals | UNVERIFIED/WARNING |
| PARTIAL | WARNING blocks migration |
| STALE | freshness WARNING (not corrupt) |
| wrong resource | FAIL |

## MIGRATION GATE MATRIX

| Check class | Blocks dataset | Blocks provider | Notes |
| --- | --- | --- | --- |
| natural_key_duplicates FAIL | YES | YES | no auto-delete |
| coverage gap FAIL | YES | YES | |
| pagination FAIL | YES | YES | |
| timezone/currency FAIL | YES | YES | |
| collection_evidence UNVERIFIED | YES | YES | empty pool |
| provider limitation | NO | READY_WITH_LIMITATION | |
| optional warning | per rules | usually NO | |

## REAL POOL AUDIT MATRIX (this environment)

| Provider | Resources | Datasets | Fact scale | Ready? |
| --- | ---: | ---: | ---: | --- |
| SEARCH_CONSOLE | 0 audited populated | profiles present | 0 | UNVERIFIED |
| GA4 | 0 | profiles present | 0 | UNVERIFIED |
| GOOGLE_ADS | 0 | profiles present | 0 | UNVERIFIED |
| META_ADS | binding may exist | profiles present | 0 | UNVERIFIED |

**REAL POOL VERIFICATION NOT EXECUTED.**
