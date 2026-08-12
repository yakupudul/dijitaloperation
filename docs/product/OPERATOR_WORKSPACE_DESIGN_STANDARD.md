# OPERATOR WORKSPACE DESIGN STANDARD

> **Status: ACCEPTED STANDARD — Meta application IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED; other modules not migrated.**  
> Canonical global operator workspace model for MoxDOP modules — information-architecture governance.  
> Applies to per-Digital-Asset intelligence workspaces (Meta Ads, Google Ads, Website, Google Business Profile) and any future paid-media / channel workspace.  
> Does not override `docs/MASTER_SPEC.md`. See **Source priority** in `PROJECT_MEMORY.md`.  
> Visual/token companion: `docs/product/MOXDOP_DESIGN_SYSTEM.md` (one global Design System).  
> Async: `OPERATOR_ASYNC_EXECUTION.md`. Historical data: Meta slice implemented on PR #122; Google Ads warehouse still PLANNED (`PROJECT_MEMORY.md`).  
> First concrete application: `docs/product/META_ADS_EXPERT_WORKSPACE.md` (**IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED — not DONE**).

## Purpose

Give every channel/module workspace one shared operator mental model instead of each module inventing its own information architecture. Operators move between Website, Google Ads, Meta Ads, and GBP workspaces daily; the **shape** of the screen (where to glance, where to explore, where to decide, where to dig) should feel identical even though the metrics underneath are channel-specific.

This document defines the **model**. Per-channel content and screens live in module blueprints (e.g. `META_ADS_EXPERT_WORKSPACE.md`). The Design System owns tokens and Blade primitives.

## Status notes (2026-08-12)

- **Design System + Meta Expert Workspace + Meta historical store:** implemented and PHPUnit-tested on PR #122; **operator visual UAT required** before DONE.
- **Google Ads / Website / GBP Expert Workspace redesigns:** not claimed; continue existing surfaces until each module adopts this standard + Design System in its own milestone.
- Synthetic visual UAT cannot satisfy real operator acceptance for Meta.

## Core architecture: GLANCE → EXPLORE → DECIDE → DEEP DATA

Every operator workspace organizes information into four progressive layers. Layers map to **attention cost**, not to database tables or Filament resource boundaries.

```text
GLANCE
  "Is this healthy? Did anything change?"
  → identity, period, sync freshness, Data Health, a small KPI set, top signals

EXPLORE
  "Where is the story? What's driving the number?"
  → breakdowns, comparisons, drill paths (account → campaign → ad set → ad),
    performance-over-time, result composition

DECIDE
  "What should I do about it, and why?"
  → Findings, Recommendations, AI guidance framed as suggestions,
    "what needs attention" surfaces — always traceable to Evidence

DEEP DATA
  "Show me everything, unfiltered, expert mode."
  → raw normalized rows, full breakdown tables, diagnostics, provenance,
    export-style detail — for power users and troubleshooting
```

Rules for the four layers:

- **Layer order is fixed.** GLANCE is always the entry point; DEEP DATA is never the default landing view.
- **Each layer answers fewer, better questions than the layer below it.** GLANCE should be scannable in seconds; DEEP DATA can take minutes.
- **No layer duplicates another layer's job.** GLANCE does not repeat DEEP DATA tables in miniature; it summarizes them.
- A workspace may implement these as tabs/sections rather than literal named layers, as long as the progressive-disclosure order holds.

## Progressive disclosure

- Default view shows the minimum an operator needs to judge health and spot the one or two things that matter today.
- Additional detail is opt-in (tab switch, expand, drawer, drill-down) — never dumped by default.
- A workspace with partial data must still render its GLANCE layer; missing sections collapse gracefully rather than blocking the page (see **Missing ≠ zero**).
- Depth increases as data availability increases. A brand connected yesterday sees an honest "collecting" state, not a broken or empty-looking dashboard.

## Visual hierarchy

- One clear primary question per screen region; supporting numbers are visually subordinate (size, weight, position).
- KPI sets stay small and named — a handful of numbers an operator can hold in their head, not a wall of tiles.
- Comparison deltas (vs prior period) are secondary to the headline value, and are hidden entirely when no valid comparison period exists (never shown as `0%` or fabricated).
- Whitespace and grouping communicate relationship: numbers that belong to the same story are visually clustered; unrelated numbers are separated.

## Explainable numbers

Every number an operator sees on GLANCE or EXPLORE must be traceable, in-product, to:

- what it measures (a named metric, not a raw provider field id)
- the time window it covers
- the data source / attribution context behind it
- whether it is a **platform-attributed** signal or a **verified business outcome** (see below)

If a number cannot be explained in one short sentence without engineering jargon, it does not belong on GLANCE. Move it to EXPLORE or DEEP DATA, or drop it.

## No decorative dashboards

