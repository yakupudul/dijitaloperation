# Demo Isolation Contract

Prompt 67 — how Explicit Demo Runtime stays quarantined from production MoxDOP truth.

**Companions:** `CAPABILITY_REALITY_CONTRACT.md`, `DEMO_REALITY_FINAL_CONVERGENCE.md`, `PRODUCTION_DEMO_REMOVAL_AUDIT.md`.

---

## 1. Purpose

Atlas Demo Mode is a product teaching and UX rehearsal surface. It must never contaminate production Customer / Brand / DigitalAsset analytics, operations queues, or integration status.

## 2. Explicit Demo definition

**Explicit Demo Runtime** means the operator is intentionally viewing Atlas catalog entities:

| Signal | Example |
| --- | --- |
| Catalog customer/brand ids | `atlas-health`, `atlas-dental` |
| Catalog asset ids (non-numeric strings) | `ga4-atlas`, `gsc-atlas`, `meta-atlas`, `web-atlas`, `gbp-atlas`, … |
| Session DemoState | Filters, flashes, Demo Mode reset for Atlas catalog |
| Livewire namespace history | `App\Livewire\Demo\**` hosts both production `/app` routes and Demo catalog — runtime must branch on asset id / data source, not namespace name alone |

Namespace `Demo` is **historical**. Path `/app` is the frozen operator UI. Filament technical admin is `/system` (panel id `app`). Naming debt must not be treated as “everything under Demo is fake.”

## 3. Fixture types (allowed)

| Type | Location | Allowed use |
| --- | --- | --- |
| **Catalog fixtures** | `app/Support/Demo/*WorkspaceFixtures.php`, `DemoCatalog`, `ClientValueFixtures`, etc. | Only when `DemoCatalogAssetGuard::isDemoCatalogAssetId` (or equivalent binding mode `DemoCatalog`) |
| **Session DemoState** | `App\Support\Demo\DemoState` | Atlas demo catalog UX; ephemeral filters/flash on `/app` where still used for chrome — never as Finding/Opportunity source of truth |
| **Test factories** | `database/factories`, model factories | PHPUnit only |
| **Evaluation fixtures** | Prompt 55 intelligence evaluation | Offline eval harness — not operator production UI |
| **Performance fixtures** | Prompt 65 `App\Support\Performance\*` | Benchmark harness — not operator production UI |
| **Seeders** | `DatabaseSeeder` | Roles, permissions, module registry, curated Playbooks — **no fake Customer** |

## 4. Contamination boundaries

| Boundary | Production rule |
| --- | --- |
| Asset id | Numeric `DigitalAsset` primary keys → production/unavailable paths only |
| Specialist read services | `migration_mode=real` must not retain Demo provenance rows in residual domains |
| Operations indexes | Findings / Opportunities / Recommendations / Tasks read canonical Eloquent services |
| Dashboard | No Atlas ClientValueFixtures narrative injection (`recentValue` empty unless real projection wired) |
| Integrations hub | Non-Google/Meta cards: truthful `not_connected` / `configured` — never fake Connected/last_check |
| Agency execution widgets | `awaiting_decision` from `RecommendationReadService`; no Demo `system_exceptions` / `recent_outcomes` |
| Website / GBP / Instagram numeric assets | `UnavailableWorkspaceShells` (or real observations when exists) — no workspace fixtures |
| Meta campaign/detail | Production asset ids gated; Demo catalog ids may use fixtures |
| Database seed | Must not create Atlas-like Customers for “demo completeness” |

## 5. Production prohibition

Production code paths **must not**:

1. Fall back to `*WorkspaceFixtures` on exception or empty pool.
2. Merge Demo and Real series in one KPI/chart.
3. Leave Demo `needs_attention`, opportunities, operations findings, business_actions, search clusters, or sibling freshness chips on real-bound workspaces.
4. Show invented health scores, local rank grids, or sample Instagram metrics for production assets.
5. Claim Interactive Assistant chat as live runtime (architecture-first only — UNAVAILABLE).

## 6. Safe retention rationale

Retaining `app/Livewire/Demo/**` + `app/Support/Demo/**` is safe **if and only if**:

- Catalog string ids are the sole entry to full fixture workspaces.
- Production numeric ids hit real read services or unavailable shells.
- PHPUnit asserts both sides (`DemoRealityFinalConvergenceTest` and specialist tests).
- Operators can distinguish Demo Mode labeling when browsing Atlas catalog.

## 7. Detection helpers

- `App\Support\Reality\DemoCatalogAssetGuard`
- Specialist binding modes (`Ga4BindingMode::DemoCatalog`, etc.)
- `UnavailableWorkspaceShells` for unbacked production specialists

## 8. Violation response

Any production path that serves Atlas narrative as Customer truth is a **P0 reality defect**: remove fallback, add regression test, update Final Matrix + Removal Audit.
