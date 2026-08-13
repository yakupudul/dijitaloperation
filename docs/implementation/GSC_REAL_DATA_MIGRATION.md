# GSC Real Data Migration (Prompt 29)

## 1. Purpose

Prompt 29 migrates the frozen Search Console Organic Demand specialist workspace (`SearchConsolePage`, path `/app/assets/search-console/{assetId}`) from deterministic Demo fixtures to **read-only presentation** of the normalized GSC data pool populated by Prompt 17 (production collector) and gated by Prompt 26 (integrity) + Prompt 27 (freshness/materialization).

Hard rules enforced in this migration:

- **No live Search Console / URL Inspection / Sitemap / OAuth API call on page render** — all numbers come from local `gsc_*` tables + `CoreAssetBinding` + `DatasetMaterialization` + integrity audit results.
- **No Demo fallback on query exceptions** — errors yield an `UNAVAILABLE` operational workspace, never fixture substitution.
- **No Demo+Real mixing inside a single KPI or chart series**.
- **Property KPI totals from `gsc_property_daily` ONLY** — never sum query/page rows for property-level clicks/impressions/CTR/position.
- **CTR = sum(clicks)/sum(impressions)** via `FORMULA_GSC_CTR` — never average of row CTRs.
- **Position = impression-weighted** via `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` — never blind average; metadata stores `provider_average_position` per day row.
- **Average position is NOT exact SERP rank** — provenance note on read model.
- **Impressions ≠ search volume** — honest labeling retained on demand + glance.
- **Query row count ≠ total keyword universe** — `PROVIDER_LIMITED` semantics on real queries.
- **Indexing site-wide totals → UNAVAILABLE** when real-bound (not Demo numbers).
- **Sitemap submitted ≠ indexed** — deprecated `contents[].indexed` never used.
- **URL Inspection is selective sample only** — never extrapolated; `userCanonical ≠ googleCanonical`.
- **No Evidence / Findings / Opportunities / Business Outcomes created** by this migration.
- **Demo catalog asset** (`gsc-atlas`) remains 100% Demo fixtures with explicit `DEMO` provenance.

## 2. Architecture Overview

```
SearchConsolePage (Livewire)
    └── GscSpecialistReadService::workspace(assetId, preset, start, end)
            ├── GscSpecialistBindingResolver → GscBindingContext (DemoCatalog | RealBound | NotConnected | ActionRequired)
            ├── GscUiDatasetGate → GscDatasetReadiness per dataset (integrity + coverage + freshness)
            ├── GscPoolReadRepository → bounded SQL over gsc_* only
            └── GscFormulaCalculator → MOXDOP_FORMULA_REGISTRY_V1 (FORMULA_GSC_*)
```

| Component | Role |
|---|---|
| `DataSourceState` | Per-field provenance label |
| `GscSpecialistBindingResolver` | Only human-confirmed active `CoreAssetBinding` (`capability=search_console`); `external_id` = siteUrl |
| `GscUiDatasetGate` | Rows-in-table ≠ ready; requires materialization + integrity `READY_FOR_REAL_UI` + proven coverage dates |
| `GscPoolReadRepository` | Single sanctioned read path to `gsc_*`; property identity = `site_url` |
| `GscFormulaCalculator` | CTR, impression-weighted position, period deltas via sum/sum — never avg of daily rates |

`refreshData()` on `SearchConsolePage` delegates to `StartIncrementalCollectionService` with `['SEARCH_CONSOLE']` when `RealBound`; Demo catalog flashes Demo-only notice.

Reporting timezone semantics: **`America/Los_Angeles`** (`gsc_reporting_date_semantics`).

## 3. Field Migration Matrix

Classification key:

| State | Meaning |
|---|---|
| `REAL` | Direct pool read, full coverage |
| `REAL_DERIVED` | Formula on summed pool facts |
| `PARTIAL_REAL` | Pool read with partial proven coverage in range |
| `DEMO` | Residual Demo fixture (explicitly documented §8) |
| `UNAVAILABLE` | Honest absence — not measured zero |
| `PROVIDER_LIMITED` | Real with row/top-N/API completeness limits |
| `STALE_REAL` | Real pool data with stale freshness chip (does not block REAL in Prompt 29) |

