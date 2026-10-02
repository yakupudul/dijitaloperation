# WEBSITE REAL DATA MIGRATION

## STATUS: BLOCKED — Prompts 32 / 33 / 34 prerequisites missing

**Prompt:** 35  
**Date:** 2026-08-13  
**Branch:** `cursor/website-real-data-migration-ea01`  
**Base:** Prompt 34 HEAD `c2376af95b400edf8b758259ac099a64d0a062b7`  
**Note:** Prompt 34 and Prompt 33 HEADs are **blocker documents only**. Prompt 32 Website Production Data Foundation is **absent**.

---

## 1. Purpose

Prompt 35 migrates the frozen Website specialist from Demo fixtures to a
**composition read layer** over distinct canonical source domains:

```text
Website DigitalAsset
  → WebsiteSpecialistReadService
      ├── Website Foundation observations (Prompt 32)
      ├── WordPress source (Prompt 33)
      ├── Related GA4 pool (Prompt 28)
      ├── Related GSC pool (Prompt 29)
      ├── DataForSEO enrichment pool (Prompt 34)
      └── Control-plane / Integrity / Freshness
  → Frozen Website UI
```

Cross-source composition belongs in the **read layer only**.  
Provider facts must **not** be copied into Website base storage.

---

## 2. Frozen Website Setup Contract

Authoritative shell: `App\Livewire\Demo\Website\OverviewPage`

| Tab | Blade | Current data source |
|---|---|---|
| Overview | `tabs/overview.blade.php` | `WebsiteWorkspaceFixtures::workspace` + `DemoCatalog::websiteOverview` |
| Health | `tabs/health.blade.php` | `WebsiteWorkspaceFixtures::health()` |
| Visibility | `tabs/visibility.blade.php` | `WebsiteWorkspaceFixtures::visibility()` |
| Content | `tabs/content.blade.php` | `contentWorkspace` + `DemoCatalog::websiteContent` |
| Performance | `tabs/performance.blade.php` | `performanceWorkspace` + `DemoCatalog::websitePerformance` |
| Infrastructure | `tabs/infrastructure.blade.php` | `ConnectorWorkspaceFixtures::websiteInfrastructure()` |
| Operations | `tabs/operations.blade.php` | Demo findings/recs/tasks + outcomes |
| Setup | `tabs/setup.blade.php` | Demo connections/settings + Site Connector Demo path |

**Tabs changed:** NO (must remain).  
**Still Demo-backed:** YES — 100% fixture path today.  
**WebsiteSpecialistReadService:** MISSING.  
**WebsitePage model:** MISSING.

---

## 3. Prerequisite gate

| Milestone | Required for Prompt 35 | Status 2026-08-13 |
|---|---|---|
| Prompt 32 Website Production Data Foundation | WebsitePage, URL resolver, observation datasets, Materialization | **MISSING** |
| Prompt 33 WordPress Site Connector | Pairing, inventory, content ingestion into Prompt 32 | **BLOCKED** (docs only @ `c7cb3b0`) |
| Prompt 34 DataForSEO Production Enrichment | Cost-gated pool enrichment (RK/competitors/pages) | **BLOCKED** (docs only @ `c2376af`) |
| Prompt 28 GA4 real migration | Related GA4 read composition | GREEN (specialist) — cannot compose without Website root |
| Prompt 29 GSC real migration | Related GSC read composition | GREEN (specialist) — same |
| Prompt 26 / 27 | Integrity / Freshness | GREEN shared — not wired to Website specialist |

**Hard rule:** Do not invent Prompt 32–34 inside Prompt 35.  
Do not fake REAL Website UI from GA4/GSC alone while WebsitePage/foundation are absent.

---

## 4. Field migration inventory (pre-coding audit — incomplete by design)

Full field-by-field matrix is **deferred** until foundation exists.  
Frozen surface inventory (authoritative for unblock):

### Overview (Demo)
identity · sourceFreshness · glance KPIs · needsAttention · opportunities · inventorySnapshot · searchSnapshot · conversionSnapshot · recentOutcomes · aiGuidance

### Health (Demo)
technical observation groups · side panels · **no arbitrary health score in freeze**

### Visibility (Demo)
organic / local / ai lenses · KPI tiles · GSC-like + DataForSEO-like Demo panels

### Content (Demo)
inventory · directory · gaps / opportunity Demo sections

### Performance (Demo)
KPI strip · vitals · tables · GA4/GSC-shaped Demo series

