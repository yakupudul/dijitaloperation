# PRODUCT_CAPABILITY_LEDGER

> **Canonical product capability truth table for MoxDOP.**  
> Inspected against **actual** `origin/main` @ `171e5e7` (2026-08-11).  
> Do **not** treat “IMPLEMENTED V1” in older docs as Definition-of-Done **DONE**.  
> Persistent product direction: `PROJECT_MEMORY.md`.  
> Async operator standard: `OPERATOR_ASYNC_EXECUTION.md`.

## How to read this ledger

| Column | Meaning |
| --- | --- |
| **Code** | Meaningful product code present on **canonical main** |
| **Automated Tests** | PHPUnit coverage on main for the capability slice |
| **Real UAT** | Real provider / operator UAT explicitly claimed in canonical docs |
| **Operator UX** | Filament / operator surface usable for the slice |
| **Background-ready** | Long-running operator flows actually queue and return control (not merely Job classes existing) |
| **State** | Ledger state — see `PROJECT_MEMORY.md` Definition of Done |
| **Known blocker / debt** | Explicit gaps |
| **Canonical notes** | Scope boundaries and pointers |

**States used:** `PLANNED` · `IMPLEMENTING` · `CODE COMPLETE` · `TESTED` · `UAT REQUIRED` · `UAT PASS` · `PARTIAL` · `BLOCKED` · `DONE`

**Inspection rules used for this snapshot:**

- Unmerged PR code is **not** main.
- Job classes implementing `ShouldQueue` without operator `dispatch` ≠ background-ready.
- Filament actions that call `(new SomeJob(...))->handle(...)` or inline services are **synchronous**.
- “IMPLEMENTED V1” in product docs = version label / scoped slice, not automatic **DONE**.

---

## Capability ledger (main @ `171e5e7`)

