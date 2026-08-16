# COLLECTION SCHEDULER

## STATUS: REAL (Prompt 62) — docs reflect code on branch

**Prompt:** 62  
**Canonical path:** `docs/implementation/COLLECTION_SCHEDULER.md`  
**Contracts:** [`COLLECTION_SCHEDULING_POLICY_CONTRACT.md`](../architecture/COLLECTION_SCHEDULING_POLICY_CONTRACT.md) · [`COLLECTION_LIFECYCLE_CONTRACT.md`](../architecture/COLLECTION_LIFECYCLE_CONTRACT.md) · [`LATE_DATA_REPAIR_CONTRACT.md`](../architecture/LATE_DATA_REPAIR_CONTRACT.md)  
**Depends on:** Prompt 61 Recurring Automation (`75f8c7a`) · Prompt 27 Freshness · Prompt 26 Integrity · Prompt 7 Data Contracts · Collection Engine (`StartCollectionService`)  
**Base HEAD:** Prompt 61 `75f8c7a0704923b4e632d4de62063152bd3c4d1b`  
**Branch:** `cursor/collection-scheduler-ea01`

| Fact | Value |
| --- | --- |
| Policy registry | `CollectionSchedulingPolicyRegistry` (`MOXDOP_COLLECTION_SCHEDULING_POLICY`) |
| Safe frontier | `LatestSafeReportingWindowResolver` → Prompt 27 `CollectableEndResolver` |
| Planner | `CollectionLifecyclePlanner` |
| Executor | `ExecuteCollectionLifecycleService` |
| Adapter | `CollectionScheduleAdapter` → lifecycle (not incremental-only) |
| Intents | `INITIAL_BACKFILL` · `INCREMENTAL` · `LATE_DATA_REPAIR` · `CATCH_UP` · `MANUAL` |
| Actions also | `NO_WORK` · `BLOCKED` |
| Cron ownership | Prompt 61 `moxdop:dispatch-due-automations` |
| DataForSEO routine schedule | **FORBIDDEN** |
| V2 entities | **NONE** (`CollectionSchedulerV2` / `WatermarkV2` / `FreshnessV2`) |
| AI | **NONE** |
| Tests | `tests/Feature/CollectionScheduler/CollectionSchedulerLifecycleTest.php` |

---

## 1. Purpose

Automate the **provider collection lifecycle** (Initial Backfill → Catch-Up → Incremental → Late-Data Repair, plus Manual) for enabled Brand Digital Assets by planning from **Resource × Dataset** canonical state and executing **only** through the Collection Orchestrator — on top of Prompt 61’s shared recurring occurrence ledger — without a second generic scheduler, without inventing V2 watermark/freshness entities, and without hidden paid DataForSEO scheduling.

```text
CollectionSchedule (domain, explicit enablement)
  → Prompt 61 RecurringOccurrence
    → CollectionScheduleAdapter
      → CollectionLifecyclePlanner + Policy Registry + Latest-Safe frontier
        → ExecuteCollectionLifecycleService
          → StartCollectionService | StartIncrementalCollectionService
            → CollectionRun / collectors / Data Pool
```

## 2. Scope

In scope:

- Versioned scheduling policy projection over Prompt 7 + Prompt 27
- Latest-safe reporting frontier resolution
- Deterministic lifecycle planning + immutable plans
- Execution via canonical orchestrator; adapter wiring; Run Now
- Intent/action/block enums; plan fingerprint idempotency
- Explicit non-scheduling of DataForSEO / non-analytics capabilities

Out of scope:

