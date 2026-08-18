# DATA FRESHNESS & INCREMENTAL COLLECTION

Prompt 27 — Data Freshness & Incremental Collection.

Status: **REAL** (policy registry, watermark semantics, provider-neutral planner, due query, manual/system incremental start).  
Automatic recurring scheduler: **NOT YET** (Prompt 61/62).

## 1. Purpose

Keep production pool data **current** after initial backfill without re-running full history. Provide deterministic, dataset-specific freshness truth per Resource × Dataset and a provider-neutral incremental planner that the Collection Engine can execute.

## 2. Why Last HTTP Success Is Not Freshness

`CollectionRun` completed ≠ verified coverage through collectable end.  
`DatasetMaterialization.coverage_end_date` (min/max bounds) ≠ contiguous verified watermark.  
`MAX(reporting_date)` in facts ≠ proof of successful collection for that day.  
Provider async 100% ≠ daily coverage complete.

Freshness is derived from **successful coverage evidence** + policy, not transport success alone.

## 3. Architecture

```text
MOXDOP_DATA_FRESHNESS_POLICY_V1.json
        ↓
DataFreshnessPolicyLoader
        ↓
CollectableEndResolver + DatasetWatermarkCalculator
        ↓
DatasetFreshnessEvaluator
        ↓
IncrementalCoveragePlanner
        ↓
DueCollectionQueryService / StartIncrementalCollectionService
        ↓
CollectionPlanner (incremental path) + Google/Meta Incremental Orchestrators
        ↓
Collection Engine executors → MaterializationService (coverage metadata)
```

No per-provider freshness silos. No GAQL/GA4/GSC/Meta request syntax in the planner.

## 4. Policy Registry

`docs/data-contracts/MOXDOP_DATA_FRESHNESS_POLICY_V1.json` (+ schema, short registry doc).  
44 production datasets across SEARCH_CONSOLE, GA4, GOOGLE_ADS, META_ADS.  
References Integrity Registry profiles; does not redefine formulas or contracts.

## 5. Global Prohibitions

- No global `last_sync_at` truth.
- No numeric freshness score.
- No global reprocess window.
- No `Schedule::daily` collection ownership in Prompt 27.

## 6. Watermark Model

| Concept | Meaning |
| --- | --- |
| `verified_contiguous_watermark` | Latest reporting date with **unbroken** successful coverage from earliest interval |
| `latest_observed_reporting_date` | Max successful date (may exceed watermark when internal gaps exist) |
| `current_collectable_end` | Policy lag + resource timezone; open day not auto-complete |
| `successful_coverage_dates` | Inclusive Y-m-d days with successful collection (incl. zero-row) |
| `zero_row_success_dates` | Subset proving coverage without fact rows |

`CoverageIntervalSet::verifiedContiguousWatermark()` never jumps unresolved gaps.

## 7. Collectable End

`CollectableEndResolver` uses dataset policy `safe_collection_lag_days`, `current_period_collectable`, and resource reporting timezone.  
GSC default semantics: `America/Los_Angeles` when no resource TZ override.  
Wall-clock “today” is informational; it is not automatically a complete reporting day.

## 8. Freshness States

Deterministic enum per Resource × Dataset:

| State | Meaning |
| --- | --- |
| `FRESH` | Verified coverage meets collectable end; reprocess not due |
| `DUE` | Work due within SLA |
| `STALE` | SLA exceeded or material lag |
| `PARTIAL` | Internal coverage gap |
| `ACTION_REQUIRED` | Auth/binding not ready |
| `PROVIDER_LIMITED` | History limit not accepted |
| `INTEGRITY_BLOCKED` | Prompt 26 blocking integrity failure |
| `FRESH_WITH_LIMITATION` | Coverage meets provider-obtainable boundary |
| `UNKNOWN` | Non-applicable or unproven continuity |

## 9. Incremental Work Reasons

`NEW_COVERAGE` · `CATCH_UP` · `LATE_DATA_REPROCESS` · `GAP_RECOVERY` · `SNAPSHOT_REFRESH` · `CONTRACT_UPGRADE` · `MANUAL_REPLAY`

Planner merges overlapping intervals into a single executable `date_range` envelope.

## 10. IncrementalCoveragePlanner

Provider-neutral planning only:

- Gap recovery before advancing past holes
- Catch-up when verified watermark lags collectable end
- Late-data reprocess when `reprocessDue` (dataset-specific window)
- Snapshot refresh when SLA exceeded
- Bounded span via `max_bounded_incremental_span_days`
- Returns `PlanDisposition`: `Eligible`, `AlreadySatisfied`, `ActionRequired`, `IntegrityBlocked`, `ProviderLimited`, `NotEligible`, `Unsupported`

Does **not** build GAQL, GA4 bodies, GSC payloads, or Meta Insights requests.

## 11. Due Collection Query

`DueCollectionQueryService` — DB + contract/policy driven, **zero analytical provider calls**.  
Joins active `CoreAssetBinding` × executable request families × materializations.  
Callable by future Prompt 62 scheduler. Filters: customer/brand/asset/provider, `authorization_ready`, `integrity_blocked`.

## 12. Start Incremental Collection

`StartIncrementalCollectionService` — manual/system entrypoint:

- `data_current` when no executable due work
- `started` with `CollectionTriggerType::Incremental` and `collection_intent=incremental_refresh`
- Idempotent via `plan_fingerprint` on active runs
- Google/Meta integration pages route to incremental orchestrators when backfill `already_satisfied`

No cron ownership here.

## 13. Collection Engine Integration

`CollectionPlanner` incremental path uses freshness decisions.  
`MaterializationService::recordSuccessfulCoverageDates` persists coverage metadata and advances watermark from interval evidence.  
Collectors record zero-row successful days (GA4/GSC/Ads/Meta).

## 14. Snapshot vs Historical

| Mode | Freshness basis | Daily watermark |
| --- | --- | --- |
| `HISTORICAL_INCREMENTAL` | Verified contiguous dates vs collectable end | yes |
| `CURRENT_SNAPSHOT` | `last_collected_at` vs `freshness_sla_hours` | no |
| `CONTROLLED_ON_DEMAND` | Operator trigger | no |
| `STATIC_OR_SLOW_METADATA` | Non-applicable incremental | no |

## 15. Integrity Dependency

Trusted fresh blocked when Prompt 26 migration-blocking integrity failure is flagged per dataset/resource (`INTEGRITY_BLOCKED`).  
Auditor does not schedule collection; evaluator respects integrity context.

## 16. Provider History Limitations

When provider history is shorter than desired range and limitation is accepted, `FRESH_WITH_LIMITATION` at obtainable boundary.  
Unaccepted limitation → `PROVIDER_LIMITED` (no silent fabrication).

## 17. Zero-Row Coverage

Successful collection with zero fact rows still records the reporting date in `successful_coverage_dates` / `zero_row_success_dates` and can advance verified watermark.

## 18. Failed Collection

Failed refresh may mark materialization `STALE` but does **not** add successful coverage dates or regress verified watermark.

## 19. Reprocessing

Dataset-specific `late_data_reprocessing.window_days` (e.g. GSC/GA4/Meta daily 7d; `google_ads_conversion_action_daily` 30d).  
May overlap existing coverage. Reprocess alone does not regress watermark.  
`last_reprocess_through` in `freshness_metadata` suppresses repeat reprocess when current.

## 20. Scheduler Boundary

`config/moxdop-data-freshness.php`: `recurring_scheduler_enabled=false`.  
`routes/console.php` has **no** `Schedule::daily` collection.  
Prompt 61/62 owns automatic recurring scheduler.

## 21. Security

No tokens in freshness metadata. Counts, dates, policy versions only.

## 22. Performance

Due query uses indexed bindings/materializations; no provider HTTP in query path.

## 23. Tests

`tests/Feature/DataPool/DataFreshnessIncrementalCollectionTest.php` — 21 feature tests, `Http::fake()`, frozen `CollectionClock`, no live provider calls.

## 24. Reality Matrix

See Milestone 5 Capability Reality Matrix (Prompt 27 rows).

## 25. Prompt 28+ Handoff

Specialist real-data UI (Prompts 28–31) consumes pool freshness via materialization/read models; does not redefine policy.

## 26. Definition of Done

- Freshness policy registry V1 validated for all 44 production datasets
- Watermark / collectable-end / evaluator / planner / due query / start service implemented
- Materialization coverage metadata wired from collectors
- Google/Meta incremental orchestration entrypoints
- No automatic collection scheduler
- Feature tests green
- Documentation + milestone matrix updated

