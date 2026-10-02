# GA4 Real Data Migration (Prompt 28)

## 1. Purpose

Prompt 28 migrates the frozen GA4 Measurement Intelligence specialist workspace (`AnalyticsPage`, path `/app/assets/analytics/{assetId}`) from deterministic Demo fixtures to **read-only presentation** of the normalized GA4 data pool populated by Prompt 18 (production collector) and gated by Prompt 26 (integrity) + Prompt 27 (freshness/materialization).

Hard rules enforced in this migration:

- **No live GA4 Analytics Data API call on page render** — all numbers come from local `ga4_*` tables + `CoreAssetBinding` + `DatasetMaterialization` + integrity audit results.
- **No Demo fallback on query exceptions** — errors yield an `UNAVAILABLE` operational workspace, never fixture substitution.
- **No Demo+Real mixing inside a single KPI or chart series** — e.g. Sessions (real) and Business actions (Demo) never share `performance_trend`.
- **No Evidence / Findings / Opportunities / Business Outcomes created** by this migration.
- **Demo catalog asset** (`ga4-atlas`) remains 100% Demo fixtures with explicit `DEMO` provenance.

## 2. Architecture Overview

```
AnalyticsPage (Livewire)
    └── Ga4SpecialistReadService::workspace(assetId, preset, start, end)
            ├── Ga4SpecialistBindingResolver → Ga4BindingContext (DemoCatalog | RealBound | NotConnected | ActionRequired)
            ├── Ga4UiDatasetGate → Ga4DatasetReadiness per dataset (integrity + coverage + freshness)
            ├── Ga4PoolReadRepository → bounded SQL over ga4_* (no firstUser*, no totalUsers period sum)
            └── Ga4FormulaCalculator → MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_GA4_*)
```

| Component | Role |
|---|---|
| `DataSourceState` | Per-field provenance label (`REAL`, `REAL_DERIVED`, `PARTIAL_REAL`, `DEMO`, `UNAVAILABLE`, `PROVIDER_LIMITED`, `STALE_REAL`) |
| `Ga4SpecialistBindingResolver` | Only human-confirmed active `CoreAssetBinding` (`capability=ga4`); never picks first-available property |
| `Ga4UiDatasetGate` | Rows-in-table ≠ ready; requires materialization + integrity `READY_FOR_REAL_UI` + proven coverage dates |
| `Ga4PoolReadRepository` | Single sanctioned read path to `ga4_*`; session-scoped acquisition dimensions |
| `Ga4FormulaCalculator` | Derived metrics (engagement rate, shares, UTM %) via sum/sum — never avg of daily rates |

`refreshData()` on `AnalyticsPage` delegates to `StartIncrementalCollectionService` when `RealBound`; Demo catalog flashes Demo-only notice.

## 3. Field Migration Matrix

Classification key:

| State | Meaning |
|---|---|
| `REAL` | Direct pool read, full coverage |
| `REAL_DERIVED` | Formula on summed pool facts |
| `PARTIAL_REAL` | Pool read with partial proven coverage in range |
| `DEMO` | Residual Demo fixture (explicitly documented §8) |
| `UNAVAILABLE` | Honest absence — not measured zero |
| `PROVIDER_LIMITED` | Real with integrity `PASS_WITH_LIMITATION` (Prompt 29 handoff) |
| `STALE_REAL` | Real pool data with stale freshness chip (does not block REAL in Prompt 28) |

Period default: `last_28` anchored to Demo `2026-08-12` via `DemoPeriod`.