- Intelligence / Finding / Opportunity / Task auto-scheduling (Prompt 63)
- SaaS / client portal schedules; external write actions
- Workflow builder / AI intent selection
- `CollectionSchedulerV2`, `WatermarkV2`, `FreshnessV2`
- Filament CRUD completeness as a REAL gate (engine may be REAL without full UI)

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Prompt 27 freshness + Prompt 26 integrity + Prompt 7 contracts |
| 3 | Prompt 61 recurring engine contracts |
| 4 | This implementation + three architecture contracts |
| 5 | Operator `web` guard / Filament `app` panel (wiring may lag) |

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | No second generic scheduler platform |
| R2 | No provider HTTP from planner/scheduler path |
| R3 | No DataForSEO routine/hidden paid scheduling |
| R4 | No Evidence/Finding/Opportunity/Task/Outcome/AI side effects |
| R5 | Dataset-specific policy only — no global last-sync truth |
| R6 | Latest-safe ≠ wall-clock today (unless policy allows open period) |
| R7 | One lifecycle intent per immutable plan |
| R8 | Explicit `CollectionSchedule` enablement for automatic path |
| R9 | Reuse P61 occurrence ledger + P27 planner/watermarks + P26 integrity |
| R10 | No `CollectionSchedulerV2` / `WatermarkV2` / `FreshnessV2` |

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Base | Prompt 61 HEAD `75f8c7a0704923b4e632d4de62063152bd3c4d1b` |
| Branch | `cursor/collection-scheduler-ea01` |
| Config flag | `config/moxdop-data-freshness.php` → `recurring_scheduler_enabled=true` (documents P61+P62 ownership) |

## 6. Prior Prompt Input Audit

| Input | Use in Prompt 62 |
| --- | --- |
| Prompt 61 `RecurringOccurrence` / dispatcher / `CollectionSchedule` | Due fan-out + misfire bounds |
| Prompt 61 `CollectionScheduleAdapter` | Execute → lifecycle service |
| Prompt 27 `IncrementalCoveragePlanner` / watermarks / freshness states | Intent reasons + windows |
| Prompt 27 `DueCollectionQueryService` / `StartIncrementalCollectionService` | Incremental family start |
| Prompt 27 `CollectableEndResolver` | Wrapped by latest-safe resolver |
| Prompt 26 integrity blocked map | Planner `INTEGRITY_BLOCKED` |
| Prompt 7 Data Contract families/datasets | Family → primary dataset |
| `StartCollectionService` | Initial Backfill start |

## 7. Existing Collection Primitive Audit

| Primitive | Decision |
| --- | --- |
| `CollectionRun` / Engine collectors | **REUSE** — sole execution sink |
| `StartCollectionService` | **REUSE** for Initial Backfill |
| `CollectionTriggerType` | Distinct from lifecycle intent; still set on runs |
| `CollectionSchedule` (P61) | **REUSE** as domain enablement + cadence |
| Per-provider cron in `routes/console.php` | **FORBIDDEN** ownership transfer — stays on shared dispatcher |
| Shadow “scheduler collector” HTTP | **FORBIDDEN** |

## 8. Existing Freshness / Watermark Audit

| Primitive | Decision |
| --- | --- |
| `MOXDOP_DATA_FRESHNESS_POLICY_V1` | **REUSE** as scheduling policy source |
| `DatasetWatermarkCalculator` / coverage metadata | **REUSE** |
| `IncrementalWorkReason` | Maps into lifecycle intents |
| Global `last_sync_at` | Still **FORBIDDEN** |
| New `WatermarkV2` / `FreshnessV2` | **DO NOT CREATE** |

## 9. Existing Recurring Engine Audit (Prompt 61)

| Primitive | Decision |
| --- | --- |
| Shared occurrence ledger | **REUSE** |
| Collection adapter discoverDue | **REUSE** (hourly/daily, `catch_up_bounded`, maxCatchUp 2) |
| P61 rule “adapter never initial backfill” | **SUPERSEDED for collection kind** — lifecycle may start Initial Backfill when coverage requires it |
| Workflow builder / AI | Still **NONE** |

## 10. Frozen Product Surface Audit

| Surface | Prompt 62 effect |
| --- | --- |
| Frozen IA / nav | Unchanged |
| Collection monitoring UX (P12-era) | May show runs; scheduler does not redesign IA |
| Integration binding UIs | Remain auth/bind owners |
| Automatic collection | Now REAL via P61 tick + P62 planner when schedule Active |
| Intelligence auto jobs | Prompt 63 REAL (Evidence analytical change → planner; not CollectionRun completion) |