- Every chart, badge, and number must answer a **named operator question**. If a chart cannot be labeled with the question it answers, it does not ship.
- No decorative pie charts, donut charts, or maps included purely for visual variety. A chart earns its place only when a line/bar/table would lose information the operator actually needs (e.g. a genuine time trend, or a genuine share-of-total breakdown that drives a decision).
- Prefer plain numbers, short tables, and simple time-series lines over ornamental visualization. Agency-facing operator tools are working tools, not marketing decks.
- Color and iconography communicate state, not decoration (see **Design language**).

## Missing ≠ zero

This is a hard invariant across every workspace:

- **Missing data, no collection yet, or a metric the platform does not report** must render as an explicit "no data" / "not collected" / "unknown" state.
- **Zero** is only ever shown when the underlying Evidence genuinely reports a zero (e.g. zero conversions this period from real data).
- Never coalesce `null`/`undefined`/"not yet collected" into `0`, `0%`, or an empty chart line that looks like a legitimate zero trend.
- Aggregates (sums, averages, rates) must be marked when built from a **partial** or **truncated** data set, and must not silently present partial coverage as complete.
- This rule applies to KPIs, table cells, chart series, and Data Health summaries alike.

## Platform attribution vs verified business outcomes

Operator workspaces must visually and textually separate two categories of number:

| Category | Examples | Treatment |
| --- | --- | --- |
| **Platform-attributed signal** | Meta/Google "results", platform leads, platform purchases, platform-reported conversions | Labeled explicitly as platform-attributed; never implied to equal revenue, qualified leads, or profit |
| **Verified business outcome** | CRM-confirmed leads, confirmed sales, human-verified Outcomes | Only shown when backed by actual business/CRM Evidence; never inferred from platform data alone |

- A workspace must never present a platform metric using business-outcome language ("Sales: 42") without the qualifying source. Correct framing is closer to "42 platform-attributed results" with the platform named.
- Funnel or outcome visuals that imply a business result (qualified lead, verified sale) require real CRM/business Evidence backing that specific figure. Absent that Evidence, the workspace shows the platform-only funnel and stops — it does not extrapolate a fake business stage.
- This is the same distinction already enforced in `PROJECT_MEMORY.md` ("Platform / provider signal" table) and `docs/product/meta-ads/META_ADS_INTELLIGENCE.md` ("Critical product rules"); this standard makes it a **UI-layer requirement**, not only a data-modeling rule.

## Operator language, not internal jargon

The operator-facing workspace must never require the operator to understand or see internal domain vocabulary such as **Run**, **Evidence**, **ExternalResource**, or **CoreAssetBinding**.

- Internal terms translate to operator language: a Run becomes "last sync" / "last collection"; Evidence becomes "data collected on `<date>`"; a binding becomes "connected account".
- Timestamps, freshness, and health are expressed in operator terms ("Updated 3 hours ago", "Last synced today at 09:14"), not raw model field names.
- This mirrors the existing convention in `docs/product/integrations/WORKSPACE.md` ("Operators should not think in CoreIntegration / ExternalResource / credential payload terms") extended to the analytics/intelligence workspace, not only the connection/settings surface.
- Internal terminology remains fine in code, docs, and admin/debug surfaces — the constraint is scoped to the operator-facing workspace screens.

## Design language

- **Calm, professional.** No gradients-as-decoration, no marketing-site styling, no gamification (badges/streaks/confetti). This is a working tool for agency staff, not a consumer growth app.
- **Semantic color only.** Color communicates meaning (success/warning/danger/neutral/info), never brand flourish or arbitrary variety. A red number always means "needs attention"; it is never used as a random accent.
- Reuse the existing Filament theme tokens/status conventions already used across the app (e.g. the status vocabulary in `docs/product/integrations/WORKSPACE.md`: Connected / Configured / Needs attention / Not configured / Disabled) rather than inventing a parallel palette per module.
- Typography and spacing stay consistent with the existing Filament app shell; workspaces do not introduce a bespoke visual system.

## Chart quality standard

- Every chart must be labeled with the **named question it answers** (e.g. "How is spend trending over the period?" — not "Spend chart").
- Prefer line/bar/table representations for time series and comparisons.
- **No decorative pie charts, donut charts, or maps.** If a breakdown genuinely needs a share-of-total visual (e.g. Result Mix composition across a small number of categories), prefer a simple horizontal bar or stacked bar with labeled values over a pie/donut.
- Charts must degrade honestly when data is partial (see **Missing ≠ zero**) — no interpolated or smoothed lines across missing periods without a visible gap or annotation.
- No animated chart flourishes that exist purely for visual delight.

## Desktop primary, responsive required

- Primary design target is **desktop** (agency staff work at a desk with a full-width Filament panel). Information density and multi-column layouts should assume desktop first.
- The workspace must still be **usable** (not necessarily equally dense) on tablet and narrow viewports: no horizontal-scroll-only tables with no fallback, no controls that become unreachable below a breakpoint.
- Responsive behavior follows the existing app-wide breakpoint conventions already used elsewhere in the product (e.g. the Integrations card grid: 3 → 2 → 1 columns by breakpoint in `docs/product/integrations/WORKSPACE.md`).