### Infrastructure (Demo)
CMS / DNS / TLS / hosting / CDN-shaped Demo panels — **must become UNAVAILABLE where no observation exists**

### Operations (Demo)
findings · recommendations · tasks · outcomes — Prompt 35 must expose **control-plane facts only**; **0 Evidence/Findings/Opportunities created**

### Setup (Demo)
connections · settings · WordPress connector Demo — must wire to Prompt 33 real backend when available; passive render **0** provider calls

**Unmapped production classifications:** N/A until foundation — all current visible analytical values remain Demo until migration resumes.

---

## 5. Intended source composition (not implemented)

| Source | Tabs | Composition rule |
|---|---|---|
| Website Foundation | Overview, Health, Content, Infrastructure (partial), Operations | Primary Website facts |
| WordPress | Health, Content, Infrastructure (configured), Setup | Source ≠ domain; configured ≠ observed |
| GA4 | Overview, Performance, Content (page context) | Related DigitalAsset only — no hostname heuristic |
| GSC | Overview, Visibility, Performance, Content (page context) | Related DigitalAsset only — no first-property fallback |
| DataForSEO | Overview, Visibility | Local Materialization only — **0 paid calls on render** |
| Control plane | Operations, Setup | Connector / Binding / Collection / Integrity / Freshness |

### Forbidden
- `website_dashboard_metrics` mega-table copying GA4/GSC/DFS
- GA4 Sessions + GSC Clicks sum
- GSC Impressions ⇄ DataForSEO Search Volume merge
- GSC Position ⇄ DataForSEO Rank merge
- DataForSEO ETV ⇄ GA4 traffic merge
- Fuzzy WebsitePage matching
- Auto WebsitePage from DataForSEO Relevant Pages
- Evidence / Findings / Opportunities / AI / scores invented in Prompt 35

---

## 6–50. Deferred matrices

Mandatory matrices from Prompt 35 (field migration, tab reality, source→tab, cross-source semantics, page relationships, grain, Overview/Health/Visibility/Content/Performance/Infrastructure/Operations/Setup, keyword semantics, data states, Demo retirement, Evidence boundary):

**Deferred** — publishing empty REAL matrices would be dishonest.

Sibling pattern to follow on resume:

- `docs/implementation/GA4_REAL_DATA_MIGRATION.md`
- `docs/implementation/GSC_REAL_DATA_MIGRATION.md`
- `docs/implementation/META_ADS_REAL_DATA_MIGRATION.md`

---

## Tab reality (current)

| Tab | Final state today |
|---|---|
| Overview | DEMO |
| Health | DEMO |
| Visibility | DEMO |
| Content | DEMO |
| Performance | DEMO |
| Infrastructure | DEMO |
| Operations | DEMO (control-plane not wired) |
| Setup | DEMO |

Target after unblock (Prompt 35 DoD): REAL / PARTIAL / PROVIDER_LIMITED / UNAVAILABLE per source coverage — **zero hidden Demo on production analytical paths**.

---

## Reality matrix (after this blocker branch)

| Capability | State |
|---|---|
| Website Foundation | **MISSING** |
| WordPress Connector / ingestion | **MISSING** |
| DataForSEO production enrichment | **MISSING** |
| GA4 / GSC specialist real migrations | REAL / PARTIAL (standalone) |
| Website specialist shell | FROZEN |
| Website specialist real composition | **NOT YET** |
| Canonical Evidence / Findings / Opportunities / AI | NOT YET |

---

## Prompt 36 Handoff

Not started. Service Scope Persistence waits for Website real migration (or Architect resequence).

## Evidence Boundary

Prompt 35 creates **0** canonical Evidence rows. Technical supporting observations ≠ Evidence domain.

## Definition of Done

Prompt 35 DoD **cannot** be satisfied while Prompts 32–34 are not green.  
This branch records frozen UI audit + hard gate only. **STATUS: BLOCKED.**

### Unblock path

1. Complete Prompt 32 — Website Production Data Foundation  
2. Complete Prompt 33 — WordPress Site Connector Productionization  
3. Complete Prompt 34 — DataForSEO Production Enrichment  
4. Re-base Prompt 35 from exact green Prompt 34 HEAD  
5. Implement `WebsiteSpecialistReadService` composition (aggregate-before-compose)  
6. Field-by-field Demo retirement with truthful UNAVAILABLE/PARTIAL states