## 11. Canonical Decision

**CREATE** lifecycle planning/execution services + enums + policy projection DTOs under `app/Services/CollectionScheduler` and `app/Support/CollectionScheduler`. **DO NOT CREATE** a parallel scheduler engine, V2 watermark tables, or provider-direct jobs.

## 12. No CollectionSchedulerV2 / WatermarkV2 / FreshnessV2

Class existence of `CollectionSchedulerV2`, `WatermarkV2`, `FreshnessV2` is **FORBIDDEN**. Tests assert absence. Scheduling policy fingerprints freshness + contracts instead.

## 13. Shared Recurring Engine vs Collection Lifecycle

Prompt 61 owns **when** an occurrence is due. Prompt 62 owns **what collection intent** that occurrence should run. The engine remains kind-agnostic; collection domain owns lifecycle semantics.

## 14. Explicit Collection Enablement

Creating an Active `CollectionSchedule` (`CollectionScheduleService`) is explicit operator enablement for automatic Backfill → Incremental → Late Repair. Paused/Archived schedules block planning (`SCHEDULE_PAUSED` / disabled). Adapter passes `collection_enabled: true` on execute.

## 15. Collection Scheduling Policy Registry

`CollectionSchedulingPolicyRegistry` projects Prompt 27 dataset policies into `CollectionSchedulingPolicy` (eligible, mode, lag, cadence, late repair, catch-up, span, rate/cost class, version, fingerprint). Loads Data Contract checksum into registry fingerprint. See architecture policy contract.

## 16. Policy Identity / Version / Fingerprint

Identity `MOXDOP_COLLECTION_SCHEDULING_POLICY`. Version from freshness loader. Fingerprints pin plans and `CollectionRun.metadata` (`policy_identity`, `policy_version`, `policy_fingerprint`, `plan_fingerprint`).

## 17. Provider × Resource × Dataset Policy Shape

Each policy row is dataset-scoped with provider_or_source, resource type, reporting grain, timezone source, history policy, latest-safe lag, incremental applicability, late repair block, catch-up enablement, max span, rate/cost class. No free-form operator JSON schema.

## 18. Schedulable Providers

Only `GA4`, `SEARCH_CONSOLE`, `GOOGLE_ADS`, `META_ADS` via capability map. Bindings outside the map are ignored for planning.

## 19. DataForSEO Not Routinely Scheduled

`isDataForSeoRoutinelyScheduled()` is hard `false`. Prevents paid enrichment cost storms from the recurring collection path. Manual/on-demand DataForSEO enrichment remains outside this scheduler.

## 20. Latest Safe Reporting Window

DTO `LatestSafeReportingWindow`: status, `latest_safe_date`, `provider_local_reporting_date`, timezone, policy version, reason. Informational “local today” is never auto-treated as complete.

## 21. LatestSafeReportingWindowResolver

Wraps `CollectableEndResolver` + freshness policy. Statuses: `AVAILABLE`, `NOT_YET_AVAILABLE` (`NO_SAFE_INTERVAL`), `POLICY_BLOCKED`, `UNSUPPORTED`. Missing lag on historical/period modes → policy blocked (no guessing).

## 22. Never “Today” as Safe Frontier

Tests assert `latestSafeDate < providerLocalReportingDate` for GA4 daily under frozen clock with lag. Open period collectable only if policy `current_period_collectable` allows via CollectableEndResolver.

## 23. Collection Lifecycle Intents

Enum `CollectionLifecycleIntent`: `INITIAL_BACKFILL`, `INCREMENTAL`, `LATE_DATA_REPAIR`, `CATCH_UP`, `MANUAL` with deterministic `priorityRank()` 1–5.

## 24. Collection Lifecycle Actions

Enum `CollectionLifecycleAction`: executable intents plus `NO_WORK`, `BLOCKED`. Manual intent maps action to Incremental when labeling override.

## 25. Planning Block Reasons

Enum `CollectionPlanningBlockReason` covers disabled/paused schedule, credentials, unbound resource, unsupported dataset, missing policy, integrity, active compatible run, provider limited, no safe interval, auth not ready, action required.

