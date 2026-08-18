# Production Demo Removal Audit

Prompt 67 — what production Demo fallbacks were removed, what Demo remains, and why remaining Demo is safe.

**Base:** Prompt 66 HEAD `526fddb`.  
**Companions:** `DEMO_ISOLATION_CONTRACT.md`, `FINAL_CAPABILITY_REALITY_MATRIX.md`.

---

## 1. Purpose

Record an evidence-backed inventory so reviewers can verify MoxDOP no longer paints production assets with Atlas fixtures.

---

## 2. Removed production Demo fallbacks

| Area | Before (problem) | After (Prompt 67) | Evidence |
| --- | --- | --- | --- |
| GA4 real workspace residual domains | `needs_attention`, opportunities, business_actions, measurement business_actions, relationship narrative, operations findings/recs/tasks/outcomes retained Demo provenance; freshness kept Demo sibling chips | Cleared to empty / `DataSourceState::Unavailable`; freshness = GA4 chip only | `Ga4SpecialistReadService` |
| GSC real workspace residual domains | search_attention, brand/nonbrand, diagnosis, clusters/momentum/ownership Demo; Demo freshness siblings; ops findings Demo | Unavailable notes / cleared; Search Console chip only; ops findings empty | `GscSpecialistReadService` |
| Google Ads real workspace residual domains | needs_attention, opportunities, search clusters/inbox Demo; ops findings Demo; Demo freshness siblings | Cleared / Unavailable; Google Ads chip only | `GoogleAdsSpecialistReadService` |
| Meta Ads real workspace residual domains | needs_attention, opportunities Demo; ops findings Demo; Demo freshness siblings | Cleared / Unavailable; Meta Ads chip only | `MetaAdsSpecialistReadService` |
| FindingsIndex | DemoState-backed Finding lists / Atlas titles | `FindingReadService` only; empty without DB rows | `FindingsIndex`, convergence test |
| Operator Integrations Hub (non-Google/Meta) | Fake Connected / `last_check` Today-style | `truthfulProviderCard` → not_connected/configured, `last_check = —`, `provenance = real` | `OperatorIntegrationsHubQuery` |
| Dashboard `recentValue` | ClientValueFixtures Atlas narrative | `recentValue => []` | `Dashboard.php` |
| Agency execution dashboard | Demo awaiting_decision / system_exceptions / recent_outcomes fixtures | `awaiting_decision` from `RecommendationReadService`; exceptions/outcomes `[]` | `AgencyExecutionFixtures` |
| Meta CampaignsPage | Fixture-oriented path risk | `MetaAdsSpecialistReadService` | `CampaignsPage` |
| Meta CampaignDetail / AdDetail | Demo creative narratives on production ids | Gated for production asset ids | Detail Livewire pages |
| Website production numeric assets | Workspace fixtures possible | `UnavailableWorkspaceShells::website` | `Website/OverviewPage` |
| GBP production numeric assets | Fixtures / fake ranks risk | `UnavailableWorkspaceShells::gbp` | `Gbp/OverviewPage` |
| Instagram production numeric assets | Sample analytics risk | `UnavailableWorkspaceShells::instagram` | `Instagram/OverviewPage` |

---

## 3. Retained intentionally

| Retained | Why safe |
| --- | --- |
| `app/Livewire/Demo/**` | Historical namespace hosting frozen `/app` UI; runtime branches on real vs catalog ids |
| `app/Support/Demo/**` | Explicit Demo Runtime + shared period helpers; catalog fixtures only for string Atlas ids |
| `DemoCatalog` string asset ids (`ga4-atlas`, …) | Sole entry to full fixture workspaces (`migration_mode=demo_catalog`) |
| `DemoState` session | Atlas demo catalog UX + ephemeral filters/flash — not Finding source of truth |
| PHPUnit model factories | Test-only |
| Prompt 55 evaluation fixtures | Offline eval harness |
| Prompt 65 performance fixtures / harness | Benchmark-only |
| `DatabaseSeeder` roles/permissions/modules/playbooks | No fake Customer seeded |

---

## 4. Safety invariants for retained Demo

1. **Id gate:** `DemoCatalogAssetGuard` / binding `DemoCatalog` mode required for fixture workspaces.
2. **No fallback:** Real-path exceptions → operational UNAVAILABLE workspaces, not fixtures.
3. **No mixing:** Real KPI series never concatenate Demo points.
4. **Tests:** `tests/Feature/Reality/DemoRealityFinalConvergenceTest` asserts production emptiness and catalog Demo retention.
5. **Seeder:** Cannot introduce Atlas Customer rows via default seed.
6. **Hub honesty:** Absence of connection is visible as not_connected/configured — not “Connected.”

---

## 5. Residual risk (accepted)

| Risk | Mitigation |
| --- | --- |
| Namespace confusion (`Livewire\Demo`) | Documented; matrix + contracts; do not rename in Prompt 67 (sidebar freeze) |
| Future specialist card “helpfulness” regressions | Convergence PHPUnit; code review against Isolation Contract |
| Catalog Demo mistaken for Customer | Demo Mode labeling + non-numeric ids |

---

## 6. What was not removed (and must not be misread as production)

- Atlas full-product interactive Demo portfolio.
- Specialist workspace fixtures files (still used for catalog ids and shape templates where real builders start from shape then clear residuals).
- Connector/demo chrome copy that appears only under Explicit Demo Mode.

---

## 7. Verification checklist

- [x] Real GA4/GSC/GoogleAds/Meta residual Demo domains cleared
- [x] FindingsIndex → FindingReadService
- [x] Hub truthful non-Google/Meta cards
- [x] Dashboard recentValue empty
- [x] Agency execution Demo exceptions/outcomes cleared; awaiting_decision from recommendations
- [x] Meta campaigns read service + detail gating
- [x] Website/GBP/Instagram production shells unavailable
- [x] Catalog Demo still works for `DemoCatalog` asset ids
- [x] Docs: contracts + matrix + gaps + this audit
- [ ] Live OAuth/SMTP/paid API — **NOT_MANUALLY_VERIFIED** (explicit)

---

## 8. Conclusion

Production Demo fallbacks listed in §2 are removed. Remaining Demo is **Explicit Demo Runtime + engineering harnesses** only. Remaining Demo is safe while id gates, unavailable shells, and convergence tests hold.