## Cross-module reuse without homogenizing metrics

This standard defines **shape**, not shared metrics:

- Meta Ads, Google Ads, Website, and GBP workspaces all follow GLANCE → EXPLORE → DECIDE → DEEP DATA, the same disclosure rules, the same Missing ≠ zero rule, and the same platform-vs-outcome distinction.
- They do **not** share a forced common KPI set, a common chart type, or a common taxonomy. Meta CTR and Google Search CTR are not treated as interchangeable just because both are called "CTR" (same caution already recorded in `PROJECT_MEMORY.md`'s Benchmark Cohort section).
- Each module's blueprint (e.g. `META_ADS_EXPERT_WORKSPACE.md`) defines its own GLANCE KPI set, its own EXPLORE breakdowns, and its own DECIDE surfaces, informed by this standard's shape but not forced into another channel's structure.
- A future **Cross-Asset / Digital Operations Analyst** workspace (see `docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md`) may eventually sit above these per-channel workspaces; this standard does not attempt to pre-build that comparison layer.

## Explicit dependencies

| Dependency | Why this standard needs it | Current state |
| --- | --- | --- |
| `OPERATOR_ASYNC_EXECUTION.md` | GLANCE freshness ("last sync"), sync/refresh actions, and any long collection triggered from a workspace must follow the async standard (queued, non-blocking, visible status) rather than blocking the operator's browser tab | **IMPLEMENTED** (#121); Meta history import / Refresh use Activity Center |
| `MOXDOP_DESIGN_SYSTEM.md` | Shared semantic tokens and Blade primitives for workspace chrome | **IMPLEMENTED** (foundation); Meta first full consumer |
| Operational Data Foundation / Historical Performance Store | EXPLORE-level performance-over-time, multi-period comparison, and any real trend chart require normalized historical facts, not single-Run Evidence snapshots | **Meta slice IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED**; Google Ads warehouse **PLANNED** |
| Operational Taxonomy / Marketing Initiative (future) | Cross-campaign / cross-channel grouping views referenced as future EXPLORE breakdowns | **PLANNED**; not implemented |

For Meta, covered date ranges read the local historical store (Analyze not required when coverage exists). Reach/Frequency use exact-period non-additive cache. Until a module has a historical store, it must show an honest "not enough history yet" state — never a fabricated trend.

## Implementation posture

- **No new frontend/JS packages or chart libraries.** Prefer Filament 5 native widgets, tables, infolists, drawers, plus `MOXDOP_DESIGN_SYSTEM.md` Blade primitives.
- Meta Expert Workspace is the first full application of this standard + Design System; other modules migrate in scoped follow-ups.
- Do not mark Meta Workspace **DONE** without operator visual acceptance.

## Explicit non-goals

- Does not force Google Ads / Website / GBP redesign in the Meta milestone.
- Does not add a second design-token system per module.
- Does not define per-channel metrics, KPI names, or Evidence shapes — those live in module blueprints.
- Does not build Operational Taxonomy or Cross-Asset comparison layer in this standard.
- Does not change any existing Filament panel, guard, or module registry decision.
- Is not a BI/analytics-platform clone (Looker/Databox-style); see `docs/product/DASHBOARD.md`'s existing "no chart theater" principle, generalized here to per-asset workspaces.

## References (patterns studied, not adopted)

The following products were reviewed **only** to extract information-architecture patterns (navigation shape, disclosure ordering, filter-bar conventions). None of their branding, visual identity, copy, or proprietary UI is reproduced here or intended for implementation. They are references, not architecture:

| Reference | Pattern extracted |
| --- | --- |
| Meta Ads Manager | Campaign → Ad Set → Ad drill-down hierarchy; persistent filter bar above a data table |
| Porter Metrics | Client-facing report grouping by outcome rather than raw platform taxonomy |
| AgencyAnalytics | Multi-channel agency workspace switching without homogenizing per-channel metrics |
| Databox | Compact KPI-tile GLANCE layer before deeper drill-in |
| Supermetrics | Cross-platform metric normalization caution (surface, not blindly merge, incompatible metrics) |
| Kodalogic (visual reference) | Calm, low-chrome dashboard layout and progressive disclosure spacing |

As with all external references tracked in `PROJECT_MEMORY.md`, these remain **research inputs only**; no vendoring, no branding reuse, no automatic architecture adoption.

## Acceptance intent

A future implementation PR can point to this document and say "GLANCE/EXPLORE/DECIDE/DEEP DATA, Missing ≠ zero, platform-vs-outcome, and no-decorative-charts were followed" without re-deriving the model per module, while each module blueprint still owns its own concrete metrics and screens.