## 26. Collection Lifecycle Planner

`CollectionLifecyclePlanner::planForDigitalAsset` fans out active schedulable bindings × contract families → primary datasets → incremental plan + frontier → candidates → priority sort → single intent selection → `CollectionPlanningDecision`. Never calls providers. Never uses AI.

## 27. Determinism / No AI

Same canonical state + policy + clock ⇒ same decision. No numeric collection score. No LLM mode selection. Arbitrary kinds `FULL_REFRESH_RECURRING` / `CUSTOM_SQL` / `AI_SELECTED_MODE` do not exist.

## 28. Intent Priority Ordering

1 Initial Backfill · 2 Catch-Up · 3 Incremental · 4 Late Data Repair · 5 Manual. Within same intent, dataset id tie-break. Higher priority suppresses mixing lower intents in the same plan.

## 29. Single Intent Per Plan

Selected candidates filtered to primary intent only. Backfill and Repair never share one execution plan. Gap context / repair context snapshots filled only for matching intent.

## 30. INITIAL_BACKFILL

Required when coverage/materialization state says initial backfill before incremental. Executed via `StartCollectionService` + `CollectionTriggerType::InitialBackfill`. See lifecycle contract.

## 31. INCREMENTAL

New safe coverage / snapshot refresh / contract upgrade. Executed via `StartIncrementalCollectionService`. Extends coverage; does not imply gap repair or late revision window alone.

## 32. LATE_DATA_REPAIR

Mapped from `LATE_DATA_REPROCESS` when it wins priority. Requires explicit fixed-window policy. See late-data repair contract.

## 33. CATCH_UP

Mapped from `GAP_RECOVERY` / `CATCH_UP`. Fills missing coverage toward frontier within bounded span. Distinct from late repair.

## 34. MANUAL

`runNow()` sets `manual=true`. Uses same planner; does not default to full backfill when current. May label intent Manual while executing due work.

## 35. NO_WORK

No executable candidates / no new safe interval. Executor returns `no_work` without fabricating `CollectionRun`. Adapter may complete occurrence with message.

## 36. BLOCKED

Hard planning stop with block reason. Adapter: pause/disabled/no-safe → occurrence `skipped`; other blocks → `failed`.

## 37. Immutable Collection Lifecycle Plan

`toImmutablePlan` builds `ImmutableCollectionLifecyclePlan` with fingerprint `life:{sha256(payload)}`, snapshots, policy pins, windows. Null when decision not executable.

## 38. Plan Fingerprint Idempotency

Active runs matched on `metadata.plan_fingerprint` / `lifecycle_plan_fingerprint`. Initial backfill idempotency key hashes plan fingerprint (+ optional suffix). Queue retry with same suffix reuses one logical run.

## 39. ExecuteCollectionLifecycleService

Plans, short-circuits NO_WORK/BLOCKED, builds immutable plan, checks active equivalent, routes Initial Backfill vs incremental family, stamps run metadata (intent, policy, fingerprints).

## 40. Canonical Orchestrator Only

Executor never calls provider HTTP/collectors directly. Only `StartCollectionService` / `StartIncrementalCollectionService`. Comment + adapter tests assert `Http::recorded()` empty at adapter boundary.

## 41. StartCollectionService Integration

Initial Backfill builds `StartCollectionRequest` with binding/family/provider pins, lifecycle context, freshness policy version. Trigger type Initial Backfill.

## 42. StartIncrementalCollectionService Integration

Catch-Up / Incremental / Late Repair / Manual-due work start through incremental service with lifecycle context, windows, watermark/frontier snapshots, idempotency suffix. Maps `data_current` → executor `no_work`.

## 43. CollectionScheduleAdapter Integration

Discover due Active schedules; execute calls `executeForDigitalAsset` with occurrence id, schedule id, `idempotency_suffix=recurring:{occurrence_key}`, `collection_enabled=true`. Frequencies hourly/daily; default misfire `catch_up_bounded`.

## 44. RecurringOccurrence Binding