### 3.1 Overview

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `identity.title` | `REAL` | `ga4_property_metadata` + binding | Property display name or asset name |
| `identity.property_id` | `REAL` | `CoreAssetBinding` → ExternalResource | Bound property only |
| `identity.measurement_id` | `REAL` | `ga4_property_metadata` streams | Primary web stream measurement ID |
| `identity.reporting_timezone` | `REAL` | binding + metadata | Property TZ |
| `identity.status` | `REAL` | binding state | `Connected` when RealBound |
| `identity.relationship_line` | `REAL` | DigitalAsset | Measures · asset name |
| `identity.freshness` | `DEMO` | — | Residual Demo chip text on real path |
| `freshness.ga4` chip | `REAL` | `ga4_property_daily` gate | Freshness/coverage from materialization |
| `glance.users` | `UNAVAILABLE` | — | `totalUsers` not additive across days |
| `glance.sessions` | `REAL` / `PARTIAL_REAL` | `ga4_property_daily` | Sum sessions; partial if coverage gaps |
| `glance.sessions` delta | `REAL_DERIVED` | `FORMULA_PERIOD_RELATIVE_CHANGE` | vs previous period sums |
| `glance.business_actions` | `DEMO` | — | No Business Action mapping store |
| `glance.measurement_state` | `DEMO` | — | Residual Demo summary chip |
| `performance_trend.sessions` | `REAL` / `PARTIAL_REAL` | `ga4_property_daily` | Daily series |
| `performance_trend.business_actions` | `UNAVAILABLE` | — | Not mixed with real Sessions series |
| `acquisition_mix` / channels (overview) | `REAL` / `PARTIAL_REAL` | `ga4_acquisition_channel_daily` | Session default channel group |
| `landing_pulse` | `REAL` / `PARTIAL_REAL` | `ga4_landing_page_daily` | Top landing paths |
| `needs_attention` | `DEMO` | — | No attention engine in Prompt 28 |

### 3.2 Measurement

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `measurement.business_actions` | `DEMO` | — | No mapping store |
| `measurement.events[]` | `REAL` / `PARTIAL_REAL` | `ga4_event_daily` | Event counts; `mapped_action=Unavailable` |
| `measurement.streams[]` | `REAL` / `PARTIAL_REAL` | `ga4_property_metadata` | Data stream list from metadata |
| `measurement.utm_hygiene` | `REAL_DERIVED` | `ga4_campaign_daily` + `ga4_property_daily` | `FORMULA_GA4_UTM_UNAVAILABLE_PCT` |
| `measurement.data_quality` | `DEMO` | — | Residual Demo fixtures |
| `measurement.interruptions` | `DEMO` | — | Residual Demo fixtures |
| `measurement.duplicates` | `DEMO` | — | Residual Demo fixtures |
| `measurement.referrals` | `DEMO` | — | Residual Demo fixtures |
| `measurement.trust_chips` | `DEMO` | — | Residual Demo fixtures |

### 3.3 Acquisition

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `acquisition.channels[]` | `REAL` / `PARTIAL_REAL` | `ga4_acquisition_channel_daily` | `sessionDefaultChannelGroup` — not `firstUser*` |
| `acquisition.channels[].share_pct` | `REAL_DERIVED` | `FORMULA_GA4_CHANNEL_SHARE` | channel sessions / total sessions |
| `acquisition.source_medium[]` | `REAL` / `PARTIAL_REAL` | `ga4_source_medium_daily` | `sessionSource` + `sessionMedium` |
| `acquisition.campaigns[]` | `REAL` / `PARTIAL_REAL` | `ga4_campaign_daily` | `sessionCampaignName` |
| `acquisition.utm_note` | `REAL` | — | Static note; mapped actions unavailable |
| `mapped_actions` columns | `UNAVAILABLE` | — | No Business Action mapping |

### 3.4 Behavior

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `behavior.landing_pages[]` | `REAL` / `PARTIAL_REAL` | `ga4_landing_page_daily` | Path sessions + engaged sessions |
| `landing_pages[].engaged_rate` | `REAL_DERIVED` | `FORMULA_GA4_ENGAGEMENT_RATE` | Per-path sum/sum |
| `behavior.engagement[]` | `REAL_DERIVED` | `ga4_property_daily` sums | Engagement rate, avg time, views/session |
| Engagement rate | `REAL_DERIVED` | `FORMULA_GA4_ENGAGEMENT_RATE` | ΣengagedSessions / Σsessions |
| Avg engagement time | `REAL_DERIVED` | `FORMULA_GA4_AVG_ENGAGEMENT_TIME` | Σduration / ΣactiveUsers (not totalUsers) |
| Views / session | `REAL_DERIVED` | `FORMULA_GA4_VIEWS_PER_SESSION` | Σviews / Σsessions |
| Appointment completion | `UNAVAILABLE` | — | Not mapped business action |
| `behavior.devices[]` | `REAL` / `PARTIAL_REAL` | `ga4_device_daily` | Device category sessions |
| `devices[].share_pct` | `REAL_DERIVED` | `FORMULA_GA4_DEVICE_SHARE` | Device sum/sum share |
| `content_role`, `title`, `mapped_actions` on landing | `DEMO` / empty | — | Not in pool V1 |