| Capability | Code | Automated Tests | Real UAT | Operator UX | Background-ready | State | Known blocker / debt | Canonical notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Core Filament Customer → Brand CRUD / portfolio |
| Digital Assets | YES | YES | NO | YES | NO | TESTED | Collect/diagnose actions on view page are sync | Types include website, google_ads, gbp, meta_ads, instagram |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth/env operator-dependent; resource refresh sync | Agency Google Integration; ADR-039/040 |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | NO | TESTED | Long collects block HTTP; ADR-013 debt | GSC/GA4/Ads/GBP bound collectors via `CollectLiveBoundDataService` |
| Google Ads Intelligence | YES | YES | NO | YES | NO | TESTED | UAT REQUIRED; analyze/collect sync | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | NO | TESTED | Diagnosis/collect sync | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | NO | TESTED | Core diagnosis island; paid SEO refresh sync | Workspace V2A, SEO Light, AI guidance |
| Public Website Discovery | YES | YES | NO | YES | NO | TESTED | **Limited scope only**; discovery sync | Bounded public website/context + optional competitor candidates — **not** full web intelligence |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
| DataForSEO | YES | YES | NO | YES | NO | TESTED | Paid refresh in-request; cost/freshness guards matter | Central Integration + Website SEO collectors |
| AI Control Plane | PARTIAL | YES | NO | YES | NO | PARTIAL | Capability Router / Playbooks / RAG still PLANNED; AI calls sync | AI Router + Agent Profiles + Skill Library V1 present |
| Website Analyst | YES | YES | NO | YES | NO | TESTED | No tools/MCP/Capability Router; analyze sync | Website SEO Analyst + Brand Discovery Analyst |
| Google Ads Analyst | YES | YES | NO | YES | NO | TESTED | Analyze sync; real Ads UAT not claimed PASS | Second operational Agent after Website |
| Recommendation | YES | YES | NO | YES | N/A | TESTED | AI drafts only; humans create Recommendations | Finding → Recommendation gate |
| Tasks | YES | YES | NO | YES | N/A | TESTED | Snapshot immutability (ADR-029) | Manual Recommendation → Task |
| Outcome Loop | YES | YES | NO | YES | N/A | TESTED | Metric Outcomes / Learning Candidates not in V1 | Task outcome signals + Finding re-eval; no Result entity |
| Meta central Integration | YES | YES | YES | YES | NO | UAT PASS | Insights/Intelligence not on main | Agency Meta Integration; product docs claim real UAT PASS |
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Ad Account ExternalResources discovered |
| Meta binding | YES | YES | YES | YES | N/A | UAT PASS | Collect live data hidden without collector on main | Meta Ads Digital Asset ↔ AssetBinding |
| Meta Ads Intelligence | NO (on main) | YES (on PR #119) | NO | YES (on PR #119) | NO | CODE COMPLETE / TESTED / UAT REQUIRED — **NOT DONE** | **Available on PR #119; not canonical main.** Blockers: (1) real Ads Manager metric spot-check after hierarchy recollection, (2) sync collection = background debt (`OPERATOR_ASYNC_EXECUTION.md`), (3) professional operator workspace not implemented (`docs/product/META_ADS_EXPERT_WORKSPACE.md` — BLUEPRINT only) | Data-engine correctness pass on #119: provider-ID joins, delivered Insights sort/filter, missing≠zero, collection stage diagnostics, account Result Mix, AI coverage gate. Hierarchy UAT must reconfirm on real account before DONE |
| Professional Operator Workspace (Meta Ads) | NO | NO | NO | NO | N/A | PLANNED / BLUEPRINTED | Blueprint only — no UI/widget/route built; depends on Historical Performance Store + `OPERATOR_ASYNC_EXECUTION.md` | See `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` (global model) + `docs/product/META_ADS_EXPERT_WORKSPACE.md` (Meta-specific); explicitly out of scope for #119 |
| Async execution | PARTIAL | PARTIAL | NO | NO | NO | PARTIAL | Jobs exist but Filament invokes `->handle()` sync; no operator Activity Center | See `OPERATOR_ASYNC_EXECUTION.md` — current sync flows are debt |
| Historical performance memory | PARTIAL | PARTIAL | NO | PARTIAL | NO | PARTIAL | No dedicated historical warehouse / backfill / incremental store | Run/Evidence history exists; Historical Performance Store **PLANNED** |
| Operational Taxonomy | NO | NO | NO | NO | N/A | PLANNED | Do not invent taxonomy module yet | Direction in `PROJECT_MEMORY.md` |
| Marketing Initiative | NO | NO | NO | NO | N/A | PLANNED | No model/service on main | Brand-level commercial effort grouping — future |
| Benchmark Cohorts | NO | NO | NO | NO | N/A | PLANNED | No cohort objects on main | Compatible taxonomy dimensions required first |
| Cross-Asset Analyst | PARTIAL | YES | NO | YES | NO | PARTIAL | Deterministic packs TESTED; Analyst persona PLANNED; jobs invoked sync | Consistency packs in Core; Digital Operations Analyst future |
| Agency Learning | NO | NO | NO | NO | N/A | PLANNED | No Learning Candidate pipeline | Human-reviewed Agency Knowledge only — future |
| Platform Engineer | NO | NO | NO | NO | N/A | PLANNED | Research reference only (e.g. OpenHands) | Not a customer-analysis runtime |
| Google Business Profile | PARTIAL | YES | NO | PARTIAL | NO | PARTIAL | Reputation Intelligence PLANNED; thin workspace vs Website/Ads | Location profile collector present |
| Finding lifecycle / fingerprint | YES | YES | NO | YES | N/A | TESTED | Unique `(digital_asset_id, fingerprint)` | Persistent Findings; ADR-034 |
| Evidence / Run model | YES | YES | NO | YES | N/A | TESTED | Foundational model; not a historical warehouse | Evidence bound to Run; no separate Result entity |

---

## Critical clarifications

### Meta Ads Intelligence is not main

PR [#119](https://github.com/yakupudul/dijitaloperation/pull/119) (*Meta Ads Intelligence + Analyst V1*) is **OPEN** (`mergedAt: null`) as of this ledger snapshot.

Main capability state for Meta Ads Intelligence must **not** be recorded as DONE.

On PR #119 after the Meta data-engine correctness + expert workspace blueprint pass, accurate state is:

> **TESTED / UAT REQUIRED** — Available on PR #119; not canonical main. **NOT DONE.**

Remaining blockers before DONE (Definition of Done in `PROJECT_MEMORY.md`):

- Real Ads Manager metric spot-check (Spend / Impressions / Reach / Frequency / Result / Cost-per-result / CTR / CPC / CPM) on an identical date range + attribution context, **after** hierarchy recollection proves nonzero campaign/ad set/ad metrics
- Operator confirmation of binding edit UX with discovered accounts (no raw DB ids)
- Background / async execution debt — long Meta collection still synchronous (see `OPERATOR_ASYNC_EXECUTION.md`); async foundation is **out of scope for #119**
- Professional operator workspace (`docs/product/META_ADS_EXPERT_WORKSPACE.md`) remains **BLUEPRINT / NOT IMPLEMENTED** — current #119 specialist workspace is not that target workspace; blueprints recorded in `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` + `META_ADS_EXPERT_WORKSPACE.md`
- Any further gaps found during live UAT

Data-engine corrections already on #119 (still require real re-UAT proof): provider-ID hierarchy joins, delivered Insights sort/filter, missing≠zero metrics, per-stage collection diagnostics, account Result Mix, AI evidence coverage gate.

Main **does** include Meta central Integration + discovery + binding (connection layer), with product-doc real UAT **PASS** for that scoped slice only.

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async is not background-ready

On main:

- `ShouldQueue` Job classes exist under `app/Jobs/`
- Filament Digital Asset actions construct jobs and call `->handle(...)` **in-request**
- Collect / SEO refresh / Discovery / AI analyze call services **inline**
- No production `::dispatch(` / `Bus::dispatch` usage found in app PHP

Therefore **Background-ready = NO** for long operator flows, despite queue tables / Job classes.

### “IMPLEMENTED V1” ≠ DONE

Many product docs and `docs/PROJECT_STATUS.md` use **IMPLEMENTED V1** / **COMPLETED** for scoped milestones. This ledger intentionally separates:

- version / milestone labels
- Definition-of-Done **DONE**

Most coded capabilities on main are **TESTED** or **UAT PASS** (Meta connection slice) or **PARTIAL**, not **DONE**, especially while async debt remains for long-running flows.

---

## Module inventory (main)

| Module | On main | Notes |
| --- | --- | --- |
| `website` | YES | Collection, intelligence, Discovery, analysts, skills |
| `google-ads` | YES | Collector, Findings, Analyst, skills, workspace |
| `google-business-profile` | YES | First-module collector; Reputation not present |
| `meta-ads` | YES (connection UX only) | No Insights/collector/AI intelligence on main |
| `sample-module` | YES (fixture) | Not an operator product capability |

Core owns Customer/Brand/DigitalAsset, Integrations, Run/Evidence/Finding/Recommendation/Task, and cross-asset packs.

---

## Maintenance rule

When a PR changes capability behavior or readiness:

1. Update **this ledger in the same PR**
2. Update `PROJECT_MEMORY.md` if the change is a material product / architecture decision
3. Do not mark DONE without reconciling code, tests, real UAT, operator UX, async requirement, blockers, and docs

---

## Snapshot provenance

| Field | Value |
| --- | --- |
| Base | `origin/main` |
| Commit | `171e5e7` — Merge PR #118 (Meta Ads real UAT binding hotfix) |
| PR #119 | OPEN — Meta Ads Intelligence + Analyst V1 (not main) |
| Method | Code / test / Filament invocation / product-doc inspection |
| Guessing | Forbidden — unknown real UAT recorded as **NO** unless docs claim PASS |