On started/active_equivalent, adapter completes occurrence with `RecurringDomainRunType::CollectionRun` + domain run id. Ledger remains Prompt 61 owned.

## 45. Misfire / Catch-Up Bounded at Engine Layer

Occurrence materialization catch-up is bounded (max 2 for collection). Lifecycle Catch-Up separately bounds span via freshness `max_bounded_incremental_span_days`. Unbounded storms **FORBIDDEN**.

## 46. Run Now

`ExecuteCollectionLifecycleService::runNow` — operator path, same planner. Never forces full historical backfill when watermark meets frontier.

## 47. Active Equivalent Reuse

Non-terminal run with same plan fingerprint ⇒ `active_equivalent` / `ACTIVE_COMPATIBLE_RUN` without starting a duplicate.

## 48. Watermark Effect Rules

Owned by Prompt 27 materialization: success advances verified contiguous watermark; failures do not; late repair does not regress; gaps block jumping. Scheduler snapshots watermarks into plans but does not write a parallel watermark store.

## 49. Coverage Effect Rules

Initial Backfill establishes coverage; Incremental/Catch-Up extend/fill; Late Repair recollects overlapping recent window. Zero-row success still counts as coverage dates.

## 50. Integrity Dependency (Prompt 26)

Integrity-blocked dataset/resource ⇒ `INTEGRITY_BLOCKED` action Blocked. Trusted fresh cannot be claimed through scheduler bypass.

## 51. DueCollectionQueryService Reuse

Incremental start still queries due items for scope. Planner uses IncrementalCoveragePlanner directly for intent selection; due query remains scheduler-callable without provider HTTP.

## 52. IncrementalCoveragePlanner Reuse

Provider-neutral intervals + reasons + dispositions. Lifecycle `intentFromReasons` maps reasons with Catch-Up beating Late Repair when both present.

## 53. Chunking / Bounded Span

`max_bounded_incremental_span_days` from policy caps envelopes. Immutable plan freezes windows — no silent expansion mid-flight.

## 54. Concurrency

One active equivalent plan fingerprint; one intent per plan; occurrence claim/execute serialized by Prompt 61 job claim rules.

## 55. Rate / Cost Class

Projected onto policy DTO (`rate_limit_class`, default `cost_class=provider_owned_read`). Informational for ops/policy; enforcement remains in collectors/providers. Paid DFS routine schedule blocked.

## 56. No Downstream Domain Writes

Lifecycle execution updates Collection runs + pool via Engine only. No Findings/Evidence/Opportunities/Tasks/Outcomes/AI.

## 57. No Evidence / Finding / Opportunity / Task / Outcome

Explicit product guarantee. Prompt 63 may schedule intelligence **after** pool freshness — not inside P62 executor.

## 58. No Direct Provider HTTP

Planner, policy registry, resolver, executor, adapter: zero analytical provider calls. Tests fake HTTP and assert no recordings on adapter execute.

## 59. Pause / Resume / Archive

Paused → `SCHEDULE_PAUSED` blocked. Archived treated as collection disabled. Resume returns to Active discovery. Prompt 61 `isScheduleActive` gates inactive executes to skipped.

## 60. Authorization / Tenancy

`CollectionScheduleService` enforces Customer/Brand allowlists on create. Planner consumes `authorization_ready_by_binding_id`. Unready → credential/auth block reasons.

## 61. Privacy / Security

No tokens in plan snapshots — ids, dates, policy versions, fingerprints only. Operator web guard unchanged. No client portal scheduling.

## 62. Performance

Planning is DB + registry JSON; no provider HTTP. Dispatcher remains 5-minute tick. Bounded catch-up limits occurrence fan-out.

## 63. Localization / Timezones

Reporting TZ from resource metadata / policy source; schedule TZ for recurrence. GSC default Pacific semantics when applicable. Plans may store schedule timezone string.

## 64. Tests

`tests/Feature/CollectionScheduler/CollectionSchedulerLifecycleTest.php` — policy excludes DFS; latest-safe lag; initial backfill; incremental/catch-up; gap ⇒ catch-up not repair; pause/unbound blocks; orchestrator start; idempotent reuse; no-work; adapter wiring; intent mapping; run-now; forbidden kinds. `Http::fake()`, `Queue::fake()`, frozen `CollectionClock`.

