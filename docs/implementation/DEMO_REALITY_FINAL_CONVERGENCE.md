# DEMO → REALITY FINAL CONVERGENCE

Prompt 67 — close production Demo fallbacks, quarantine Explicit Demo Runtime, and publish an authoritative capability reality matrix.

**Status vocabulary:** REAL · PARTIAL · DEMO · UNAVAILABLE (see `docs/architecture/CAPABILITY_REALITY_CONTRACT.md`).

**Base:** Prompt 66 HEAD `526fddb` (observability schedule note on Prompt 66 stack).  
**Branch:** `cursor/demo-reality-final-convergence-ea01`.

---

## 1. Purpose

Make MoxDOP operator surfaces tell the truth: production Digital Assets and Operations queues must not silently render Atlas Demo fixtures. Explicit Demo Mode remains available for catalog string asset ids only.

## 2. Scope

In scope: `/app` operator Livewire surfaces, specialist read services (GA4/GSC/Google Ads/Meta), Integrations hub cards, dashboard/agency execution widgets, Website/GBP/Instagram production shells, Findings index read path, seeder boundary, reality contracts + matrix docs, PHPUnit regression.

Out of scope: inventing new specialist analytics, Interactive Assistant chat runtime, inventing health scores, full GBP local-rank productionization, live OAuth/SMTP manual PASS claims, changing frozen sidebar IA.

## 3. Authority Model

MASTER_SPEC → accepted ADR → product blueprints → this Prompt 67 doc set → older “IMPLEMENTED V1” labels. Reality Matrix statuses override marketing language.

## 4. Hard Product Rules

1. No production Demo fallback on error or empty data.
2. Demo and Real values never mix in one KPI/series.
3. Missing ≠ zero; unavailable ≠ Demo.
4. Explicit Demo only via Atlas catalog ids / DemoState catalog session.
5. No invented health scores or fake local ranks.
6. Frozen `/app` sidebar unchanged.
7. Harici write action yok (read-only external integrations).
8. Manual live provider verification is NOT claimed in Prompt 67.

## 5. Base HEAD and Branch

| Field | Value |
| --- | --- |
| Prompt 66 HEAD | `526fddb` |
| Working branch | `cursor/demo-reality-final-convergence-ea01` |
| Prompt | 67 — Demo → Reality Final Convergence |

## 6. Prompt 66 Boundary / Input Audit

Prompt 66 delivered observability/alerting (durable alerts, heartbeats, provider counters, health snapshot without score). Prompt 67 consumes that honesty culture for product UI truth: do not paper over gaps with Demo.

## 7. Reality Status Taxonomy

REAL / PARTIAL / DEMO / UNAVAILABLE — definitions in `CAPABILITY_REALITY_CONTRACT.md`. No percentages.

## 8. Evidence Requirements

Canonical source + read/write paths + tests + no Demo fallback. Live OAuth/SMTP/paid APIs: `NOT_MANUALLY_VERIFIED` unless recorded PASS (none in Prompt 67).

## 9. Product vs Runtime vs Deployment

Product IA can be frozen while runtime returns REAL, DEMO, or UNAVAILABLE per asset id. Deployment credentials are separate from Reality status.

## 10. Demo Isolation Principle

`DEMO_ISOLATION_CONTRACT.md`: catalog string ids → fixtures; numeric production ids → real or unavailable shells.

## 11. No Production Demo Fallback Rule

Specialist `buildRealWorkspace` must clear residual Demo domains to empty/UNAVAILABLE. Hub cards must not fake Connected. Dashboard must not inject Atlas value narratives.

## 12. Frozen Surface Audit

Frozen sidebar and specialist IA remain. Convergence changes data provenance behind existing routes — not navigation redesign (`MILESTONE_5_PANEL_FREEZE.md`).

## 13. Namespace Naming Debt

`App\Livewire\Demo\**` names the historical Demo-era Livewire tree that now hosts production operator UI at the site root. Do not infer “all Demo namespace = fake data.”

## 14. Filament `/admin` Panel Boundary

Filament panel id `app`, path `/admin` — technical/admin resources (Customers, Findings, Tasks, Runs, Modules, …). Operator product UI is Livewire at the site root (routes in `routes/demo.php` + `routes/web.php` + middleware). Legacy `/app` and `/system` return HTTP 410.

## 15. Explicit Demo Runtime (Atlas)

