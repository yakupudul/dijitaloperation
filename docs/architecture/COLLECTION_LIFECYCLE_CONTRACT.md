# Collection Lifecycle Contract

> Prompt 62 — bounded collection lifecycle intents and planner/executor semantics.  
> Implementation: `CollectionLifecycleIntent`, `CollectionLifecycleAction`, `CollectionPlanningBlockReason`, `CollectionLifecyclePlanner`, `ExecuteCollectionLifecycleService`, `ImmutableCollectionLifecyclePlan`.  
> Related: [`COLLECTION_SCHEDULING_POLICY_CONTRACT.md`](COLLECTION_SCHEDULING_POLICY_CONTRACT.md) · [`LATE_DATA_REPAIR_CONTRACT.md`](LATE_DATA_REPAIR_CONTRACT.md) · [`docs/implementation/COLLECTION_SCHEDULER.md`](../implementation/COLLECTION_SCHEDULER.md)

## Canonical rule

For each Digital Asset (Resource × Dataset fan-out), the planner chooses **exactly one** executable lifecycle intent (or `NO_WORK` / `BLOCKED`). Intents are distinct from `CollectionTriggerType` and `IncrementalWorkReason`. Priority: **Initial Backfill → Catch-Up → Incremental → Late Data Repair** (Manual labels operator override). No AI. No arbitrary execution kinds (`FULL_REFRESH_RECURRING`, `CUSTOM_SQL`, `AI_SELECTED_MODE` — **FORBIDDEN**).

```text
Resource × Dataset canonical state
  + CollectionSchedulingPolicy
  + LatestSafeReportingWindow
  + IncrementalCoveragePlanner
    → CollectionPlanningDecision
      → ImmutableCollectionLifecyclePlan
        → StartCollectionService | StartIncrementalCollectionService
          → CollectionRun (orchestrator only)
```

---

## Shared fields (all intents)

| Concern | Contract |
| --- | --- |
| Planning input | Active bindings (schedulable capabilities), materializations, auth map, integrity map, schedule status, optional `manual`, clock |
| Provider calls in planner | **NONE** |
| Downstream domain writes | **NONE** (no Finding / Evidence / Opportunity / Task / Business Outcome / AI) |
| Retry | Idempotent plan fingerprint + optional `idempotency_suffix`; active-equivalent reuse |
| Block reasons | `COLLECTION_DISABLED`, `CREDENTIAL_INVALID`, `RESOURCE_UNBOUND`, `DATASET_UNSUPPORTED`, `POLICY_NOT_CONFIGURED`, `INTEGRITY_BLOCKED`, `ACTIVE_COMPATIBLE_RUN`, `PROVIDER_LIMITED`, `NO_SAFE_INTERVAL`, `AUTHORIZATION_NOT_READY`, `SCHEDULE_PAUSED`, `ACTION_REQUIRED` |

---

## INITIAL_BACKFILL

| Dimension | Contract |
| --- | --- |
| Entry conditions | No usable materialization / incremental reason `initial_backfill_required_before_incremental` / mat null with `PlanDisposition::NotEligible` |
| Planning input | Binding + primary datasets for provider families; policy required-history informs Engine scope |
| Coverage effect | Establishes first successful coverage intervals via Collection Engine collectors |
| Watermark effect | Creates / advances verified contiguous watermark from successful coverage evidence (Prompt 27) |
| Retry semantics | Idempotency key `life:{hash(planFingerprint[|suffix])}`; duplicate start reuses same `CollectionRun` |
| Completion criteria | Orchestrator run reaches terminal status; materializations exist so planner no longer selects Initial Backfill |
| Trigger | `CollectionTriggerType::InitialBackfill` via `StartCollectionService` |
| Forbidden | Defaulting Run Now / recurring tick to full backfill when coverage already current |

---

## INCREMENTAL

