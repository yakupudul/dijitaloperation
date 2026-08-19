# PROJECT_MEMORY

> **Canonical persistent product / architecture memory for MoxDOP.**  
> Inspected against `origin/main` @ `171e5e7` (2026-08-11).  
> Does **not** override `docs/MASTER_SPEC.md`. See **Source priority** below.  
> Implementation truth (coded / tested / UAT / UX / async) lives in `PRODUCT_CAPABILITY_LEDGER.md`.  
> Operator long-running execution standard: `OPERATOR_ASYNC_EXECUTION.md`.

---

## Product identity

**MoxDOP** (DOP — Dijital Operasyon Platformu) is an **internal digital operations platform for Moximu**.

It is **not**:

- SaaS
- a customer / client portal
- a subscription / billing product
- a marketplace / plugin ZIP store
- a multi-tenant Workspace product

Operators are agency owners and agency staff only. Customers do **not** log in.

Canonical operational hierarchy:

```text
Customer
→ Brand
→ Digital Asset
→ Integration / External Resource / Binding
→ Run
→ Evidence
→ Finding
→ Recommendation
→ Task
→ Outcome
```

Notes:

- **AI remains advisory and evidence-grounded.** AI does not invent Findings, silently override deterministic Recommendations, or auto-open Tasks.
- **External provider integrations remain READ-ONLY.** No external write actions.
- There is **no separate Result entity**. Outcomes are observed via later Evidence / Finding lifecycle and Task outcome signals.
- Single Filament panel: id `app`, path `/app`; `web` guard; `spatie/laravel-permission`.
- Modules live under `app-modules/` + `internachi/modular` (minimal registry: id + enabled/disabled).

---

## Brand / account model

One Brand **MAY** have:

- multiple Meta Ads accounts
- multiple Google Ads accounts
- multiple Digital Assets of the same provider type

**Canonical model:**

```text
ONE provider advertising account
=
ONE corresponding Ads Digital Asset
+
its provider binding
```

Do **not** force all Brand ad accounts into one Digital Asset.

Meta Business Manager / Google Manager (MCC) accounts may appear as **provider scope / container context**, but are **not** automatically equivalent to Brand.

---

## Central integration model

The agency authenticates providers **centrally**.

### Meta

```text
one central Meta Integration / agency credential
→ discover accessible Businesses / Ad Accounts
→ operator selects relevant account(s)
→ bind selected accounts to Brand Digital Assets
```

- No Meta App per customer.
- No access token per Ad Account as the primary auth model.

### Google

Follows the corresponding **central agency-auth** model (one agency Google Integration → discover resources → bind to Digital Assets).

Google **Collect Data** is Integration-scoped at the operator entry, but planning/execution is **Brand-scoped**: one `CollectionRun` per eligible Brand, same-brand GSC/GA4/Ads siblings in that run, no silent drop of sibling Brands, no cross-brand or cross-customer mixing inside a run. Incremental refresh due selection uses that Brand’s exact preflight binding IDs across Digital Assets (not only the website/GSC anchor). Meta same-customer multi-brand backfill remains a separate contract (one run may span Brands for the same Customer).

Site-scoped legacy connection paths may still exist for some Website connectors; the **direction of travel** is central Integration + External Resource + AssetBinding.

---

## Current product philosophy

```text
Provider / raw data
→ normalized operational data / Evidence
→ deterministic Findings
→ bounded Agent + Skills
→ AI interpretation
→ human Recommendation
→ human Task
→ later read-only refresh
→ Outcome
```

Hard distinctions:

| Platform / provider signal | Must not be treated as |
| --- | --- |
| Platform result | Verified business outcome |
| Meta lead | Qualified lead |
| Messaging result | Qualified customer |
| Purchase value | Verified profit (unless supported by business / CRM Evidence) |

Platform metrics are useful operational Evidence. They are **not** automatic truth about business success.

---

## Operational Taxonomy — planned foundation

**Status: PLANNED — do not implement in this memory milestone.**

Marketing entities will eventually be classified across **independent dimensions**, not one simple category string.

Example dimensions:

- Service / Offer
- Market / Geography
- Audience Segment
- Funnel Stage
- Business Goal
- Language
- Acquisition Type

Future classification should support:

- canonical terms
- aliases
- manual assignment
- AI / rule suggestions
- human approval
- provenance
- confidence
- valid-from / valid-to where needed

---

## Marketing Initiative — planned

**Status: PLANNED — do not implement yet.**

Brand-level grouping of provider entities that represent the **same commercial effort**.

Example:

```text
Mommy Makeover | Germany | Turkish Diaspora | Lead Gen
```

could later contain:

- Meta Campaign A
- Meta Campaign B
- Google Campaign X
- relevant landing-page context

Initiatives are a future organizational layer above raw provider campaign objects.

---

## Benchmark Cohort — planned

**Status: PLANNED — do not implement yet.**

Future cross-Brand comparisons should use **approved compatible taxonomy dimensions**.

Do **not** compare semantically incompatible platform metrics merely because labels look similar.

Example: Meta CTR and Google Search CTR are **not** automatically equivalent benchmark metrics.

---

## Operational Data Foundation — next foundation direction

**Status: DOCUMENTED DIRECTION ONLY — do not implement in this milestone.**

Planned building blocks:

- Provider Entity Catalog
- Historical Performance Store
- Historical backfill
- Incremental sync
- Operational Taxonomy
- Classification assignments
- Marketing Initiative foundations
- Benchmark Cohort foundations

Desired future behavior:

```text
Brand connects provider account
→ available provider history backfilled in resumable chunks
→ normalized daily facts retained
→ incremental updates continue
→ campaigns / entities are classifiable
→ historical filtering / comparison becomes possible
→ Evidence / Findings / Outcome / learning can use the history
```

Constraints:

- Historical store is **NOT RAG**.
- Do **not** use giant Evidence JSON dumps as the primary historical warehouse.
- Prefer normalized daily / entity facts with provenance.

---

## Agency Learning — future

**Status: PLANNED — no automatic self-modifying truth.**

Controlled future learning flow:

```text
Historical Evidence
+ Recommendation
+ Task
+ later Evidence
+ Outcome
→ Learning Candidate
→ human review
→ approved Agency Knowledge
```

No automatic Skill / Agent mutation from Outcomes without human approval.

---

## Outside-in Discovery status

**Current implemented capability on main: LIMITED public Website Discovery.**

It can obtain:

- bounded public website / context signals
- optional supported competitor **candidates** (when DataForSEO is configured)
- Brand Context **candidates** for human Accept / Edit / Ignore

It is **NOT** yet:

- full web intelligence
- social intelligence
- Facebook / Instagram public intelligence
- YouTube intelligence
- review monitoring
- news / mention monitoring
- continuous web monitoring

**Never** describe current Website Discovery as “all digital web discovery.”

Canonical product doc: `docs/product/DISCOVERY_INTELLIGENCE.md`.

---

## Operator workspace model — planned foundation

**Status: DOCUMENTED DIRECTION ONLY — not implemented; no UI built from this yet.**

`docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` defines one shared operator workspace shape across channel/module workspaces (Meta Ads, Google Ads, Website, GBP): **GLANCE → EXPLORE → DECIDE → DEEP DATA**, progressive disclosure, semantic-color-only design, no decorative charts, and the **Missing ≠ zero** rule (absent/uncollected data must never render as `0`).

It also codifies, as a UI-layer requirement, the existing platform-attribution-vs-verified-business-outcome distinction, and requires operator-facing workspaces to avoid internal jargon (Run/Evidence/ExternalResource/CoreAssetBinding) in favor of operator language — extending the pattern already used in `docs/product/integrations/WORKSPACE.md`.