`DemoCatalog` portfolio: Atlas Health / Atlas Dental with string asset ids (`ga4-atlas`, `gsc-atlas`, `meta-atlas`, `web-atlas`, `gbp-atlas`, …). Full workspace fixtures allowed only here.

## 16. DemoCatalog Asset Id Contract

`DemoCatalogAssetGuard::isDemoCatalogAssetId` — non-numeric ids present in DemoCatalog. Numeric ids are production (or unknown → not catalog).

## 17. DemoState Session Scope

Session store for Demo Mode filters/flash/reset and Atlas catalog interaction. Must not be the Findings/Opportunity source of truth for production indexes.

## 18. Fixture Taxonomy

Catalog workspace fixtures, session DemoState, PHPUnit factories, Prompt 55 evaluation fixtures, Prompt 65 performance fixtures — see Isolation Contract.

## 19. Test / Evaluation / Performance Fixtures Retention

Intentionally retained. Not operator production dependencies.

## 20. DatabaseSeeder Boundary

`DatabaseSeeder` seeds roles/permissions, module registry, curated Playbooks only — **no fake Customer**.

## 21. Auth / Users / Roles Reality

REAL — Laravel auth + Spatie roles/permissions; admin creation via `dop:create-admin`.

## 22. Customer / Brand / Asset CRUD Reality

REAL — Filament `/system` + migrated `/app` portfolio create/index flows for durable Eloquent models.

## 23. Findings Persistence Reality

REAL — `findings` table, fingerprint uniqueness, `FindingReadService`, Filament Finding resources, rule-backed production Findings (prior prompts).

## 24. FindingsIndex → FindingReadService Convergence

REMOVED DemoState-backed Finding lists. `/app` Operations FindingsIndex reads `FindingReadService` only; empty state when none.

## 25. Opportunities Reality

REAL (Operations index DB-backed per prior convergence). Specialist residual opportunity cards on real workspaces cleared → UNAVAILABLE/empty (Prompt 67).

## 26. Recommendations Reality

REAL — canonical recommendations + `RecommendationReadService`; dashboard awaiting_decision uses this service.

## 27. Work / Tasks Reality

REAL — Work/Task domain persistence and `/app` Operations task surfaces (prior prompts).

## 28. Business Outcomes Reality

REAL — definitions/observations/aggregates persistence + read services (Prompt 57 lineage).

## 29. Report Snapshots Reality

REAL — snapshot persistence/schema contracts (Prompt 59–60 lineage). PDF/share delivery depends on deployment config (mail/storage) → note as deployment PARTIAL where applicable.

## 30. Client Value Story Reality

PARTIAL/REAL for Brand Value projections when wired; **dashboard `recentValue` cleared to `[]`** — no ClientValueFixtures Atlas narrative on production home (Prompt 67).

## 31. Agency Execution Dashboard Convergence

`AgencyExecutionFixtures` awaiting_decision from `RecommendationReadService`; `system_exceptions` and `recent_outcomes` cleared of Demo fixture rows.

## 32. Integrations Hub Truthfulness

`OperatorIntegrationsHubQuery`: Google/Meta retain real connection read models; other providers use `truthfulProviderCard` → not_connected/configured with `last_check = —` and `provenance = real` (honest absence).

## 33. Google OAuth / Discovery / Binding

REAL (code + tests) for OAuth lifecycle, discovery, human-confirmed binding. Manual live OAuth: NOT_MANUALLY_VERIFIED.

## 34. Meta OAuth / Discovery / Binding

REAL (code + prior UAT PASS recorded in ledger for connection slice). Re-verification in Prompt 67 environment: NOT_MANUALLY_VERIFIED.

## 35. Collection Engine Reality

REAL for control plane (CollectionRun → ResourceRun → DatasetRun, scheduler/lifecycle). Provider executor completeness varies by provider — see matrix.

## 36. Data Pool Reality

REAL foundation (raw storage + typed facts + materialization). Population depends on collectors/bindings — PARTIAL at portfolio scale.

## 37. GA4 Specialist Real Path

REAL for pool-backed glance/trends/acquisition/behavior/measurement events when bound. Page render does not call live Analytics Data API.

## 38. GA4 Residual Domain Clearing

Prompt 67: needs_attention, opportunities, business_actions, operations findings/recs/tasks/outcomes, relationship narrative, Demo freshness siblings → UNAVAILABLE/empty on real path.

## 39. GSC Specialist Real Path

REAL for property daily clicks/impressions/CTR, query/page/device/country where gated usable.

## 40. GSC Residual Domain Clearing