## 65. Definition of Done

| Criterion | Status |
| --- | --- |
| Policy registry over P7+P27 | YES |
| Latest-safe resolver wrapping CollectableEnd | YES |
| Deterministic lifecycle planner + intents/actions/blocks | YES |
| Execute via orchestrator only | YES |
| Adapter routes through lifecycle | YES |
| DataForSEO not routinely scheduled | YES |
| No V2 scheduler/watermark/freshness entities | YES |
| No downstream domain / AI writes | YES |
| Feature tests green for lifecycle paths | YES |
| Architecture contracts + this doc | YES |
| Prompt 63 intelligence scheduling | NOT IN SCOPE |

## 66. Explicit Non-Goals

- Intelligence auto-scheduler (Prompt 63)
- Workflow builder / Zapier-like automation
- AI collection mode selection
- Absorbing domain schedules into polymorphic V2 tables
- Routine DataForSEO / WordPress collection ticks
- Redesigning frozen Milestone 5 IA
- Per-provider `Schedule::daily` ownership

## 67. Collection Primitive Matrix

| Primitive | Owner | P62 role |
| --- | --- | --- |
| `CollectionSchedule` | Domain (P61/62) | Explicit enablement + cadence |
| `RecurringOccurrence` | P61 engine | Due ledger / claim / execute |
| `CollectionLifecyclePlanner` | P62 | Intent selection |
| `CollectionSchedulingPolicyRegistry` | P62 | Versioned policy projection |
| `LatestSafeReportingWindowResolver` | P62 | Safe frontier |
| `ExecuteCollectionLifecycleService` | P62 | Orchestrator entry |
| `IncrementalCoveragePlanner` | P27 | Windows / reasons |
| `DatasetWatermarkCalculator` | P27 | Watermark snapshots |
| `DueCollectionQueryService` | P27 | Due query for incremental start |
| `StartCollectionService` | Collection Engine | Initial Backfill |
| `StartIncrementalCollectionService` | P27/62 | Incremental family start |
| `CollectionRun` | Collection Engine | Domain run bind |
| `CollectionSchedulerV2` | — | **FORBIDDEN / NONE** |
| `WatermarkV2` / `FreshnessV2` | — | **FORBIDDEN / NONE** |

## 68. Collection Mode Matrix

| Freshness `collection_mode` | Lifecycle behavior |
| --- | --- |
| `HISTORICAL_INCREMENTAL` | Backfill → catch-up/incremental → optional late repair; requires explicit lag |
| `PERIOD_OBSERVATION` | Same safe-frontier discipline; missing lag ⇒ policy blocked |
| `CURRENT_SNAPSHOT` | Snapshot refresh / stale catch-up semantics; late repair often N/A (`replace_current_snapshot`) |
| `CONTROLLED_ON_DEMAND` | Not routine incremental; families may be skipped/unsupported |
| `STATIC_OR_SLOW_METADATA` | Often `incremental_applicable=false` → dataset `NO_WORK` |
| Unknown / missing policy | `POLICY_NOT_CONFIGURED` → `BLOCKED` |

## 69. Repair vs Catch-Up Matrix

| Dimension | Catch-Up | Late Data Repair |
| --- | --- | --- |
| Problem | Missing / gapped coverage | Revisions inside covered recent window |
| Incremental reasons | `CATCH_UP`, `GAP_RECOVERY` | `LATE_DATA_REPROCESS` |
| Lifecycle intent | `CATCH_UP` | `LATE_DATA_REPAIR` |
| Priority | Higher (rank 2) | Lower (rank 4) |
| Policy basis | catch-up + max span | `fixed_recent_reporting_window` + `window_days` |
| Watermark | Advances when gaps filled | Does not regress; `last_reprocess_through` |
| Overlap existing coverage | Fills holes | May re-pull overlapped days |
| Equating the two | **FORBIDDEN** | **FORBIDDEN** |

