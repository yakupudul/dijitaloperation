# Collection Scheduling Policy Contract

> Prompt 62 — versioned Provider × Resource Type × Dataset scheduling policy.  
> Implementation: `CollectionSchedulingPolicyRegistry`, `CollectionSchedulingPolicy`.  
> Sources: Prompt 7 Data Contract Registry + Prompt 27 `MOXDOP_DATA_FRESHNESS_POLICY_V1` (no duplicate magic constants).  
> Related: [`COLLECTION_LIFECYCLE_CONTRACT.md`](COLLECTION_LIFECYCLE_CONTRACT.md) · [`LATE_DATA_REPAIR_CONTRACT.md`](LATE_DATA_REPAIR_CONTRACT.md) · [`docs/implementation/COLLECTION_SCHEDULER.md`](../implementation/COLLECTION_SCHEDULER.md)

## Canonical rule

Scheduling behavior is **code/config owned** and **dataset-specific**. The registry identity is `MOXDOP_COLLECTION_SCHEDULING_POLICY`. Policy version and fingerprint derive from the freshness policy version + data-contract checksum — not from operator UI free-text, not from AI, and not from a `CollectionSchedulerV2` / `WatermarkV2` / `FreshnessV2` table.

---

## Provider

| Rule | Contract |
| --- | --- |
| Schedulable analytics providers | `GA4`, `SEARCH_CONSOLE`, `GOOGLE_ADS`, `META_ADS` only |
| Capability map | `ga4` → GA4 · `search_console` → SEARCH_CONSOLE · `google_ads` → GOOGLE_ADS · `meta_ads` → META_ADS |
| DataForSEO | **NOT** routinely scheduled (`isDataForSeoRoutinelyScheduled() === false`) |
| WordPress / website scrape | **NOT** in `CAPABILITY_PROVIDER` — absent from routine scheduling |
| Provider HTTP from registry | **FORBIDDEN** — registry reads JSON/contracts only |

---

## Resource Type

| Rule | Contract |
| --- | --- |
| Source field | Freshness policy `resource_type` or `resource_kind` |
| Binding gate | Active `CoreAssetBinding` for a schedulable capability |
| Unbound / disabled binding | Planner → `BLOCKED` / `RESOURCE_UNBOUND` |
| Timezone | Prefer external-resource metadata (`timezone`, `timezone_name`, `timeZone`, `time_zone`); else policy `timezone_source` |

---

## Dataset

| Rule | Contract |
| --- | --- |
| Primary dataset per request family | `primaryDatasetForFamily()` via Data Contract requirements |
| Policy lookup | `policy(datasetId)` → `CollectionSchedulingPolicy` or `null` |
| Missing policy | `POLICY_NOT_CONFIGURED` → blocked |
| Deferred / unsupported families | Skipped (`DEFERRED`, `UNSUPPORTED`, `UNAVAILABLE`, `DEMO_ONLY`) |
| Explicit family exclusions | `GSC_RF_APPEARANCE_DAILY`, `GSC_RF_URL_INSPECTION` not scheduled |

---

## Eligibility

| Field | Contract |
| --- | --- |
| `eligible` | `false` when `incremental_applicable === false` |
| `ineligibility_reason` | From `non_applicable_reason` or `POLICY_NOT_CONFIGURED` |
| Planner effect | Ineligible dataset → `NO_WORK` for that dataset row (not Initial Backfill) |
| Integrity | Prompt 26 migration-blocking fail → `INTEGRITY_BLOCKED` (trusted fresh forbidden) |
| Authorization | Binding not ready → `CREDENTIAL_INVALID` / `AUTHORIZATION_NOT_READY` |

---

## Required History

| Field | Contract |
| --- | --- |
| Source | Freshness `contract_history_policy` |
| Typical daily analytics | e.g. `minimum_required` / `recommended_initial_backfill` = `180d` (dataset-specific) |
| Snapshot metadata | Often `current` |
| Role | Informs Initial Backfill scope via Collection Engine / contracts — scheduler does not invent history lengths |

---

## Grain

| Field | Contract |
| --- | --- |
| `reporting_grain` | List or string from freshness policy (e.g. `property_id` + `date`) |
| Watermark grain | Per Resource × Dataset (Prompt 27) — never global `last_sync_at` |

---

## Timezone Source

| Value / pattern | Contract |
| --- | --- |
| `ga4_property_timezone` | Resource metadata TZ, else UTC |
| `gsc_reporting_date_semantics` | Default `America/Los_Angeles` when no resource TZ |
| `resource_timezone_or_utc` | Default fallback string on `CollectionSchedulingPolicy` |
| Schedule TZ | `CollectionSchedule.timezone` for occurrence materialization (Prompt 61) — distinct from reporting TZ |

---

## Latest Safe Policy