search_attention, brand/nonbrand, diagnosis, clusters/momentum/ownership residual Demo → UNAVAILABLE; freshness siblings cleared; operations findings cleared.

## 41. Google Ads Specialist Real Path

REAL for account/campaign/search term/keyword pool reads when bound.

## 42. Google Ads Residual Domain Clearing

needs_attention, opportunities, search clusters/inbox Demo, operations findings → UNAVAILABLE/empty; freshness siblings cleared.

## 43. Meta Ads Specialist Real Path

REAL for campaign/adset/ad daily + snapshots/typed actions when bound. Reach/frequency/result mix remain UNAVAILABLE by contract.

## 44. Meta Ads Residual Domain Clearing

needs_attention, opportunities, operations findings/recs/tasks/outcomes → empty/UNAVAILABLE; freshness siblings cleared.

## 45. Meta Campaigns / Detail Gating

`CampaignsPage` → `MetaAdsSpecialistReadService`. CampaignDetail/AdDetail gated so production asset ids do not load Demo creative narratives without real ids.

## 46. Website Specialist Production Shell

Production numeric website assets → `UnavailableWorkspaceShells::website` (no WebsiteWorkspaceFixtures). Catalog `web-atlas` remains DEMO.

## 47. GBP Specialist Production Shell

Production numeric GBP assets → `UnavailableWorkspaceShells::gbp` (no fake ranks/reviews). Local grid productionization UNAVAILABLE.

## 48. Instagram Specialist Production Shell

Production numeric Instagram assets → `UnavailableWorkspaceShells::instagram`. Analytics UNAVAILABLE.

## 49. Specialist Sub-capability Honesty

Each specialist is not a single status: glance KPIs may be REAL while residual cards are UNAVAILABLE and catalog mode is DEMO. Matrix enumerates sub-capabilities.

## 50. Operations Activity / Notifications

Activity + notification persistence REAL (Prompt 47 lineage). Mail delivery depends on SMTP config → deployment PARTIAL.

## 51. Approvals / QA / Playbooks / Recurring

Persistence REAL from prior prompts. Demo Atlas approval session states may still exist for catalog UX — must not override production recommendation decisions.

## 52. AI Agent Execution Reality

PARTIAL/REAL for operational agent execution paths delivered in Prompt 50 lineage; not all specialists execute live. Credentials env-dependent.

## 53. Intelligence Memory / Retrieval

Architecture + Website retrieval path REAL per prior prompts; broader wire-up PARTIAL.

## 54. Assistant Chat UNAVAILABLE

Interactive Assistant chat runtime is architecture-first (Prompt 56) — **UNAVAILABLE** as live product chat. Do not claim REAL.

## 55. Security Credential Hardening

REAL — encrypted credential casts, brokers, redaction, tenant guards (Prompt 64).

## 56. Observability Alert Evaluation

REAL — evaluate-alerts, heartbeats, snapshot without health score (Prompt 66). Cron registration is deployment concern.

## 57. Performance Scale Intake

Prompt 65 harness/fixtures retained; not a Demo contamination vector for `/app` business truth.

## 58. UnavailableWorkspaceShells

`App\Support\Reality\UnavailableWorkspaceShells` — truthful empty shells for Website/GBP/Instagram production assets.

## 59. DemoCatalogAssetGuard

`App\Support\Reality\DemoCatalogAssetGuard` — central id classification helper.

## 60. Contamination Boundaries

Documented in Isolation Contract §4. Primary risk was residual Demo provenance on `migration_mode=real` and hub/dashboard fixture injection — addressed in Prompt 67.

## 61. Manual Verification Policy

All live OAuth / SMTP / paid API checks for Prompt 67: **NOT_MANUALLY_VERIFIED**.

## 62. Removed Production Fallbacks Inventory

See `docs/reality/PRODUCTION_DEMO_REMOVAL_AUDIT.md`. Summary: specialist residual Demo domains; FindingsIndex DemoState data; hub fake connected cards; dashboard recentValue; agency Demo exceptions/outcomes; Meta campaigns read service; Website/GBP/Instagram fixture shells.

## 63. Retained Demo Inventory

`app/Livewire/Demo/**`, `app/Support/Demo/**`, DemoState, test/eval/perf fixtures, roles/modules/playbooks seeder — Explicit Demo + engineering only.

## 64. Tests

`tests/Feature/Reality/DemoRealityFinalConvergenceTest.php` plus existing specialist/product route tests updated for empty/unavailable assertions.

## 65. Explicit Non-Goals

