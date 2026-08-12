# PRODUCT_CAPABILITY_LEDGER

> **Canonical product capability truth table for MoxDOP.**  
> Updated for Meta Ads Expert Workspace + Design System + Meta historical foundation (PR #122 / `cursor/meta-ads-expert-workspace-ea01`).  
> State for that milestone: **IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED** — **not DONE**.  
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

## Capability ledger

| Capability | Code | Automated Tests | Real UAT | Operator UX | Background-ready | State | Known blocker / debt | Canonical notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Customer / Brand management | YES | YES | NO | YES | N/A | TESTED | Formal real-operator UAT not recorded as PASS | Core Filament Customer → Brand CRUD / portfolio |
| Digital Assets | YES | YES | NO | YES | PARTIAL | TESTED | Long actions migrated to queue; short cross-asset checks still sync | Types include website, google_ads, gbp, meta_ads, instagram |
| Google central Integration | YES | YES | NO | YES | NO | TESTED | Live OAuth/env operator-dependent; resource refresh sync | Agency Google Integration; ADR-039/040 |
| Google resource discovery / binding | YES | YES | NO | YES | NO | TESTED | Refresh resources runs in-request | ExternalResources + AssetBinding |
| Google live collection | YES | YES | NO | YES | YES | TESTED | Async via Activity Center / database queue; real Ads UAT not re-run here | GSC/GA4/Ads/GBP bound collectors via queued `CollectLiveBoundDataJob` |
| Google Ads Intelligence | YES | YES | NO | YES | YES | TESTED | Collect + AI guidance queued; Expert Workspace not redesigned | Module Findings + Analyst + Skills; docs say IMPLEMENTED V1 |
| Website collection | YES | YES | NO | YES | YES | TESTED | Refresh data + diagnosis queued | GSC/GA4 + diagnosis probes; distinct from public Discovery |
| Website Intelligence | YES | YES | NO | YES | YES | TESTED | SEO refresh queued when provider work needed; fresh cache stays sync | Workspace V2A, SEO Light, AI guidance |
| Public Website Discovery | YES | YES | NO | YES | YES | TESTED | **Limited scope only**; discovery queued | Bounded public website/context + optional competitor candidates — **not** full web intelligence |
| Brand Context | YES | YES | NO | YES | N/A | TESTED | Discovery proposes candidates; humans approve | `BrandIntelligenceContext` operator-owned facts |
| DataForSEO | YES | YES | NO | YES | YES | TESTED | Paid refresh queued when not fresh; cost/freshness guards remain | Central Integration + Website SEO collectors |
| AI Control Plane | PARTIAL | YES | NO | YES | PARTIAL | PARTIAL | Capability Router / Playbooks / RAG still PLANNED; long AI guidance queued | AI Router + Agent Profiles + Skill Library V1 present |
| Website Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; no tools/MCP/Capability Router | Website SEO Analyst + Brand Discovery Analyst |
| Google Ads Analyst | YES | YES | NO | YES | YES | TESTED | Guidance generation queued; real Ads UAT not claimed PASS | Second operational Agent after Website |
| Recommendation | YES | YES | NO | YES | N/A | TESTED | AI drafts only; humans create Recommendations | Finding → Recommendation gate |
| Tasks | YES | YES | NO | YES | N/A | TESTED | Snapshot immutability (ADR-029) | Manual Recommendation → Task |
| Outcome Loop | YES | YES | NO | YES | N/A | TESTED | Metric Outcomes / Learning Candidates not in V1 | Task outcome signals + Finding re-eval; no Result entity |
| Meta central Integration | YES | YES | YES | YES | NO | UAT PASS | Resource refresh still sync | Agency Meta Integration; product docs claim real UAT PASS |
| Meta resource discovery | YES | YES | YES | YES | NO | UAT PASS | Discovery sync | Ad Account ExternalResources discovered |
| Meta binding | YES | YES | YES | YES | N/A | UAT PASS | Collect live data hidden without collector | Meta Ads Digital Asset ↔ AssetBinding |
| Meta Ads Intelligence | YES | YES | YES | YES (interim specialist UX → Expert Workspace PR) | YES | **UAT PASS / ACCEPTED — NOT DONE** | Collect + AI guidance **queued**. Professional Meta Expert Workspace is separate capability below. | Read-only Intelligence engine on main after PR #119. Ads Manager spot-check PASS retained. |
| Professional Operator Workspace (Meta Ads) | YES | YES | NO | YES | YES | **IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED** | **Not DONE** until explicit operator visual acceptance. Synthetic visual UAT does not satisfy. Persistent UAT host not deployed. | Primary nav Overview / Campaigns / Creatives / Insights; Connection + Sync secondary. Design System consumer. Reads local historical store when coverage exists (no Analyze-per-date). Exact-period Reach/Frequency. Incremental Manual Refresh. |
| Async execution | YES | YES | YES (Cloud Meta async smoke) | YES | YES | **DONE (implementation) / MERGED** | Cancellation future; cross-asset still sync; persistent public host **deferred** (templates only — not deployed) | PR #121 merged to main (`f6818f0`). Async implementation acceptance ≠ persistent deployment acceptance. |
| Historical performance memory (Meta) | YES | YES | NO | YES | YES | **IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED** | **Not DONE** — operator visual UAT required. Google Ads warehouse still PLANNED. Taxonomy/Initiative/Cohort still PLANNED. | Integration/ExternalResource-scoped initial import (all discovered accessible Ad Accounts, pre-Brand binding). Local normalized daily facts + exact-period non-additive Reach/Frequency cache. Manual Refresh incremental. See `META_FOUNDATION_PASS_AUDIT.md`. |
| Historical performance memory (Google Ads / cross-provider) | NO | NO | NO | NO | NO | PLANNED | Meta slice only so far | Google Ads warehouse + shared Provider Entity Catalog remain future |
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

### Meta Ads Intelligence — UAT PASS / ACCEPTED, not DONE

PR [#119](https://github.com/yakupudul/dijitaloperation/pull/119) (*Meta Ads Intelligence + Analyst V1*) merges the **read-only Meta Ads Intelligence engine** onto main.

Accurate multidimensional state:

> **UAT PASS / ACCEPTED — NOT DONE**

Accepted operator UAT (Ads Manager manual spot-check **PASS**):

| Field | Value |
| --- | --- |
| Meta Ad Account | Obezite ve Estetik (`act_744654160596455`) |
| Campaign | `09 \| Diaspora TR \| Form - Mox` |
| Period | `2026-07-14` → `2026-08-10` |
| Result | DOP metrics matched Meta Ads Manager |

Also accepted on this slice: hierarchy collection, provider-ID joins, missing≠zero, click/result metric semantics, synthetic UAT isolation, read-only Meta client (GET only).

**Explicitly still NOT DONE / not claimable as finished Meta product:**

- **Background-ready: YES** for collect + AI guidance (queued) — Activity Center persists progress. Real async Meta collect UAT is on the Async Operations PR (#121).
- **Professional Operator Workspace: IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED** — target IA in `docs/product/META_ADS_EXPERT_WORKSPACE.md` (+ global `OPERATOR_WORKSPACE_DESIGN_STANDARD.md` + `MOXDOP_DESIGN_SYSTEM.md`). **Not DONE** until explicit operator visual acceptance. Synthetic visual UAT does not satisfy.
- **Meta Historical Performance Store: IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED** — Integration/ExternalResource-scoped import, local normalized history, exact-period Reach/Frequency, incremental Manual Refresh. Google Ads warehouse still **PLANNED**.

Do **not** describe this merge as “Meta Ads complete”, “Meta module finished”, or “Meta workspace done”.

### Meta Ads Expert Workspace + historical foundation (PR #122 track)

Branch `cursor/meta-ads-expert-workspace-ea01` / PR [#122](https://github.com/yakupudul/dijitaloperation/pull/122):

| Dimension | Claim |
| --- | --- |
| Code | YES — Expert Workspace UI, Design System tokens/Blade, Meta historical schema/import/query |
| Automated tests | YES — PHPUnit for schema, upserter, query, import async, historical workspace |
| Real / operator visual UAT | **REQUIRED** — not yet accepted; synthetic checks insufficient |
| Operator UX | YES — Overview / Campaigns / Creatives / Insights; history import on Integration; Manual Refresh incremental |
| Background-ready | YES — history import, gap enrich, Manual Refresh via Activity Center |
| State | **IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED — NOT DONE** |

Material product decisions recorded in `PROJECT_MEMORY.md` (Design System singleton, pre-binding Integration import, local date queries, exact-period Reach/Frequency, all-account initial import, incremental Refresh).

Main also continues to include Meta central Integration + discovery + binding (connection layer), with prior product-doc real UAT PASS for that scoped slice.

### Public Website Discovery is limited

Current Discovery is **Website-owned bounded public discovery**:

- public website / context signals
- Brand Context candidates (human review)
- optional DataForSEO competitor **candidates**

It is **not** full digital web discovery, social intelligence, review/news monitoring, or continuous monitoring.

### Async foundation (material)

Long operator actions (bound collect, Website diagnosis, public discovery, SEO refresh when not fresh, Website/Google/Meta AI guidance) queue via `AsyncOperationService` onto Laravel **database** queue. Canonical execution record remains **Run** (`queued|running|completed|partial|failed`; `cancelled` reserved). Activity Center is Filament `RunResource` (`/app/runs`) with phase progress, duplicate guards, stale detection, retry for safe failures, and in-app database notifications. **Cancellation** is intentionally **not** shipped (fragile with current job architecture). Cross-asset consistency packs and integration resource refresh remain synchronous by design for now.

### Async is not fully universal

On main after Async Operations merge:

- Migrated Digital Asset long actions **dispatch** queue jobs (not `(new Job)->handle()`)
- Cross-asset consistency checks may still call `->handle()` in-request (short/safe)
- Integration resource discovery refresh may still be sync
- Cancellation of in-flight provider work is **future**

Track readiness per capability in this ledger’s **Background-ready** column — do not mark Historical Store / Expert Workspace DONE because async landed.

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
| `meta-ads` | YES | Insights collector + Intelligence + Analyst on main after #119; Expert Workspace + historical store on #122 track (UAT required); async collect/AI/history via Core queue jobs |
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
| Base | `origin/main` + PR #122 branch `cursor/meta-ads-expert-workspace-ea01` |
| PR #119 | Ads Manager operator spot-check **PASS** — merge acceptance for Intelligence engine |
| PR #122 | Expert Workspace + Design System + Meta historical foundation — **USER VISUAL UAT REQUIRED** |
| Accepted UAT (#119) | `act_744654160596455` / `09 \| Diaspora TR \| Form - Mox` / `2026-07-14`→`2026-08-10` |
| Method | Code / test / Filament invocation / operator Ads Manager comparison (#119); operator visual acceptance pending (#122) |
| Guessing | Forbidden — unknown real UAT recorded as **NO** unless docs claim PASS |