## 70. Prompt61/62 Boundary Matrix

| Concern | Prompt 61 | Prompt 62 |
| --- | --- | --- |
| Occurrence ledger / claim / job | YES | Consumes |
| Schedule kinds registry | YES | Collection kind only |
| Misfire / frequency allowlist | YES | Collection hourly/daily |
| What intent to collect | NO (incremental-only historically) | YES lifecycle planner |
| Policy / latest-safe / intents | NO | YES |
| Direct provider calls | NO | NO |
| Initial Backfill via collection adapter | Was NO | YES when state requires |
| Intelligence scheduling | NO | NO (→ P63) |
| `SchedulerV2` | NONE | NONE |

## 71. Prompt63 Handoff

Prompt 63 (Intelligence Scheduling) may consume **stable pool freshness / materialization state** and domain events after collection runs complete. It must **not** embed inside `ExecuteCollectionLifecycleService`, must **not** call collectors, and must **not** treat P62 as an intelligence workflow engine.

### Prompt63 Boundary Matrix

| Capability | Prompt 62 | Prompt 63 |
| --- | --- | --- |
| Collection lifecycle plan/execute | YES | NO |
| Provider collectors / pool writes via Engine | YES (via orchestrator) | NO |
| Finding / Opportunity / Task auto materialization | **NONE** | Future owner |
| AI agent scheduling | **NONE** | Future owner |
| RecurringOccurrence collection kind | Consumes P61 | Must not hijack |
| DataForSEO routine ticks | **FORBIDDEN** | Still forbidden unless explicit product prompt |
| Downstream domain writes from collection tick | **FORBIDDEN** | Separate explicit prompts only |

## 72. Reality Matrix

| Capability | Status |
| --- | --- |
| `CollectionSchedulingPolicyRegistry` over P7+P27 | **REAL** |
| `LatestSafeReportingWindowResolver` | **REAL** |
| `CollectionLifecyclePlanner` deterministic intents | **REAL** |
| `ExecuteCollectionLifecycleService` via orchestrator | **REAL** |
| `CollectionScheduleAdapter` → lifecycle | **REAL** |
| Initial Backfill when coverage absent | **REAL** |
| Incremental / Catch-Up / Late Repair intents | **REAL** |
| Manual Run Now same planner | **REAL** |
| Plan fingerprint idempotency / active equivalent | **REAL** |
| DataForSEO routine scheduling | **NONE / FORBIDDEN** |
| Provider HTTP in scheduler path | **NONE** |
| Evidence/Finding/Opportunity/Task/Outcome/AI from P62 | **NONE** |
| `CollectionSchedulerV2` / `WatermarkV2` / `FreshnessV2` | **NONE** |
| Intelligence scheduling (Prompt 63) | **REAL** (Evidence-change triggers; CollectionRun completion is not a trigger) |
| Filament schedule CRUD completeness | **PARTIAL / NOT REQUIRED for scheduler REAL** |

---

## Code Map

| Path | Role |
| --- | --- |
| `app/Enums/Collection/CollectionLifecycleIntent.php` | Intents + priority |
| `app/Enums/Collection/CollectionLifecycleAction.php` | Actions |
| `app/Enums/Collection/CollectionPlanningBlockReason.php` | Blocks |
| `app/Services/CollectionScheduler/CollectionSchedulingPolicyRegistry.php` | Policy projection |
| `app/Services/CollectionScheduler/LatestSafeReportingWindowResolver.php` | Safe frontier |
| `app/Services/CollectionScheduler/CollectionLifecyclePlanner.php` | Planner |
| `app/Services/CollectionScheduler/ExecuteCollectionLifecycleService.php` | Executor |
| `app/Support/CollectionScheduler/*` | Policy/plan/decision/window DTOs |
| `app/Services/RecurringAutomation/Adapters/CollectionScheduleAdapter.php` | P61 adapter |
| `app/Services/Collection/CollectionScheduleService.php` | Enablement CRUD |
| `tests/Feature/CollectionScheduler/CollectionSchedulerLifecycleTest.php` | Feature coverage |