No ObservabilityV2; no Autonomous remediation; no health score; no Assistant chat runtime; no GBP rank grid invention; no sidebar unfreeze; no SaaS/multi-tenant customer portal.

## 66. File Matrix

| Area | Paths |
| --- | --- |
| Reality helpers | `app/Support/Reality/*` |
| Specialist reads | `app/Services/{Ga4,Gsc,GoogleAds,MetaAds}/*SpecialistReadService.php` |
| Hub | `app/Services/Integrations/OperatorIntegrationsHubQuery.php` |
| Findings UI | `app/Livewire/Demo/Operations/FindingsIndex.php` |
| Dashboard | `app/Livewire/Demo/Dashboard.php` |
| Agency fixtures | `app/Support/Demo/AgencyExecutionFixtures.php` |
| Website/GBP/IG | `app/Livewire/Demo/{Website,Gbp,Instagram}/*` |
| Meta pages | `app/Livewire/Demo/Meta/*` |
| Docs | `docs/implementation/DEMO_REALITY_FINAL_CONVERGENCE.md`, `docs/architecture/CAPABILITY_REALITY_CONTRACT.md`, `docs/architecture/DEMO_ISOLATION_CONTRACT.md`, `docs/reality/*` |
| Tests | `tests/Feature/Reality/DemoRealityFinalConvergenceTest.php` |

## 67. Reality Matrix Summary

Authoritative table: `docs/reality/FINAL_CAPABILITY_REALITY_MATRIX.md`. Gaps-only: `docs/reality/REMAINING_PRODUCTION_GAPS.md`.

## 68. Remaining Gaps Pointer

PARTIAL/UNAVAILABLE/NOT_MANUALLY_VERIFIED and deployment blockers only — do not restate REAL capabilities in the gaps doc.

## 69. Production Demo Removal Audit Pointer

`docs/reality/PRODUCTION_DEMO_REMOVAL_AUDIT.md` — removed vs retained with safety rationale.

## 70. Capability Reality Contract Pointer

`docs/architecture/CAPABILITY_REALITY_CONTRACT.md`.

## 71. Demo Isolation Contract Pointer

`docs/architecture/DEMO_ISOLATION_CONTRACT.md`.

## 72. Deployment Requirements

For REAL collectors/integrations to operate in an environment: provider OAuth/app credentials, queue workers (Horizon/database queue), scheduler (`moxdop:ops:evaluate-alerts` et al.), mailer for notifications/report delivery, object storage for raw payloads/PDFs. Missing config ⇒ runtime unavailable — still not Demo.

## 73. Prompt 68+ Handoff

| Item | Prompt 67 | Next |
| --- | --- | --- |
| Residual specialist Demo on real path | Cleared | Wire canonical Finding/Opportunity reads into specialist operations cards if product wants |
| Website/GBP/Instagram analytics | Unavailable shells | Production observation → workspace migration |
| Assistant chat | UNAVAILABLE | Only after architecture runtime lands |
| Live provider UAT | NOT_MANUALLY_VERIFIED | Recorded PASS per environment |
| Hub non-Google/Meta | Truthful configured/not_connected | Deeper connection models when product requires |
| Observability cron | Documented | Deployment schedule registration automation |

## 74. Definition of Done

Prompt 67 is **DONE** when:

1. Production specialist real paths no longer leave Demo provenance in residual domains (needs_attention, opportunities, operations findings, business_actions, search clusters, freshness siblings).
2. FindingsIndex uses `FindingReadService` (not DemoState lists).
3. Integrations hub non-Google/Meta cards are truthful not_connected/configured.
4. Dashboard `recentValue` is empty (no Atlas ClientValueFixtures injection).
5. Agency execution awaiting_decision uses `RecommendationReadService`; Demo system_exceptions/recent_outcomes cleared.
6. Meta CampaignsPage uses `MetaAdsSpecialistReadService`; detail pages gated for production ids.
7. Website/GBP/Instagram production numeric assets use UnavailableWorkspaceShells.
8. Explicit Demo catalog paths still serve fixtures for Atlas string asset ids.
9. DatabaseSeeder creates no fake Customer.
10. Contracts + Final Matrix + Gaps + Removal Audit docs exist with accurate REAL/PARTIAL/DEMO/UNAVAILABLE only.
11. `DemoRealityFinalConvergenceTest` (and related updates) pass.
12. No claim of live OAuth/SMTP/paid API manual verification in Prompt 67.
13. Frozen sidebar unchanged.