---

## §211 DATASET FRESHNESS MATRIX (by provider category)

### SEARCH_CONSOLE (9)

| Dataset | Mode | Incremental | Safe lag | Reprocess window | SLA (h) |
| --- | --- | --- | ---: | ---: | ---: |
| `gsc_property_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_query_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_page_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_query_page_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_country_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_device_daily` | HISTORICAL_INCREMENTAL | yes | 3 | 7d | 48 |
| `gsc_search_appearance_daily` | STATIC_OR_SLOW_METADATA | **no** | — | — | — |
| `gsc_url_inspection_snapshot` | CONTROLLED_ON_DEMAND | **no** | — | — | — |
| `gsc_sitemap_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |

TZ source: `gsc_reporting_date_semantics` (Pacific default; resource TZ override allowed).

### GA4 (11)

| Dataset | Mode | Incremental | Safe lag | Reprocess window | SLA (h) |
| --- | --- | --- | ---: | ---: | ---: |
| `ga4_property_metadata` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `ga4_property_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_acquisition_channel_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_source_medium_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_campaign_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_landing_page_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_event_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_event_channel_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_event_source_medium_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_event_campaign_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_event_landing_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |
| `ga4_device_daily` | HISTORICAL_INCREMENTAL | yes | 2 | 7d | 48 |

TZ source: GA4 property timezone.

### GOOGLE_ADS (14)

| Dataset | Mode | Incremental | Safe lag | Reprocess window | SLA (h) |
| --- | --- | --- | ---: | ---: | ---: |
| `google_ads_account_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_account_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `google_ads_campaign_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_campaign_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `google_ads_ad_group_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_ad_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_keyword_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_keyword_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `google_ads_search_term_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `google_ads_landing_page_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `google_ads_conversion_action_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_conversion_action_daily` | HISTORICAL_INCREMENTAL | yes | 1 | **30d** | 48 |
| `google_ads_campaign_budget_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `google_ads_asset_coverage_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |

TZ source: Google Ads customer timezone.

### META_ADS (9)

| Dataset | Mode | Incremental | Safe lag | Reprocess window | SLA (h) |
| --- | --- | --- | ---: | ---: | ---: |
| `meta_ad_account_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `meta_campaign_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `meta_adset_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `meta_creative_snapshot` | CURRENT_SNAPSHOT | snapshot | — | — | 168 |
| `meta_campaign_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `meta_adset_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `meta_ad_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `meta_typed_action_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |
| `meta_delivery_breakdown_daily` | HISTORICAL_INCREMENTAL | yes | 1 | 7d | 48 |

TZ source: Meta ad account timezone.

## §212 COLLECTION MODE MATRIX

| Mode | Count (approx) | Watermark | Planner output |
| --- | ---: | --- | --- |
| `HISTORICAL_INCREMENTAL` | 28 | daily contiguous | `date_range` + reasons |
| `CURRENT_SNAPSHOT` | 14 | none | `SNAPSHOT_REFRESH` interval |
| `CONTROLLED_ON_DEMAND` | 1 | none | blocked / operator |
| `STATIC_OR_SLOW_METADATA` | 1 | none | blocked / not applicable |

## §213 FRESHNESS STATE DECISION MATRIX

| Condition | State | Collection due? |
| --- | --- | --- |
| Auth/binding not ready | `ACTION_REQUIRED` | no |
| Integrity blocking fail | `INTEGRITY_BLOCKED` | no |
| Incremental not applicable | `UNKNOWN` | no |
| Internal gap in coverage | `PARTIAL` | yes |
| Never collected / unproven | `DUE` / `UNKNOWN` | yes / varies |
| Behind collectable end (within SLA) | `DUE` | yes |
| Behind collectable end (SLA exceeded) | `STALE` | yes |
| Meets collectable end | `FRESH` | no |
| Provider limit accepted at boundary | `FRESH_WITH_LIMITATION` | no |
| Provider limit not accepted | `PROVIDER_LIMITED` | no |
| Snapshot never collected | `DUE` | yes |
| Snapshot SLA exceeded | `STALE` | yes |
| Snapshot within SLA | `FRESH` | no |

