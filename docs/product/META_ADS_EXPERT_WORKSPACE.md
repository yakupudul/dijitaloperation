# META ADS EXPERT WORKSPACE

> **Status: IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED — NOT DONE.**  
> Meta-specific application of `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md`.  
> Visual/token layer: `docs/product/MOXDOP_DESIGN_SYSTEM.md` (Meta is the first full consumer).  
> Historical data: Meta Historical Performance Store (Integration/ExternalResource-scoped) — dashboards read local normalized history when coverage exists; date selection does not require Analyze for covered ranges; Reach/Frequency use exact-period non-additive cache; Manual Refresh is incremental.  
> Implementation lives on PR [#122](https://github.com/yakupudul/dijitaloperation/pull/122) / `cursor/meta-ads-expert-workspace-ea01`.  
> **Not DONE** until the operator explicitly accepts real-data screenshots. Synthetic visual UAT cannot satisfy acceptance.  
> **Explicitly out of scope for PR #119** (`docs/product/meta-ads/META_ADS_INTELLIGENCE.md`) — #119 shipped the intelligence engine; this workspace (+ historical foundation) is the follow-on milestone.  
> Related: `docs/product/meta-ads/META_ADS_INTELLIGENCE.md`, `docs/product/meta-ads/META_ADS.md`, `docs/product/meta-ads/META_ADS_INTEGRATION.md`, `docs/implementation/META_FOUNDATION_PASS_AUDIT.md`, `OPERATOR_ASYNC_EXECUTION.md`, `PROJECT_MEMORY.md`, `PRODUCT_CAPABILITY_LEDGER.md`.

## Purpose

Define what a genuinely expert, trustworthy Meta Ads operator workspace looks like once the intelligence engine (Evidence, Findings, Analyst) has matured — and record the target that the Expert Workspace PR implements toward. It replaces ad hoc workspace decisions with one documented target so future PRs converge instead of re-litigating navigation and default views each time.

This blueprint follows the four-layer model from `OPERATOR_WORKSPACE_DESIGN_STANDARD.md` (GLANCE → EXPLORE → DECIDE → DEEP DATA) and inherits its rules (Missing ≠ zero, platform-vs-outcome, no decorative charts, no internal jargon, semantic color only).

## Implementation status notes (2026-08-12)

| Area | State |
| --- | --- |
| Primary nav Overview / Campaigns / Creatives / Insights | **IMPLEMENTED** (code + PHPUnit) |
| Design System adoption (tokens / Blade primitives) | **IMPLEMENTED** (first consumer) |
| Meta historical store powering workspace reads | **IMPLEMENTED** (covered ranges; Evidence fallback when unbound/uncovered) |
| Date selection without Analyze when coverage exists | **IMPLEMENTED** |
| Exact-period Reach/Frequency cache | **IMPLEMENTED** |
| Integration-scoped initial history import (all accessible Ad Accounts, pre-Brand binding) | **IMPLEMENTED** |
| Incremental Manual Refresh | **IMPLEMENTED** |
| Operator visual UAT | **REQUIRED** — not accepted; **not DONE** |
| Google Ads / other module Expert Workspaces | **Not in this milestone** |

## Current state vs this blueprint

| | Pre-#122 (PR #119 engine UX) | This blueprint / #122 track |
| --- | --- | --- |
| Nav | Overview / Performance / Intelligence / Connections / Activity | Overview / Campaigns / Creatives / Insights (primary) + Connection / Sync / Data Health (secondary) |
| Campaign default filter | Not explicitly "delivered in period" | **Delivered in selected period** by default |
| Account-level result | Forced single Primary Result, shows "Deferred" when objectives differ per campaign | **Result Mix** — a labeled breakdown across result types, no forced single number |
| Data freshness | "Updated `<time>`" pill | Compact **Data Health badge** → drawer with detail |
| Async | Collection later queued (#121) | History import / gap enrich / Manual Refresh via Activity Center |
| History/trend | Single-Run KPI + prior-period delta only | Local Meta historical store for covered ranges; exact-period Reach/Frequency |

Do not treat code-complete as DONE: **USER VISUAL UAT REQUIRED**.

## Preferred navigation

Primary (operator-facing, always visible):

```text
Overview | Campaigns | Creatives | Insights
```

Secondary (grouped, less frequently needed — connection/ops concerns, not analysis):

```text
Connection | Sync | Data Health
```

- Primary tabs answer "how is this account doing and what should I look at". Secondary tabs answer "is the plumbing working".
- This mirrors the separation the Integrations workspace already uses between provider analysis and provider setup/health (`docs/product/integrations/WORKSPACE.md`), applied inside a single Digital Asset workspace instead of across the settings hub.
- "Insights" here means the EXPLORE/DEEP DATA breakdown surface (placement, audience, geo, time) — distinct from the existing "Intelligence" tab (Findings/Recommendations/AI guidance), which maps to DECIDE and may be folded into or linked from Overview rather than kept as a top-level tab; final IA arrangement is an implementation-time decision within this blueprint's intent.

## Persistent filter bar

A single filter bar persists across Overview, Campaigns, Creatives, and Insights (not re-specified per tab):

- **Date preset** — Today, Yesterday, Last 7 days, Last 14 days, Last 30 days, This month, Last month, This year, Custom — consistent with the account's reporting timezone.
- **Compare** — Previous period, Previous year, Custom, or Off; hidden/disabled when no valid comparison period exists (Missing ≠ zero — never a fabricated 0% delta).
- **Account** — when a Brand has multiple Meta Ad Accounts, an account switcher (never silently mixes accounts into one aggregate without the operator choosing to).
- **Delivery status** — Delivered / Active / Paused / Archived / All (see default below).
- **Objective** — filter by campaign objective family.
- **Campaign** — narrow to one or more specific campaigns; carries through to Creatives/Insights.
- **Future taxonomy** — reserved filter slot for Operational Taxonomy dimensions (Service/Offer, Market, Audience Segment, etc.) once that foundation exists (`PROJECT_MEMORY.md`, status PLANNED). Not implemented now; the filter bar's design should not preclude adding it later.

The filter bar is a single shared control, not duplicated per tab, so switching tabs preserves operator context.

## Default campaign visibility: delivered in selected period

The default campaign list is **not** "active right now". It is:

> Campaigns with **spend > 0 OR impressions > 0** within the selected period.

Rationale: an "Active now" filter hides campaigns that delivered meaningfully during the period but were paused after, and can also show campaigns that are technically "active" but delivered nothing — both are misleading defaults for a period-based review.

- Explicit filter options remain available: **Delivered** (the default rule above), **Active**, **Paused**, **Archived**, **All**.
- Default **sort** is by **material spend** (descending) so the campaigns that actually consumed budget surface first, not alphabetical or ID order.
- A campaign with zero spend and zero impressions in the period is not force-listed by default; it remains reachable via the **All** filter.
- This default applies to the Campaigns tab and to any campaign list rendered inside Overview (e.g. "what needs attention").

## Overview (GLANCE)

Identity and context, non-negotiable at the top of the screen:

- Brand
- Ad Account (name + external id)
- Meta Business (context only — not equivalent to Brand; see `PROJECT_MEMORY.md`)
- Selected period (from the persistent filter bar)
- Currency
- Last sync (operator language — never "last Run" in this surface)
- **Data Health badge** (compact; see below)

Small KPI set: a handful of named, explainable numbers for the selected period (e.g. Spend, Impressions, Reach, Frequency, CPM/CPC as applicable) — comparison deltas shown only when a valid prior period exists.

### Result Mix (account level)

Account-level results are shown as a **Result Mix**, not a forced single Primary Result:

- When campaigns in the account share a compatible objective/result type, the mix may collapse visually to a single dominant figure — but it is still framed as "this account's results are predominantly `<type>`", not as an unconditional single KPI.
- When campaigns have **heterogeneous objectives** (e.g. some optimizing for leads, some for traffic, some for engagement), Overview shows a **labeled breakdown across result types** (e.g. "42 Leads · 1,180 Link Clicks · 3 Purchases" with each figure attributed to its result family) instead of the current behavior of collapsing to a single "Deferred" placeholder.
- Result Mix never sums incompatible action types into one fake total (this constraint already exists in `docs/product/meta-ads/META_ADS_INTELLIGENCE.md` — "Never sum distinct action types into a fake total"; Result Mix is the Overview-level UI expression of that rule, not an exception to it).
- Each entry in the mix is a platform-attributed signal (see platform-vs-outcome rule) and is labeled as such.
- Campaign/ad set/ad-level primary-result resolution (the existing conservative resolver — resolved / zero / deferred / unresolved / none) is unchanged and remains the correct behavior at those narrower levels; Result Mix only changes how the **account-level rollup** is presented when a single resolved primary result would misrepresent a mixed-objective account.

### Performance-over-time (blueprint only)

A time-series view of the account's key metric(s) across the selected period is part of the target Overview. It **requires** the Historical Performance Store (see Dependencies) to render honestly across periods; until that exists, this section either does not render or renders a bounded "not enough history yet" state — it does not synthesize a trend from a single Run/Evidence snapshot.

### Funnel (business outcome — conditional)

A funnel that ends in a business-outcome stage (e.g. "→ Qualified Lead" or "→ Sale") is only shown when that stage is backed by real CRM/business Evidence for the same Brand/period. Absent that Evidence, Overview shows the platform-only funnel (e.g. Impressions → Clicks → Platform Result) and stops there — it never extrapolates a business stage from platform data alone (same rule as `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s platform-vs-outcome section).

### What Needs Attention

A short, high-signal list — not a dumping ground:

- Surfaces open Findings and drafted Recommendations that materially affect the current period (e.g. a campaign spending with no resolved result, a sudden delivery drop, a Data Health degradation).
- Bounded in length; links into Campaigns/Insights/Intelligence for detail rather than inlining full explanations.
- Empty state is explicit and calm ("Nothing needs attention right now") — never padded with low-signal noise to avoid looking empty.

### Data Health

Rendered as a **compact badge** on Overview (e.g. "Data Health: Good" / "Partial" / "Degraded") that opens a **drawer** with detail on demand:

- Drawer contents: last sync time, collection status (using the states from `OPERATOR_ASYNC_EXECUTION.md`: queued/running/completed/partial/failed), coverage by area (account/campaign/ad set/ad/creative — reusing the existing coverage concept from the current Overview), and any known partial/truncation caveats.
- The badge itself never expands inline into a full coverage table — that stays in the drawer, keeping GLANCE scannable.

## Campaigns → Ad Sets → Ads drill-down

A single drill-down hierarchy, consistent with the existing Meta entity model:

```text
Campaigns (default: delivered in period, sorted by spend)
  → Ad Sets (scoped to selected campaign(s))
    → Ads (scoped to selected ad set(s))
```

Suggested column groups per level (exact column set is an implementation detail, not frozen by this blueprint):

- **Identity** — name, status (Delivered/Active/Paused/Archived), objective/optimization goal
- **Delivery** — spend, impressions, reach, frequency
- **Result** — resolved primary result (count, human label, cost/result) using the existing conservative resolver; explicit **zero / deferred / unresolved / none** states rendered honestly, never coalesced to `0`
- **Efficiency** — CTR family (labeled explicitly: All Clicks / Link Clicks / Outbound Clicks — never fabricated), CPC, CPM

The filter bar (date, compare, delivery status, objective, campaign) applies consistently at every level of the drill-down.

## Creative workspace

A **cards/table hybrid**, not a pure asset gallery and not a pure spreadsheet:

- Card view emphasizes creative identity (name, format, associated ad/ad set/campaign) plus a small performance summary per creative.
- Table view (or a toggle) supports sorting/scanning across many creatives by the same performance columns used in the Ads drill-down.
- **No media binaries.** The workspace displays creative **metadata** (name, format, text fields already marked as untrusted per `META_ADS_INTELLIGENCE.md`) and, where the Graph API already exposes a lightweight reference (e.g. a thumbnail URL from creative metadata), may surface that reference — it does not download, store, or proxy raw ad media/video/image binaries as part of this blueprint.
- **No unsupported fatigue claims.** Ad fatigue (frequency-driven performance decay) is a well-known paid-media concept, but MoxDOP does not currently have the historical time-series depth to claim it reliably. Any fatigue-adjacent signal shown must be traceable to real Evidence (e.g. current-period frequency alongside current-period result trend) and must not claim a fatigue diagnosis the data cannot support. Real fatigue modeling depends on the Historical Performance Store (see Dependencies) and is explicitly future work, not part of this milestone.
- Creative copy remains **untrusted data** for AI/analysis purposes, consistent with `META_ADS_INTELLIGENCE.md`.

## Placement / audience / geo breakdowns

Bounded, useful breakdowns — not an open-ended pivot table:

- Support a small set of well-understood breakdown dimensions (e.g. placement, age/gender, region) one at a time or in a small number of pre-defined combinations chosen for genuine operator value (e.g. "placement over time" or "region × device").
- **Forbid combinatorial explosion.** The workspace does not offer an arbitrary N-dimension pivot/cross-tab that lets an operator request every breakdown dimension simultaneously — Meta's breakdown API already warns against many combinations, and MoxDOP does not currently have the storage/compute model to make that fast, honest, or cheap.
- Whether/how breakdown results are cached, stored historically, or bounded by API-imposed combination limits is a decision for the **Historical Performance Store / Operational Data Foundation** (`PROJECT_MEMORY.md`), not decided ad hoc per breakdown view. Until that foundation exists, breakdowns render live, bounded, single-dimension views only.

## Intelligence integrated into workflow

AI-derived guidance and deterministic Findings are not a separate silo the operator has to remember to check — they surface contextually:

- Findings/Recommendations relevant to a campaign appear near that campaign in the Campaigns drill-down (e.g. an indicator/badge that opens detail), not only in a disconnected "Intelligence" tab.
- Framing is consistently **Recommendation**, not command or autopilot language: AI guidance suggests; the operator decides; nothing auto-applies to Meta (write actions remain forbidden — `META_ADS_INTELLIGENCE.md`, `MASTER_SPEC.md` §5).
- The existing human-gated flow (Finding → AI-assisted Recommendation draft → human-created Recommendation → manual Task) is unchanged by this blueprint; this section only changes **where** that surfaces in the UI, not the underlying gate.

## Deep Data (expert-only)

The DEEP DATA layer for Meta Ads is explicitly expert-facing:

- Full normalized Evidence rows (account/campaign/ad set/ad/creative) with raw action-type detail, not just resolved/human-labeled summaries.
- Attribution/date-range provenance, collection metadata (Run id in operator-safe form, truncation/partial flags), and diagnostic detail from the primary-result resolver (e.g. why a result was marked unresolved).
- This is the natural home for anything too dense or too raw for GLANCE/EXPLORE/DECIDE — it is reachable, not hidden, but never the default landing view.

## Dependencies: what needs what

| Feature in this blueprint | Needs Historical Foundation | Needs Async (`OPERATOR_ASYNC_EXECUTION.md`) | Buildable on current Evidence snapshots |
| --- | --- | --- | --- |
| Overview identity, KPIs, Result Mix | — | — | Yes |
| Campaigns/Ad Sets/Ads drill-down + delivered-in-period default | — | — | Yes |
| Creative cards/table (metadata only) | — | — | Yes |
| Placement/audience/geo (bounded, single-dimension, live) | — | — | Yes |
| Data Health badge + drawer | — | Improves once collection is async (accurate `queued`/`running` states) | Partially — static last-sync/coverage today |
| Performance-over-time / multi-period trend | **Yes — required** | — | No |
| Reliable ad fatigue signal | **Yes — required** | — | No |
| "Sync" tab reflecting real background progress | — | **Yes — required** | No (today's sync is a blocking action, not observable progress) |
| Long collection / large-account Insights jobs not blocking the operator | — | **Yes — required** | No |
| Cross-campaign benchmark/comparison beyond current period | **Yes — required** | — | No |

Rows marked "Yes" in the last column could be built against the single-Run Evidence model. On the #122 track, Meta historical foundation + Async (#121) remove those blockers for Meta: performance-over-time and covered-range date queries read the local store; Sync/Refresh use Activity Center. Fatigue modeling beyond honest current-period signals remains future work. **Operator visual UAT is still required before DONE.**

## Relationship to PR #119

This blueprint does **not** change the scope, status, or Definition-of-Done of PR #119 (`Meta Ads Intelligence + Analyst V1`). #119 remains the engine milestone: collectors, Evidence, deterministic Findings, the Analyst/Skills, and the current specialist workspace (Overview / Performance / Intelligence / Connections / Activity). This document is the **next** milestone's target and is explicitly **not implemented in #119**.

## Explicit non-goals (this document)

- Does not redesign Google Ads / Website / GBP workspaces.
- Does not change the PR #119 Intelligence engine scope or Ads Manager spot-check acceptance.
- Does not build Operational Taxonomy, Marketing Initiative, or Benchmark Cohorts.
- Does not add creative media storage/download.
- Does not claim fatigue modeling beyond what current Evidence / history honestly supports.
- Does not introduce combinatorial breakdown pivoting.
- Does not add any new frontend package; prefers Filament 5 native widgets/tables/drawers + `MOXDOP_DESIGN_SYSTEM.md` Blade primitives.

## Acceptance intent

Expert Workspace + Meta historical foundation code on PR #122 is **IMPLEMENTED / TESTED**. Definition-of-Done **DONE** requires explicit operator visual acceptance against real Meta data. Synthetic visual UAT / disposable SQLite browser checks **do not** satisfy that gate.