Period default: `last_28` anchored to Demo `2026-08-12` via `DemoPeriod`.

### 3.1 Overview

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `identity.title` | `REAL` | binding + DigitalAsset | Asset / brand name |
| `identity.property_label` | `REAL` | `CoreAssetBinding` → ExternalResource `external_id` | siteUrl only — not property_id |
| `identity.reporting_timezone` | `REAL` | binding + `America/Los_Angeles` | GSC PT date semantics |
| `identity.status` | `REAL` | binding state | `Connected` when RealBound |
| `freshness.gsc` chip | `REAL` | `gsc_property_daily` gate | Freshness/coverage from materialization |
| `glance.clicks` | `REAL` / `PARTIAL_REAL` | `gsc_property_daily` | Sum clicks — property daily ONLY |
| `glance.impressions` | `REAL` / `PARTIAL_REAL` | `gsc_property_daily` | Sum impressions — not search volume |
| `glance.ctr` | `REAL_DERIVED` | `FORMULA_GSC_CTR` | sum(clicks)/sum(impressions) |
| `glance.clicks.avg_position` | `REAL_DERIVED` | `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | Weighted by impressions across days |
| `glance.search_attention` | `DEMO` | — | No attention engine |
| `performance_trend.clicks` | `REAL` / `PARTIAL_REAL` | `gsc_property_daily` | Daily series |
| `performance_trend.impressions` | `REAL` / `PARTIAL_REAL` | `gsc_property_daily` | Daily series |
| `metric_series.ctr` | `REAL_DERIVED` | per-day `FORMULA_GSC_CTR` | Never mixed with Demo zeros |
| `metric_series.position` | `REAL` | metadata `provider_average_position` | Not exact rank — note on chart path |
| `search_momentum` | `DEMO` | — | Heuristic cluster momentum — no store |
| `page_pulse` | `REAL` / `PARTIAL_REAL` / `UNAVAILABLE` | `gsc_page_daily` | Reuses real `pages.directory` for frozen overview cards |
| `discoverability` funnel | `UNAVAILABLE` | — | Site-wide index totals unavailable from API |
| `needs_attention` | `DEMO` | — | No attention engine |

### 3.2 Performance

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `performance.devices[]` | `REAL` / `PARTIAL_REAL` | `gsc_device_daily` | clicks/impr + derived CTR/position |
| `performance.countries[]` | `REAL` / `PARTIAL_REAL` | `gsc_country_daily` | Top countries by clicks |
| `performance.brand_nonbrand` | `DEMO` | — | No canonical brand rule store |
| `performance.diagnosis` | `DEMO` | — | Derived interpretation — no production engine |

### 3.3 Demand

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `demand.queries[]` | `PROVIDER_LIMITED` | `gsc_query_daily` | Top-N provider-limited rows |
| `demand.queries[].ctr` | `REAL_DERIVED` | `FORMULA_GSC_CTR` | Per-query sum/sum |
| `demand.queries[].position` | `REAL_DERIVED` | `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | Per-query weighted |
| `demand.observed_query_note` | `REAL` (static honesty) | — | Not exhaustive keyword universe |
| `demand.clusters[]` | `DEMO` | — | No cluster store |
| `demand.momentum` | `DEMO` | — | Heuristic — no store |
| `demand.ownership_reviews` | `DEMO` | — | No ownership engine |

### 3.4 Pages

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `pages.directory[].clicks` | `REAL` / `PARTIAL_REAL` | `gsc_page_daily` | Aggregated page rows |
| `pages.directory[].impressions` | `REAL` / `PARTIAL_REAL` | `gsc_page_daily` | Not search volume |
| `pages.directory[].ga4_context` | `UNAVAILABLE` | — | Not query-attributed on real path |
| `content_role`, `title`, `offering` | empty / `DEMO` | — | Not in pool V1 |