Meta-specific application: `docs/product/META_ADS_EXPERT_WORKSPACE.md` (status: **BLUEPRINT / NOT IMPLEMENTED**; explicitly out of scope for PR #119).

Two decisions worth remembering from that blueprint:

- **Result Mix over forced Primary Result at account level.** When an account's campaigns have heterogeneous objectives, Overview should show a labeled breakdown across result types ("Result Mix") instead of collapsing to the current "Deferred" placeholder. Campaign/ad set/ad-level primary-result resolution is unchanged.
- **Delivered-in-selected-period is the default campaign filter**, not "Active now" — a campaign qualifies by `spend > 0 OR impressions > 0` in the selected period, sorted by material spend. Active/Paused/Archived/All remain explicit alternate filters.

A professional operator workspace (real performance-over-time, reliable multi-period comparison, fatigue-adjacent signals) is **blocked** on the Historical Performance Store / Operational Data Foundation and on `OPERATOR_ASYNC_EXECUTION.md` adoption — it cannot be honestly built on single-Run Evidence snapshots or blocking sync collection alone.

## Meta / Google intelligence (main vs unmerged)

### Google Ads Intelligence

Present on **canonical main** (module collectors, Findings, Google Ads Analyst + Skills, workspace UX).  
State details: see `PRODUCT_CAPABILITY_LEDGER.md`. Product doc often labels this “IMPLEMENTED V1” — that means a technical version slice, **not** automatically Definition-of-Done **DONE**.

### Meta Ads Intelligence

**PR #119** (`Meta Ads Intelligence + Analyst V1`) is the read-only Meta Ads Intelligence engine (collectors, Evidence, Findings, Analyst/Skills, interim specialist workspace).

Operator Ads Manager spot-check: **PASS**  
Account `act_744654160596455` · Campaign `09 | Diaspora TR | Form - Mox` · Period `2026-07-14`→`2026-08-10`.

Canonical ledger state: **UAT PASS / ACCEPTED — NOT DONE**.

Still explicit:

- **Background-ready: YES** for Collect live data + Generate AI guidance (database queue + Activity Center). Professional workspace still **NOT IMPLEMENTED**. Async Meta operator UAT is validated on the Async Operations PR (read-only).
- **Professional Meta Expert Workspace: BLUEPRINTED / NOT IMPLEMENTED** (`docs/product/META_ADS_EXPERT_WORKSPACE.md` + `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`)
- Do not call Meta Ads “complete”, “finished”, or “workspace done”

Main also has Meta **central Integration + resource discovery + binding** (connection layer).

Details: `PRODUCT_CAPABILITY_LEDGER.md`.

---

## Environments (material)

| Environment | Role |
| --- | --- |
| Cursor Cloud / local agent | **Development / automated test** only |
| PHPUnit | Isolated testing (`sqlite :memory:`) |
| Disposable browser-UAT SQLite | Synthetic browser checks only |
| **persistent UAT** | Future browser host when operator provisions infrastructure — **PREPARED / DEFERRED** (`docs/operations/PERSISTENT_UAT.md`) |
| Production | Future; **not** claimed by Async / UAT template work |

Persistent UAT decisions (when eventually used):

- Uses **MySQL 8** (not Cloud SQLite)
- Web = **Nginx + PHP-FPM**; plus separate persistent **queue worker** and **scheduler**
- One stable **`APP_KEY`** across deploys so encrypted provider credentials survive
- Provider credentials and real bindings must survive deploys; never regenerate `APP_KEY` casually
- Target hostname concept: `https://uat.dop.moximu.com` (operator DNS/host required)

**Async implementation acceptance** (queue + Activity + Cloud Meta smoke) is independent of **persistent deployment acceptance**. Operator decision (2026-08-12): do **not** provision VPS until Meta Expert Workspace UI is useful; Cursor Cloud remains development/test.

---

## Definition of Done

A feature is **NOT** considered **DONE** merely because code exists.

**DONE** requires the relevant dimensions to pass:

1. Code implemented
2. Automated tests
3. Real / provider UAT where applicable
4. Operator UX usable
5. Async / background-safe where long-running
6. Security / provenance checked
7. Known blockers resolved
8. Canonical documentation updated

Use explicit states such as:

| State | Meaning |
| --- | --- |
| `PLANNED` | Direction accepted; no meaningful product code |
| `IMPLEMENTING` | Active work; not ready to treat as main capability |
| `CODE COMPLETE` | Code on target branch; tests/UAT/UX may lag |
| `TESTED` | Automated tests cover the capability on main |
| `UAT REQUIRED` | Needs real provider / operator verification |
| `UAT PASS` | Real/provider UAT recorded as pass for the scoped slice |
| `PARTIAL` | Meaningful subset only; gaps are explicit |
| `BLOCKED` | Cannot proceed without resolving a named blocker |
| `DONE` | Meets Definition of Done for the scoped slice |

**Avoid** using “Implemented V1” as a synonym for **DONE**.

Technical version labels (for example Agent 1.0.0, “Intelligence V1”) remain valid as **version identifiers**, not completion claims.

Reconcile claims against `PRODUCT_CAPABILITY_LEDGER.md` before asserting completeness.

---

## External repository references

Reviewed external repos are **references only**. Never automatically vendor / copy them into this repository.

| Repository | Role |
| --- | --- |
| [coreyhaines31/marketingskills](https://github.com/coreyhaines31/marketingskills) | Methodology / Skills reference |
| [joshbuchea/HEAD](https://github.com/joshbuchea/HEAD) | Technical SEO taxonomy reference |
| [AgriciDaniel/claude-seo](https://github.com/AgriciDaniel/claude-seo) | SEO methodology + Recommendation framing reference |
| [every-app/open-seo](https://github.com/every-app/open-seo) | Selective implementation / workflow reference |
| [zubair-trabzada/geo-seo-claude](https://github.com/zubair-trabzada/geo-seo-claude) | Future GEO methodology reference |
| [garmeeh/next-seo](https://github.com/garmeeh/next-seo) | Structured-data taxonomy / reference |
| [pipeboard-co/meta-ads-mcp](https://github.com/pipeboard-co/meta-ads-mcp) | Meta taxonomy / reference only — **no** runtime / write adoption |
| [georgekhananaev/google-reviews-scraper-pro](https://github.com/georgekhananaev/google-reviews-scraper-pro) | Review intelligence concepts only — scraper runtime **rejected** |
| [Panniantong/Agent-Reach](https://github.com/Panniantong/Agent-Reach) | Capability / Adapter architecture reference — runtime **not** adopted |
| [OpenHands/OpenHands](https://github.com/OpenHands/OpenHands) | Future Platform Engineer research reference — **not** customer-analysis runtime |

Canonical adoption registry: `docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`.

---

## Source priority

Preserve MASTER_SPEC supremacy while integrating project memory:

1. `docs/MASTER_SPEC.md` — product truth (highest)
2. Latest accepted ADRs (`docs/foundation/DECISION_LOG.md`)
3. `PROJECT_MEMORY.md` — persistent product / architecture memory (this file; does not override MASTER_SPEC)
4. Relevant `docs/product/*` / module blueprints
5. `PRODUCT_CAPABILITY_LEDGER.md` — **implementation truth** (coded / tested / UAT / UX / async)
6. `docs/IMPLEMENTATION_ROADMAP.md`
7. `docs/PROJECT_STATUS.md`
8. `AGENTS.md` / supporting references (`docs/foundation/*`, `docs/module-sdk/*`, research)

`docs/current-state/*` remains **historical** snapshot material. On conflict with the sources above, current-state loses.

When behavior or capability state changes, update `PRODUCT_CAPABILITY_LEDGER.md` in the **same PR**.  
When material product / architecture decisions change, update `PROJECT_MEMORY.md` in the **same PR**.

---

## Related canonical docs

| Doc | Role |
| --- | --- |
| `docs/MASTER_SPEC.md` | Product constitution |
| `PRODUCT_CAPABILITY_LEDGER.md` | Capability truth table |
| `OPERATOR_ASYNC_EXECUTION.md` | Operator async execution standard |
| `docs/PROJECT_STATUS.md` | Human/agent progress tracker |
| `docs/product/*` | Domain blueprints |
| `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` | Global operator workspace model (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/product/META_ADS_EXPERT_WORKSPACE.md` | Meta-specific workspace blueprint (BLUEPRINT / NOT IMPLEMENTED) |
| `docs/foundation/DECISION_LOG.md` | ADRs |