| Dimension | Contract |
| --- | --- |
| Entry conditions | Executable incremental plan with reasons `NEW_COVERAGE`, `SNAPSHOT_REFRESH`, and/or `CONTRACT_UPGRADE` (and not higher-priority Catch-Up / Backfill) |
| Planning input | Verified watermark vs latest-safe frontier; freshness SLA for snapshots |
| Coverage effect | Extends successful coverage through new safe intervals only |
| Watermark effect | Advances verified watermark when contiguous success proven; failed refresh does **not** advance |
| Retry semantics | Fingerprint includes intent + due items + suffix; `active_equivalent` if non-terminal twin exists |
| Completion criteria | No new safe interval due (`NO_WORK` / `data_current`) or run terminal; frontier met without gap |
| Trigger | Incremental path via `StartIncrementalCollectionService` → `CollectionTriggerType::Incremental` |
| Action enum | `INCREMENTAL` (Manual intent may map action to Incremental while labeling Manual) |

---

## LATE_DATA_REPAIR

| Dimension | Contract |
| --- | --- |
| Entry conditions | Incremental reason `LATE_DATA_REPROCESS` only (after Catch-Up/NewCoverage priority filters); policy `lateDataRepairEnabled` |
| Planning input | Fixed recent reporting window from freshness `late_data_reprocessing`; `last_reprocess_through` suppresses repeats |
| Coverage effect | Re-collects **already-covered** recent range for late-arriving provider revisions; may overlap coverage |
| Watermark effect | Reprocess alone does **not** regress verified watermark; may update `last_reprocess_through` |
| Retry semantics | Same immutable windows + fingerprint; no silent window expansion mid-flight |
| Completion criteria | Reprocess through current collectable end recorded / not due; see [`LATE_DATA_REPAIR_CONTRACT.md`](LATE_DATA_REPAIR_CONTRACT.md) |
| Distinct from Catch-Up | Repair ≠ filling missing days; Catch-Up owns gaps |
| Forbidden | Global lookback repair; inventing repair without explicit dataset policy |

---

## CATCH_UP

| Dimension | Contract |
| --- | --- |
| Entry conditions | Incremental reasons `GAP_RECOVERY` or `CATCH_UP` (priority over Incremental and Late Repair) |
| Planning input | Internal gaps / verified watermark behind collectable end; bounded by `max_bounded_incremental_span_days` |
| Coverage effect | Fills missing successful coverage dates toward latest-safe frontier |
| Watermark effect | Verified contiguous watermark advances only after gap dates succeed; cannot jump unresolved holes |
| Retry semantics | Bounded span + occurrence misfire `catch_up_bounded` (adapter `maxCatchUp=2`) — **no** unbounded catch-up storms |
| Completion criteria | Gaps resolved or remaining work re-planned next tick within bounds |
| Forbidden | Treating late revision windows as Catch-Up; fabricating coverage without collector success |

---

## MANUAL

| Dimension | Contract |
| --- | --- |
| Entry conditions | `ExecuteCollectionLifecycleService::runNow` / context `manual=true` when underlying work is not Initial Backfill |
| Planning input | Same planner as automatic path — never a separate “full refresh” mode |
| Coverage effect | Whatever the selected underlying intent produces (Incremental / Catch-Up / Late Repair) |
| Watermark effect | Same as underlying intent |
| Retry semantics | Same fingerprint rules; operator retry must not fork a second active equivalent |
| Completion criteria | Same as underlying intent; if nothing due → `NO_WORK` without fabricating a run |
| Labeling | Intent may be `MANUAL` while action remains the executable work kind; Initial Backfill still required when coverage absent |
| Forbidden | Manual defaulting to full historical backfill when data is current |

---

## NO_WORK / BLOCKED (actions)

| Action | Meaning |
| --- | --- |
| `NO_WORK` | No executable dataset work (e.g. `NO_NEW_SAFE_INTERVAL`); occurrence may complete without a new run |
| `BLOCKED` | Hard stop with `CollectionPlanningBlockReason`; adapter maps pause/disabled/no-safe to `skipped`, other blocks to `failed` |

---

## Immutable plan

`ImmutableCollectionLifecyclePlan` pins: intent, asset, binding ids, request families, providers, windows, timezone, policy identity/version/fingerprint, watermark / safe-frontier / gap / repair snapshots, decision array, `created_at_utc`, `plan_fingerprint`.

Execution **must not** silently expand windows when newer safe dates appear after pin.

---

## Forbidden

- Mixing multiple lifecycle intents in one plan
- Planner → provider HTTP / collector bypass
- `CollectionSchedulerV2` lifecycle tables
- AI intent selection
- Auto-creating Findings, Evidence, Opportunities, Tasks, Outcomes, or AI runs from lifecycle execution