### 3.5 Journeys

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `journeys[]` | `UNAVAILABLE` | — | No path reconstruction store in Prompt 28 |

### 3.6 Operations

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `operations.collection_state` | `REAL` | All GA4 datasets + gates | Per-dataset integrity/freshness/coverage |
| `operations.findings[]` | `DEMO` | — | No Evidence/Findings pipeline |
| `operations.recommendations[]` | `DEMO` | — | Residual Demo |
| `operations.tasks[]` | `DEMO` | — | Residual Demo |
| `operations.outcomes[]` | `DEMO` | — | Residual Demo |
| `operations.finding_detail` | `DEMO` | — | Residual Demo |

### 3.7 Relationships (overview embed)

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `relationships.technical_connection` | `REAL` | `CoreAssetBinding` + metadata | Property + measurement binding facts |
| `relationships.narrative` | `DEMO` | — | Residual narrative fixtures |
| `relationships.measures` / `provides_evidence_to` | `DEMO` | — | Residual Demo relationship cards |

### 3.8 Cross-cutting

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `opportunities[]` | `DEMO` | — | No Opportunities entity |
| `business_actions[]` (global) | `DEMO` | — | No mapping store |
| `recent_outcomes[]` | `DEMO` | — | Residual Demo |
| `narrative` | `DEMO` | — | Residual Demo |

### 3.9 Demo catalog asset (`ga4-atlas`)

All fields: `DEMO` · `migration_mode=demo_catalog` · fixtures from `Ga4WorkspaceFixtures`.

## 4. Tab Status Matrix (real-bound default)

Rollup from field provenance (`rollupTabStatus`):

| Tab | Status | Primary reason |
|---|---|---|
| Overview | `PARTIAL` | Real sessions + channels + landing; Demo needs_attention + business_actions; Unavailable users |
| Measurement | `PARTIAL` | Real events/streams/utm; Demo business_actions + quality subs |
| Acquisition | `REAL` or `PARTIAL` | All acquisition datasets pool-backed when gates pass |
| Behavior | `PARTIAL` | Real landing/engagement/devices; Unmapped landing metadata |
| Journeys | `UNAVAILABLE` | No journey paths |
| Operations | `PARTIAL` | Real collection_state; Demo findings/recommendations/tasks/outcomes |

Not connected / error: all tabs `UNAVAILABLE`.

## 5. Dataset → UI Consumer Matrix

| Dataset | Consumers |
|---|---|
| `ga4_property_metadata` | identity (name, streams, measurement_id), measurement.streams, relationships.technical_connection |
| `ga4_property_daily` | glance.sessions, performance_trend.sessions, behavior.engagement (sums), utm_hygiene denominator, freshness.ga4 |
| `ga4_acquisition_channel_daily` | acquisition.channels, acquisition_mix, overview channel scan |
| `ga4_source_medium_daily` | acquisition.source_medium |
| `ga4_campaign_daily` | acquisition.campaigns, measurement.utm_hygiene numerator |
| `ga4_landing_page_daily` | behavior.landing_pages, landing_pulse |
| `ga4_event_daily` | measurement.events |
| `ga4_device_daily` | behavior.devices |
| `DatasetMaterialization` | `operations.collection_state`, gate inputs |
| `DataIntegrityCheckResult` | gate `integrityReady` per dataset |

## 6. Grain / Semantics Matrices

### 6.1 Grain

| Surface | Grain | Notes |
|---|---|---|
| Property daily | property × reporting_date | Property TZ from metadata |
| Acquisition channel | property × date × `sessionDefaultChannelGroup` | Session-scoped |
| Source/medium | property × date × source × medium | Session-scoped |
| Campaign | property × date × `sessionCampaignName` | Session-scoped |
| Landing page | property × date × `landingPage` | |
| Event | property × date × `eventName` | Counts only — no BA mapping |
| Device | property × date × `deviceCategory` | |

### 6.2 Users non-additive

| Metric | Period KPI | Rule |
|---|---|---|
| `totalUsers` | `glance.users` | **Never summed** across days → `UNAVAILABLE` |
| `activeUsers` | engagement avg time denominator | Summed only for `FORMULA_GA4_AVG_ENGAGEMENT_TIME` — not shown as period users KPI |