## §214 INCREMENTAL WORK MATRIX

| Reason | Trigger |
| --- | --- |
| `GAP_RECOVERY` | `internal_gaps` non-empty |
| `NEW_COVERAGE` | Single new collectable day |
| `CATCH_UP` | Multi-day lag to collectable end |
| `LATE_DATA_REPROCESS` | `reprocessDue` + fixed window |
| `SNAPSHOT_REFRESH` | Snapshot SLA/stale |
| `CONTRACT_UPGRADE` | Reserved |
| `MANUAL_REPLAY` | Operator replay (future) |

Overlapping new + reprocess → single merged `date_range` envelope.

## §215 TIMEZONE & COLLECTABLE END MATRIX

| Provider | TZ source | Default when resource TZ missing | Open day complete? |
| --- | --- | --- | --- |
| GA4 | property timezone | UTC | no (lag ≥ 1) |
| GSC | reporting-date semantics | America/Los_Angeles | no (lag 3) |
| Google Ads | customer timezone | UTC | no (lag 1) |
| Meta Ads | ad account timezone | UTC | no (lag 1) |

Resource metadata `timezone` / `timezone_name` overrides default for planning.

## §216 WATERMARK EVIDENCE MATRIX

| Source | Verified watermark? | Notes |
| --- | --- | --- |
| `successful_coverage_dates` | yes (via intervals) | canonical |
| `zero_row_success_dates` | yes (merged into successful) | coverage without rows |
| `coverage_end_date` alone | **no** | bounds only |
| `MAX(fact_date)` | **no** | never trusted alone |
| Failed collection | no advance | may mark STALE |
| Reprocess same dates | no regression | watermark monotonic |

## §217 PLAN DISPOSITION MATRIX

| Disposition | Executable | Typical cause |
| --- | --- | --- |
| `Eligible` | yes | due incremental/snapshot work |
| `AlreadySatisfied` | no | fresh / no intervals |
| `ActionRequired` | no | auth/binding |
| `IntegrityBlocked` | no | Prompt 26 block |
| `ProviderLimited` | no | unaccepted history cap |
| `NotEligible` | no | non-applicable / initial backfill |
| `Unsupported` | no | missing policy |

## §218 DUE QUERY PRIORITY MATRIX

| `priorityCategory` | Reasons / state |
| --- | --- |
| `stale` | `FreshnessState::Stale` |
| `gap_recovery` | `GAP_RECOVERY` |
| `catch_up` | `CATCH_UP` |
| `new_coverage` | `NEW_COVERAGE` |
| `reprocess` | `LATE_DATA_REPROCESS` |
| `snapshot` | `SNAPSHOT_REFRESH` |
| `due` | default |
| `action_required` | non-executable auth issues |

## §219 SCHEDULER / ORCHESTRATION MATRIX

| Capability | Prompt 27 | Owner |
| --- | --- | --- |
| Policy registry | REAL | Prompt 27 |
| Watermark + evaluator | REAL | Prompt 27 |
| Incremental planner | REAL | Prompt 27 |
| Due query (DB only) | REAL | Prompt 27 |
| `StartIncrementalCollectionService` | REAL | Prompt 27 |
| Google/Meta Collect Data → incremental | REAL | Prompt 27 |
| `Schedule::daily` collection | **NOT YET** | Prompt 61/62 |
| Automatic recurring scheduler UI | **NOT YET** | Prompt 62 |

## §220 INTEGRATION TOUCHPOINT MATRIX

| Component | Role |
| --- | --- |
| `DataFreshnessPolicyLoader` | Load/validate registry |
| `CollectableEndResolver` | Policy lag + TZ |
| `DatasetWatermarkCalculator` | Interval evidence → snapshot |
| `DatasetFreshnessEvaluator` | State machine |
| `IncrementalCoveragePlanner` | Executable intervals |
| `DueCollectionQueryService` | Scheduler-facing due list |
| `StartIncrementalCollectionService` | Start incremental run |
| `MaterializationService` | Persist coverage metadata |
| `CollectionPlanner` | Engine plan with incremental decisions |
| `GoogleIncrementalCollectionOrchestrator` | Google entry |
| `MetaIncrementalCollectionOrchestrator` | Meta entry |
| `CoverageIntervalSet` | Shared gap/watermark math (Prompt 26) |
