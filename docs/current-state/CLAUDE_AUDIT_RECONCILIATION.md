# Claude audit reconciliation (Track A)

> Runtime evidence against issue #211 / the 21 Aug 2026 Claude diagnosis.  
> Baseline SHA this branch started from: `d888a618035c16a759b16d88bbbe005df069f68b` (PR #210 head; strict descendant of `7722144af2646f70d449e832208005a14c708656`).  
> This document follows code. It is not a completeness claim by itself.

Classification: `DONE` / `PARTIAL` / `MISSING` / `OBSOLETE`.

## A0 Reconciliation + cleanup

| Item | Status | Evidence |
| --- | --- | --- |
| Operator UI boundary: root Livewire/TailAdmin; `/admin` technical; legacy `/app/*` `/system/*` 410 | **DONE** | `routes/demo.php`, `AppPanelProvider` path `admin`, `LegacyRetiredPrefixController`, `tests/Feature/PublicCanonicalUrlArchitectureTest.php` |
| Production operator screens do not read DemoState/fixtures as KPI truth | **DONE** | Canonical GSC/GA4 routes `whereNumber('assetId')`. `GscSpecialistBindingResolver` / `Ga4SpecialistBindingResolver` reject non-numeric and DemoCatalog ids as not-connected (unavailable, not fixture KPIs). Tests: `tests/Feature/TrackA/ProductionOperatorDemoIsolationTest.php`, `tests/Feature/PublicCanonicalUrlArchitectureTest.php`. `DemoState` remains period/flash chrome — not Finding/KPI truth. `GscWorkspaceFixtures` / `Ga4WorkspaceFixtures` stay test-only helpers. |
| Retire `core_connections*` | **OBSOLETE / not retired** | Runtime still depends on `CoreConnection` for PageSpeed/WordPress/probe services (`Ga4ConnectionProbeService`, `PageSpeedConnectionProbeService`, Filament relation managers). Proof: `tests/Feature/TrackA/DeferredModulesRuntimeTest.php`. Canonical collection uses `core_integrations` / `core_external_resources` / `core_asset_bindings` |
| Remove `moxdop-final-interface-*.zip` | **DONE** | File removed from git; `.gitignore` ignores future copies |
| Remove markdown-only product spec tests | **DONE** | `*ProductSpecTest` classes now assert runtime behavior (API version, deferred modules, gated GBP) |
| Stale `docs/current-state/*` | **OBSOLETE** | Files remain bannered historical snapshots; this file is the Track A runtime map |
| Deferred modules stay disabled | **DONE** | GBP `GOOGLE_GBP_DISCOVERY_ENABLED` default false; Instagram has no `app-modules/instagram` package and renders `outside_scope` |
| Google Ads API version | **DONE** | Runtime default `v25` via `config/moxdop.php` + `GoogleOAuthConfig::adsApiVersion()`. Test: `tests/Feature/TrackA/GoogleAdsApiVersionRuntimeTest.php` |

## A1 GSC + GA4 typed facts

| Item | Status | Evidence |
| --- | --- | --- |
| Reuse PostgreSQL Data Pool; no second metrics architecture | **DONE** | Extended `gsc_*` / `ga4_*` tables + `PostgresWarehouseWriter`. No parallel metrics store |
| GSC date + query + page + query×page + device/country, natural keys, upsert | **DONE** | Existing warehouse tables + `SearchConsoleDatasetExecutor`. Unchanged grain |
| GA4 channel / source-medium / device / landing + sessions/users/new users/engagement/key events/conversions/revenue where API supports | **PARTIAL → extended** | Existing grains reused. `ga4_property_daily` now has `newUsers`, `conversions`, `keyEvents`, `totalRevenue` (optional metrics dropped when property metadata lacks them). Unique users remain non-additive (`glance.users` UNAVAILABLE). Migration `2026_08_21_095920_add_ga4_property_daily_commerce_metrics.php` |
| 16-month historical collection | **DONE** (code) | GSC/GA4 `HISTORICAL_INCREMENTAL` history tokens in freshness/storage/registry contracts are `provider_16m_available` (486 days). Meta/Ads 180d windows were not changed. `HistoricalRangeResolverTest` |
| Full pagination, not top-25 | **DONE** (production path) | Production executors paginate. Legacy `SearchConsoleBoundCollector` / `Ga4BoundCollector` still top-25 Evidence-only and are not the specialist UI read path |
| Incremental restatement of recent days | **DONE** | Existing 7-day reprocess window + natural-key upsert |
| Evidence vs facts | **DONE** | DatasetExecutors write warehouse facts; Evidence remains run provenance / canonical pipeline |
| Screens read persisted facts | **DONE** | `GscSpecialistReadService` / `Ga4SpecialistReadService` / `PeriodAwareWebsiteWorkspace` read pool on `RealBound` |
| Period presets + custom + previous + YoY; missing ≠ zero | **DONE** | `OperatorReportingPeriod::comparisonQueryBounds`, period bar Previous/YoY, picker min 486 days for real bindings. Demo catalog stays on 90-day fixtures |
| Closed-period provider reconciliation command/tests | **DONE** (harness) | `php artisan moxdop:reconcile-provider-period {SEARCH_CONSOLE\|GA4} --asset= --from= --to= --json`. PHPUnit with `Http::fake`. Live UI ±1% is **EXTERNAL UAT REQUIRED** |

## A2 PR #210 P2s

| Item | Status | Evidence |
| --- | --- | --- |
| Relative links vs fetched final URL | **DONE** | Inherited from `d888a61` — `PublicUrlNormalizer::resolve`, `WebsiteProductionCollectorTest` |
| Aggregate crawl byte ceiling across resume | **DONE** | Inherited from `d888a61` — `bytes_downloaded_total` checkpoint, `MAX_TOTAL_BYTES` |
| Dashboard greeting/date via OperatorClock | **DONE** | Inherited from `d888a61` — `OperatorExecutionReadServiceTest` |
| Prior RC P1/P2 guarantees | **DONE** | Not reverted; warehouse idempotency, completed-run gating, SMTP reload, reset mail, Data Sources RBAC remain |

## A3 First deploy gate (code-level)

See the pull request body for exact SHA and command results.

| Item | Status |
| --- | --- |
| PHPUnit / Pint / `npm ci && npm run build` / `route:cache` / unique migrations / PostgreSQL tests | **DONE** (code-level) on `82f912e6bb190daebd2025ed0028adbe414a15f5`. PHPUnit 2078 tests / 2071 passed / 7 skipped; Pint green; Vite build green; `route:cache` green; disposable SQLite migrate green including `2026_08_21_095920_add_ga4_property_daily_commerce_metrics`; `@group postgres` 6 passed / 857 assertions |
| Unresolved P1/P2 review / exact-head Codex review | Requested after code-level green |
| Real GSC/GA4 closed-period ±1% vs provider UI | **EXTERNAL UAT REQUIRED** — command above; operator paths `/assets/search-console/{id}` and `/assets/analytics/{id}` |
| Production path Customer → Brand → Digital Asset → Data Sources → Collect → Activity → facts → Evidence/Finding → Recommendation → manual Task | Code path exists (Phase E). Live provider collect UAT is external |

## External UAT commands (no secrets)

Closed July 2026 example after a real GSC/GA4 collect on a bound asset:

```bash
php artisan moxdop:reconcile-provider-period SEARCH_CONSOLE --asset=<id> --from=2026-07-01 --to=2026-07-31 --json
php artisan moxdop:reconcile-provider-period GA4 --asset=<id> --from=2026-07-01 --to=2026-07-31 --json
```

Compare additive metrics to Search Console / GA4 UI for the same closed dates. Document metric-definition differences (GSC position blending; GA4 unique users; sampling/thresholding; property retention truncating 16-month GA4 history) instead of hiding them as zero.

## Intentionally not done on Track A

Tracks B–F (SEO crawl depth, Google Ads 13-month facts, Meta breakdowns, AI reports, AEO/GEO). `core_connections` retirement. Renaming `App\Livewire\Demo` namespace.