### 3.5 Indexing

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `indexing.coverage` site-wide totals | `UNAVAILABLE` | — | No full Page Indexing report API |
| `indexing.urls[]` | `PROVIDER_LIMITED` | `gsc_url_inspection_snapshot` | Controlled sample only |
| `indexing.urls[].user_canonical` | `REAL` | inspection metadata | Distinct from google_canonical |
| `indexing.urls[].google_canonical` | `REAL` | inspection metadata | Distinct from user_canonical |
| `indexing.sitemaps[]` | `REAL` / `PARTIAL_REAL` | `gsc_sitemap_snapshot` | Submitted metadata — not indexed count |
| `indexing.reconciliation.index_observed` | `UNAVAILABLE` | — | Site-wide totals unavailable |
| `indexing.discoverability_by_role` | `DEMO` | — | Residual Demo |

### 3.6 Operations

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `operations.collection_state` | `REAL` | All GSC datasets + gates | Per-dataset integrity/freshness/coverage |
| `operations.findings[]` | `DEMO` | — | No Evidence/Findings pipeline |
| `operations.recommendations[]` | `DEMO` | — | Residual Demo |
| `operations.tasks[]` | `DEMO` | — | Residual Demo |
| `operations.outcomes[]` | `DEMO` | — | Residual Demo |

### 3.7 Relationships (overview embed)

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `relationships.technical_connection` | `REAL` | `CoreAssetBinding` | siteUrl binding facts |
| `relationships.observes` / `provides_evidence_to` | `DEMO` | — | Residual narrative cards |

### 3.8 Cross-cutting

| UI field | State (real-bound) | Dataset / Formula | Reason |
|---|---|---|---|
| `opportunities[]` | `DEMO` | — | No Opportunities entity |
| `recent_outcomes[]` | `DEMO` | — | Residual Demo |
| `narrative` | `DEMO` | — | Residual Demo |

### 3.9 Demo catalog asset (`gsc-atlas`)

All fields: `DEMO` · `migration_mode=demo_catalog` · fixtures from `GscWorkspaceFixtures`.

## 4. Tab Status Matrix (real-bound default)

Rollup from field provenance (`rollupTabStatus`):

| Tab | Status | Primary reason |
|---|---|---|
| Overview | `PARTIAL` | Real glance + trend + page_pulse (from pages); Demo search_momentum/needs_attention; Unavailable discoverability |
| Performance | `PARTIAL` | Real devices/countries/trend; Demo brand/nonbrand + diagnosis |
| Demand | `PARTIAL` | Provider-limited queries; Demo clusters/momentum/ownership |
| Pages | `REAL` or `PARTIAL` | Page clicks/impressions from pool when gate passes |
| Indexing | `PARTIAL` | Real sitemaps + inspection sample; Unavailable site-wide coverage/reconciliation |
| Operations | `PARTIAL` | Real collection_state; Demo findings/recommendations/tasks/outcomes |

Not connected / error: all tabs `UNAVAILABLE`.

## 5. Dataset → UI Consumer Matrix

| Dataset | Consumers |
|---|---|
| `gsc_property_daily` | glance (clicks/impr/ctr/position), performance_trend, metric_series, freshness.gsc |
| `gsc_query_daily` | demand.queries (top N, PROVIDER_LIMITED) |
| `gsc_page_daily` | pages.directory, page_pulse override on real path uses directory |
| `gsc_device_daily` | performance.devices |
| `gsc_country_daily` | performance.countries |
| `gsc_sitemap_snapshot` | indexing.sitemaps |
| `gsc_url_inspection_snapshot` | indexing.urls (sample) |
| `DatasetMaterialization` | `operations.collection_state`, gate inputs |
| `DataIntegrityCheckResult` | gate `integrityReady` per dataset |

## 6. Grain / Semantics Matrices

### 6.1 Grain

| Surface | Grain | Notes |
|---|---|---|
| Property daily | site_url × reporting_date | PT reporting dates |
| Query daily | site_url × date × query | TOP_ROWS_LIMITED |
| Page daily | site_url × date × page | |
| Device daily | site_url × date × device | |
| Country daily | site_url × date × country | |
| Sitemap snapshot | site_url × sitemap_path × retrieved_at | Current-state |
| URL inspection snapshot | site_url × page × inspected_at | Controlled sample |

### 6.2 Property totals rule

| Metric | Source | Forbidden |
|---|---|---|
| Property clicks/impressions/CTR/position | `gsc_property_daily` sums + formulas | Summing `gsc_query_daily` or `gsc_page_daily` for property KPIs |

### 6.3 CTR / position formulas

