# Late Data Repair Contract

> Prompt 62 — explicit late-arriving provider data recollection within a policy window.  
> Implementation path: freshness `late_data_reprocessing` → `DatasetFreshnessEvaluator::reprocessDue` → `IncrementalCoveragePlanner` (`LATE_DATA_REPROCESS`) → `CollectionLifecyclePlanner` intent `LATE_DATA_REPAIR` → `ExecuteCollectionLifecycleService` → Collection Orchestrator.  
> Related: [`COLLECTION_SCHEDULING_POLICY_CONTRACT.md`](COLLECTION_SCHEDULING_POLICY_CONTRACT.md) · [`COLLECTION_LIFECYCLE_CONTRACT.md`](COLLECTION_LIFECYCLE_CONTRACT.md) · Prompt 27 `DATA_FRESHNESS_INCREMENTAL_COLLECTION.md` · Prompt 26 integrity.

## Canonical rule

Late Data Repair re-collects a **dataset-specific recent reporting window that may already be covered**, because provider reporting can revise after first availability. It is **not** Catch-Up (missing days), **not** Initial Backfill, and **not** a global lookback. Without an explicit freshness policy strategy `fixed_recent_reporting_window` + positive `window_days`, repair capability is **OFF**.

---

## Why late data exists

Providers (GA4, GSC, Ads, Meta, etc.) commonly publish a reporting day before all conversions, delayed hits, or search metrics stabilize. Official docs often describe multi-day maturation (e.g. GA4 ~24–48h processing with possible revisions up to ~7 days). MoxDOP therefore separates:

| Concept | Meaning |
| --- | --- |
| Safe lag | How far behind “today” the collectable end sits |
| Late repair window | How far back inside already-safe dates to re-pull for revisions |
| Catch-Up / gap recovery | Days never successfully collected |

Treating first successful HTTP pull as final truth is **FORBIDDEN**.

---

## Explicit policy requirement

| Requirement | Contract |
| --- | --- |
| Source of truth | Per-dataset `late_data_reprocessing` in `MOXDOP_DATA_FRESHNESS_POLICY_V1` |
| Enable gate (`CollectionSchedulingPolicyRegistry`) | `strategy === fixed_recent_reporting_window` **and** `window_days` is int > 0 |
| Other strategies | e.g. `replace_current_snapshot`, `none` → not Late Data Repair intent |
| Global reprocess window | **FORBIDDEN** (`global_reprocess_window_forbidden: true`) |
| Magic constants in scheduler | **FORBIDDEN** — wrap Prompt 27 only |

---

## Covered-range requirement

Repair intervals are planned against the recent window ending at collectable end and **may overlap existing successful coverage** when `overlap_existing_coverage_allowed: true`. Repair does not invent days beyond the policy window or before known coverage bounds when intervals exist. If verified watermark is still behind collectable end, new-coverage / catch-up planning typically absorbs overlapping reprocess work — lifecycle priority prefers Catch-Up / Incremental over Late Repair when those reasons are present.

---

## Repair horizon

| Rule | Contract |
| --- | --- |
| Horizon length | Dataset `window_days` (commonly 7 for many daily datasets; some Ads conversion datasets 30 per freshness registry) |
| End | Current collectable end (latest-safe), not wall-clock today |
| Start | `end - (window_days - 1)`, clamped to coverage bounds when applicable |
| Suppression | `freshness_metadata.last_reprocess_through >= collectableEnd` → reprocess not due |

---

## Recollection provenance

| Rule | Contract |
| --- | --- |
| Execution | Canonical Collection Orchestrator / collectors only |
| Intent stamp | `CollectionRun.metadata.collection_intent = LATE_DATA_REPAIR` (+ plan / policy fingerprints) |
| Planner provenance | Immutable plan `repair_context` snapshots selected windows |
| Direct provider HTTP from scheduler | **FORBIDDEN** |
| Hidden paid enrichment (DataForSEO) as repair | **FORBIDDEN** |

---

## Natural-key reconciliation

Recollection upserts pool facts by existing dataset natural keys (Prompt 7 / collectors). Repair is a **re-read + reconcile** of the same Resource × Dataset × reporting grain, not a parallel “repair facts” store. No `LateRepairV2` entity.

---

## Row disappearance safety

| Rule | Contract |
| --- | --- |
| Zero-row success | Still records successful coverage dates (Prompt 27) — does not invent metrics |
| Provider row removal / revision | Natural-key upsert / collector semantics apply; scheduler must not delete pool rows itself |
| Unsafe shortcut | Blind `TRUNCATE` / delete-by-date from scheduler path — **FORBIDDEN** |
| Integrity | Prompt 26 blocking failures keep trusted fresh blocked; repair does not bypass integrity |

---

## Watermark behavior

| Rule | Contract |
| --- | --- |
| Verified contiguous watermark | **Not** regressed solely because a reprocess ran |
| Advancement | Only from successful coverage evidence rules already owned by Prompt 27 materialization |
| `last_reprocess_through` | Tracks repair progress through collectable end; separate from verified watermark |
| Failed repair run | Does not advance verified watermark |

---

## Integrity / freshness

| Concern | Contract |
| --- | --- |
| Freshness states | Evaluator may mark due for late reprocess while coverage otherwise meets frontier |
| `INTEGRITY_BLOCKED` | Blocks trusted fresh / executable planning until cleared |
| `PROVIDER_LIMITED` / `ACTION_REQUIRED` | Block — no silent repair |
| Numeric freshness score | **NONE** |
| FreshnessV2 / WatermarkV2 | **NONE** |

---

## No downstream domain writes

Late Data Repair may update **Data Pool** materialization / facts only through the Collection Engine. It must **not** auto-create or mutate:

- Findings / Evidence / Opportunities / Recommendations  
- Work Tasks / Approvals / Playbooks  
- Business Outcomes / Client Value Stories / Report Snapshots  
- AI agent runs / Skills  

Intelligence scheduling that reacts to fresher pool data is **Prompt 63** — out of scope here.

---

## Forbidden

- Equating Late Repair with Catch-Up or Initial Backfill  
- Global or operator-arbitrary lookback without dataset policy  
- Scheduler-side destructive pool wipes  
- Downstream domain side effects from repair completion  
- `CollectionSchedulerV2` / `WatermarkV2` / `FreshnessV2`