| Field | Contract |
| --- | --- |
| Resolver | `LatestSafeReportingWindowResolver` wraps Prompt 27 `CollectableEndResolver` |
| Inputs | `safe_collection_lag_days`, `current_period_collectable`, `collection_mode`, reporting TZ, clock |
| Statuses | `AVAILABLE` · `NOT_YET_AVAILABLE` · `POLICY_BLOCKED` · `UNSUPPORTED` |
| Forbidden | Treating wall-clock “today” as collectable end unless policy explicitly allows open period |
| Missing lag on historical modes | `POLICY_BLOCKED` — never guess lag days |

---

## Incremental Cadence

| Field | Contract |
| --- | --- |
| `expected_refresh_cadence` | From freshness policy (typically `daily`) |
| Schedule frequencies allowed | Prompt 61 adapter: **hourly** · **daily** only |
| Engine tick | `moxdop:dispatch-due-automations` every 5 minutes |
| Due work truth | Prompt 27 `IncrementalCoveragePlanner` / `DueCollectionQueryService` — not schedule history alone |

---

## Late Repair Capability / Range / Cadence

| Field | Contract |
| --- | --- |
| Enabled when | `late_data_reprocessing.strategy === fixed_recent_reporting_window` **and** `window_days` is positive int |
| Disabled / other strategies | e.g. `replace_current_snapshot`, `none` → `lateDataRepairEnabled = false` for repair intent |
| Range | Dataset-specific `window_days` (commonly 7d for GA4/GSC/Meta daily; Ads conversion actions may be 30d per freshness registry) |
| Cadence | Driven by freshness evaluator `reprocessDue` + schedule tick — **no** global reprocess window |
| Overlap | Allowed when policy says `overlap_existing_coverage_allowed: true` |
| Full contract | [`LATE_DATA_REPAIR_CONTRACT.md`](LATE_DATA_REPAIR_CONTRACT.md) |

---

## Catch-Up

| Field | Contract |
| --- | --- |
| Policy source | Freshness `catch_up_policy` (e.g. `coverage_gap_to_collectable_end`, `snapshot_refresh_if_stale`) |
| `catchUpEnabled` | `false` only when catch-up policy explicitly sets `enabled: false` |
| Planner mapping | `GAP_RECOVERY` / `CATCH_UP` incremental reasons → lifecycle intent `CATCH_UP` |
| Bound | `max_bounded_incremental_span_days` + Prompt 61 occurrence `maxCatchUp` (collection adapter = 2) |
| Distinct from late repair | Gaps in verified coverage ≠ late-arriving revisions inside already-covered range |

---

## Chunking

| Field | Contract |
| --- | --- |
| `max_bounded_incremental_span_days` | Caps single incremental envelope (e.g. 31 for many daily datasets) |
| Window merge | Prompt 27 planner merges overlapping intervals — scheduler does not invent unbounded spans |
| Immutable plan | Execution must not silently expand windows when newer safe dates appear after plan pin |

---

## Concurrency

| Rule | Contract |
| --- | --- |
| Active equivalent | Same `plan_fingerprint` / lifecycle metadata → reuse active `CollectionRun` |
| One intent per plan | Do not mix Initial Backfill with Late Repair in one execution |
| Terminal statuses | Completed / Failed / Partial / Cancelled / Skipped / NotEligible release equivalence |
| Queue retry | Same `idempotency_suffix` (e.g. `recurring:{occurrence_key}`) → same logical run |

---

## Rate / Cost Class

| Field | Contract |
| --- | --- |
| `rate_limit_class` | Optional string from freshness raw policy (may be null) |
| `cost_class` | Defaults to `provider_owned_read` when absent |
| Paid enrichment | DataForSEO routine scheduling **FORBIDDEN** — prevents hidden paid cost storms |
| Scheduler HTTP | **FORBIDDEN** — rate/cost classes inform policy only; collectors remain inside Collection Engine |

---

## Policy Version

| Field | Contract |
| --- | --- |
| `policyIdentity` | `MOXDOP_COLLECTION_SCHEDULING_POLICY` |
| `policyVersion` | Per-dataset freshness `policy_version`, else registry freshness version |
| Registry `version()` | Freshness policy loader version |
| `policyFingerprint` | SHA-256 of dataset scheduling payload (mode, lag, cadence, late, catch-up, history, version, …) |
| Registry `fingerprint()` | SHA-256 of identity + freshness version + contract checksum + freshness registry id |
| Plan pin | Immutable plan stores policy identity / version / fingerprint on `CollectionRun.metadata` |

---

## Forbidden

- `CollectionSchedulerV2`, `WatermarkV2`, `FreshnessV2`, per-provider shadow policy tables
- Magic lag / reprocess constants outside Prompt 27 registry
- Global `last_sync_at` or global reprocess window as scheduling truth
- AI policy inference
- Routine DataForSEO / WordPress scheduling
- Direct provider collector calls from the policy registry or scheduler path