### 6.3 Acquisition semantics

| Dimension | Used | Forbidden |
|---|---|---|
| Channel group | `sessionDefaultChannelGroup` | `firstUserDefaultChannelGroup` |
| Source/medium | `sessionSource`, `sessionMedium` | `firstUser*` variants |
| Campaign | `sessionCampaignName` | — |

### 6.4 Journeys

No multi-step path store in V1 pool → `journeys=[]`, provenance `UNAVAILABLE`. Demo path cards retired on real path.

### 6.5 Operations

| Surface | Real in Prompt 28 | Still Demo |
|---|---|---|
| Collection/materialization/freshness/integrity/coverage | Yes (`operations.collection_state`) | — |
| Findings / Recommendations / Tasks / Outcomes | — | Yes (fixture lists) |

### 6.6 Demo retirement matrix

| Demo domain | Prompt 28 action |
|---|---|
| Sessions, acquisition, behavior facts | **Retired** on numeric bound assets when gates pass |
| Users period KPI | **Retired** → Unavailable (not wrong sum) |
| Business actions / mapping matrix | **Retained Demo** |
| Needs attention | **Retained Demo** |
| Journeys paths | **Retired** → empty Unavailable |
| Operations findings pipeline | **Retained Demo** |
| Relationships narrative | **Retained Demo** |
| Demo catalog `ga4-atlas` | **Retained** full Demo |

## 7. Reality Matrix Update Notes

Update `docs/implementation/MILESTONE_5_PANEL_FREEZE.md`:

- GA4 specialist UI: `PARTIAL` (real pool presentation for analytics facts; residual Demo for mapping/ops narrative)
- `GA4 specialist real-data UI`: REAL (Prompt 28) — not `NOT YET`
- `GA4/GSC/Ads/Meta real-data UI` row: GA4 portion complete at Prompt 28
- Google connector workspaces: GA4 portion REAL/PARTIAL; GSC/Ads remain Demo UI until Prompt 29/30

## 8. Residual Demo Fields (explicit)

On **real-bound** assets, these UI fields still render Demo fixture content:

1. `glance.business_actions`
2. `glance.measurement_state`
3. `needs_attention[]`
4. `business_actions[]` / `measurement.business_actions[]`
5. `measurement.data_quality`, `interruptions`, `duplicates`, `referrals`, `trust_chips`
6. `operations.findings[]`, `recommendations[]`, `tasks[]`, `outcomes[]`, `finding_detail`
7. `relationships.narrative`, `measures[]`, `provides_evidence_to[]`
8. `opportunities[]`, `recent_outcomes[]`, `narrative`
9. `identity.freshness` label (Demo-shaped text; GA4 freshness chip is real)

## 9. Definition of Done

- [x] `Ga4SpecialistReadService` builds frozen workspace shape from pool + formulas for `RealBound`
- [x] `Ga4SpecialistBindingResolver` uses only active `CoreAssetBinding`; no arbitrary property
- [x] `Ga4UiDatasetGate` blocks unverified/blocked integrity from `REAL` presentation
- [x] `Ga4PoolReadRepository` is sole `ga4_*` read path; session acquisition; no `totalUsers` period sum
- [x] `Ga4FormulaCalculator` implements `FORMULA_GA4_*` via registry; sum/sum rates
- [x] `AnalyticsPage` uses read service; no Http on render; no Demo fallback on exception
- [x] `DataSourceState` taxonomy includes shared Prompt 28/29 labels
- [x] Demo catalog asset unchanged (`DEMO` provenance)
- [x] `allowedTabs` frozen list unchanged
- [x] No Evidence/Findings/Opportunities created
- [x] `tests/Feature/Ga4/Ga4RealDataMigrationTest.php` green
- [x] `Ga4OperatingWorkspaceTest` + `Ga4WorkspaceFixturesTest` green
- [x] `vendor/bin/pint --dirty` clean
- [x] This document + Milestone 5 matrix updated

## 10. Prompt 29 Handoff

- Map `PROVIDER_LIMITED` / `STALE_REAL` at field level when integrity returns `READY_WITH_PROVIDER_LIMITATION` or freshness is stale-but-presented
- Business Action mapping store → migrate `business_actions` surfaces from Demo
- Journeys path reconstruction or honest permanent Unavailable
- Operations findings when Evidence pipeline exists