| Formula | Definition | Forbidden |
|---|---|---|
| `FORMULA_GSC_CTR` | sum(clicks)/sum(impressions) | avg(row CTR) |
| `FORMULA_GSC_IMPRESSION_WEIGHTED_POSITION` | sum(position×impressions)/sum(impressions) | avg(daily position) without weights |
| `FORMULA_PERIOD_RELATIVE_CHANGE` | (current−previous)/previous | Infinity% when previous=0 |
| `FORMULA_PERIOD_ABSOLUTE_DELTA` | current−previous | — |

Position metadata: `provider_average_position` per row — **not exact SERP rank**.

### 6.4 Indexing semantics

| Surface | Real in Prompt 29 | Unavailable / Demo |
|---|---|---|
| Site-wide Indexed/Not indexed/Excluded totals | — | **UNAVAILABLE** |
| URL inspection table | Sample rows only | Never extrapolated |
| Sitemaps submitted counts | Metadata snapshot | submitted ≠ indexed |
| Reconciliation `index_observed` site-wide | — | **UNAVAILABLE** |

### 6.5 Operations

| Surface | Real in Prompt 29 | Still Demo |
|---|---|---|
| Collection/materialization/freshness/integrity/coverage | Yes | — |
| Findings / Recommendations / Tasks / Outcomes | — | Yes |

### 6.6 Demo retirement matrix

| Demo domain | Prompt 29 action |
|---|---|
| Property glance + performance trend + metric series | **Retired** on numeric bound assets when gates pass |
| Devices + countries + top queries + pages + page_pulse | **Retired** when gates pass |
| Sitemaps + inspection sample metadata | **Retired** when snapshot gates pass |
| Site-wide indexing coverage + reconciliation index_observed | **Retired** → Unavailable (not Demo substitution) |
| Clusters, momentum, brand/nonbrand, diagnosis, attention, ops findings, opportunities, discoverability funnel | **Retained Demo** or **Unavailable** |

## 7. Provider verification (2026-08-13)

Verified against:

- [Search Analytics data completeness](https://developers.google.com/webmaster-tools/v1/how-tos/all-your-data) — 2–3 day lag, row limits, top-N completeness (`PROVIDER_LIMITED`).
- [Search Console date/time reporting](https://support.google.com/webmasters/answer/96568) — Pacific Time date boundaries (`America/Los_Angeles`).
- `SEARCH_CONSOLE_DATA_CONTRACT_V1` + Prompt 17 GSC collector docs — URL Inspection selective sample; Sitemaps submitted ≠ indexed; deprecated `contents[].indexed` ignored.

**Verdict:** No Data Contract mismatch found that blocks migration. Unsupported Indexing site-wide totals remain `UNAVAILABLE` per contract.

## 8. Residual Demo / Unavailable summary (real-bound)

**REAL / REAL_DERIVED / PARTIAL_REAL / PROVIDER_LIMITED when gates pass:**

- identity, freshness.gsc, glance clicks/impressions/ctr/position, performance trend + metric series, devices, countries, demand.queries (top N), pages clicks/impressions, page_pulse (same as pages.directory), sitemaps metadata, inspection sample rows, operations.collection_state, relationships.technical_connection

**DEMO (explicit residual):**

- search_momentum, needs_attention, demand.clusters/momentum/ownership, performance.brand_nonbrand, performance.diagnosis, opportunities, recent_outcomes, narrative, operations findings/recommendations/tasks/outcomes, relationships narrative cards, indexing.discoverability_by_role, glance.search_attention

**UNAVAILABLE (honest absence):**

- indexing.coverage site-wide totals, indexing.reconciliation site-wide/index_observed, discoverability funnel site-wide, pages ga4_context on real path, not-connected/error operational workspace fields

## 9. Tests

`tests/Feature/Gsc/GscRealDataMigrationTest.php` — demo catalog, binding resolver, property totals isolation, CTR/position formulas, impressions labeling, PROVIDER_LIMITED queries, indexing UNAVAILABLE, sitemap/inspection semantics, exception path, zero HTTP on render, zero Evidence writes, frozen tabs.

Regression: `GscOperatingWorkspaceTest`, `GscWorkspaceFixturesTest`.
