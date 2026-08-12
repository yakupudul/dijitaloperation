# MoxDOP Design System

> **Status: IMPLEMENTED (foundation) — Meta Ads is the first full consumer.**  
> This document defines the MoxDOP visual system: semantic color tokens, card/table/filter/navigation standards, and a reusable Blade component inventory under `resources/views/components/moxdop/`.  
> **MoxDOP has one global Design System** — modules must not invent parallel token sets.  
> **Meta Ads Expert Workspace** (`docs/product/META_ADS_EXPERT_WORKSPACE.md`) consumes this system on PR #122 (**IMPLEMENTED / TESTED / USER VISUAL UAT REQUIRED**). Other modules (Brand, Google Ads, Website, GBP, Findings/Recommendations/Tasks, Activity) are **NOT redesigned in this milestone** — they migrate in scoped follow-ups.  
> Governed by `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md` (information architecture, GLANCE→EXPLORE→DECIDE→DEEP DATA, Missing ≠ zero, platform-vs-outcome). This document is the **visual/token layer** underneath that standard — it does not change navigation, data, or product behavior.  
> Related: `docs/product/META_ADS_EXPERT_WORKSPACE.md`, `resources/css/filament/app/theme.css`.

## Purpose

MoxDOP today renders Brand, Meta Ads, Google Ads, Website, GBP, Findings, Recommendations, Tasks, and Activity as separate surfaces that grew organically (Filament defaults plus ad hoc `.mox-*` classes added per feature). This document defines **one coherent visual system** — semantic tokens, card/table/filter/navigation standards, dark mode, empty states, and operation-progress patterns — so every module can converge on the same look and feel without re-deriving component design per surface.

This is a **foundation** milestone: tokens, utility classes, and reusable Blade primitives, proven first on Meta. It does not redesign Google, Website, or GBP pages in this PR.

## Status

**IMPLEMENTED (foundation + Meta consumer):**

- Semantic CSS tokens in `resources/css/filament/app/theme.css` (`--mox-primary`, `--mox-result`, `--mox-traffic`, `--mox-efficiency`, `--mox-warning`, `--mox-critical`, `--mox-neutral`, soft variants, card tokens).
- Reusable utility classes (`.mox-card`, `.mox-kpi-card`, `.mox-status-pill`, `.mox-section-card`, `.mox-filter-bar`, `.mox-empty-state`, `.mox-operation-progress`, `.mox-entity-table`, `.mox-attention-card`, `.mox-chart-card`).
- Anonymous Blade component library under `resources/views/components/moxdop/`.
- **Meta Ads Expert Workspace** uses this system as the first full consumer (operator visual UAT still required before Meta Workspace DONE).

**NOT implemented in this milestone:**

- No existing Brand, Google Ads, Website, GBP, Findings, Recommendations, Tasks, or Activity view has been rewritten to use these components.
- No new frontend package, chart library, or build tool. Everything is Tailwind CSS v4 (`@theme`) + plain CSS custom properties + Blade, consistent with `docs/product/OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s "no new design system package" posture.

## Design direction

MoxDOP's visual identity sits deliberately between three things it is **not**:

| Is NOT | Why |
| --- | --- |
| A gray Filament admin-panel look | Filament's default gray/zinc palette reads as generic back-office CRUD, not an agency operations product with its own identity. |
| A rainbow analytics dashboard | Multi-color charts-for-the-sake-of-color (BI-tool style) create visual noise and defeat "one number, one meaning" semantic color. |
| A Meta/Google Ads Manager clone | Ads Manager patterns (drill-down hierarchy, persistent filter bar) are studied as **information-architecture references only** (see `OPERATOR_WORKSPACE_DESIGN_STANDARD.md` "References"); MoxDOP does not copy their branding, chrome, or visual density. |

Instead, MoxDOP targets:

- **Google Ads-like clarity** — calm surfaces, generous whitespace, one clear number per region, tables that are scannable at a glance.
- **Modern operator usability** — compact filter bars, sticky table headers, honest empty/loading states, progress feedback for async operations (`docs/product/OPERATOR_ASYNC_EXECUTION.md`).
- **MoxDOP identity** — a single warm **orange accent** (`--mox-primary`) used sparingly for primary actions and brand touches, on top of a neutral slate/white (light) or slate/near-black (dark) base. Provider brand colors (Meta blue, Google multicolor, etc.) appear only as small identity marks (logos, initials, tiny badges) — **never** as a KPI's semantic color.

## Semantic tokens

Color communicates **meaning**, not decoration or brand flourish (this repeats and does not relax `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s "semantic color only" rule). Seven semantic families cover every use case across every module:

| Token | Family | Hue | Meaning | Typical use |
| --- | --- | --- | --- | --- |
| `--mox-primary` | PRIMARY / ACTION | Orange | MoxDOP identity, primary actions, spend-family KPIs | Primary buttons, active nav state, "Spend" KPI accent, brand touches |
| `--mox-result` | RESULT / POSITIVE | Green | Good outcomes, positive deltas, healthy status | Result-family KPIs, "Active"/"Good" status pills, positive delta arrows |
| `--mox-traffic` | TRAFFIC / DELIVERY | Blue | Volume/delivery signals (impressions, reach, clicks) | Traffic-family KPIs, delivery-flow steps, informational badges |
| `--mox-efficiency` | EFFICIENCY / RATE | Violet | Rate/efficiency metrics (CTR, CPC, CPM, cost-per-result) | Efficiency-family KPIs, rate badges |
| `--mox-warning` | WARNING | Amber | Needs attention, degraded, partial data | "Needs attention" status, partial Data Health, medium-severity Findings |
| `--mox-critical` | CRITICAL | Red | Broken, failed, high/critical severity | Failed sync, critical/high-severity Findings, negative deltas |
| `--mox-neutral` | NEUTRAL | Slate | Inactive, archived, unknown, no-signal | Paused/archived status, disabled controls, secondary text |

Each token has a **soft** variant (`--mox-primary-soft`, `--mox-result-soft`, `--mox-traffic-soft`, `--mox-efficiency-soft`, `--mox-warning-soft`, `--mox-critical-soft`, `--mox-neutral-soft`) — a low-opacity tint of the same hue, used for pill/badge backgrounds and thin card accents. **Soft variants exist specifically so a KPI card family accent never becomes a giant saturated background** (see Rules below); the solid token is reserved for text, icons, borders, and small indicators, not large fill areas.

All tokens are defined once in `@theme` (base hex, Tailwind-theme-visible) and re-exposed as `--mox-*` custom properties on `.fi-body` / `.dark .fi-body` so every token automatically has a light and dark value without any component needing its own `dark:` variant.

### Provider colors — identity only

Meta blue, Google's four-color mark, GBP's palette, etc. are permitted **only** as small identity marks: a provider logo/initial badge (e.g. `.mox-brand-logo`, `.mox-integration-card__mark`), never as:

- a KPI value or chart line color (those use the semantic families above),
- a status pill color (status uses semantic tone, not provider brand),
- a large background fill.

This keeps every workspace visually consistent even though it represents a different ad/data provider underneath.

## Card standard

All elevated surfaces (`.mox-card` and everything built on it — KPI cards, section cards, chart cards, attention cards) share three tokens:

| Token | Purpose | Value (light) |
| --- | --- | --- |
| `--mox-card-radius` | Corner radius | `0.75rem` |
| `--mox-card-shadow` | Elevation | `0 1px 2px rgba(15, 23, 42, 0.05), 0 1px 1px rgba(15, 23, 42, 0.03)` |
| `--mox-card-padding` | Inner padding | `1rem` |

A card is: `1px solid var(--mox-border)` + `background: var(--mox-surface)` + the three tokens above. Dark mode overrides only the shadow (flatter, less visible on dark backgrounds) — border/surface already switch via the existing `.dark .fi-body` block.

## KPI card standard

A KPI card (`.mox-kpi-card`) is a `.mox-card` plus:

- a **label** (uppercase, small, muted) — never colored by family,
- a **value** (large, bold, `--mox-text` — never colored by family; color communicates status, not raw magnitude),
- an optional **delta** (`<x-moxdop.metric-delta>` — up/down/flat, colored `--mox-result` / `--mox-critical` / `--mox-neutral`, hidden entirely when no valid comparison exists per Missing ≠ zero),
- an optional **hint** (small muted caption, e.g. "Cost / result 12.40"),
- a **family accent**: a thin (3px) top border in the family's solid color, driven by a modifier class (`.mox-kpi-card--spend`, `.mox-kpi-card--result`, `.mox-kpi-card--traffic`, `.mox-kpi-card--efficiency`). This is the entire family styling — **no colored background fill** (see Rules).

## Table standard

`.mox-entity-table` wraps the existing `.mox-table` markup with:

- a scrollable body and a **sticky header** (`position: sticky; top: 0`) so column labels stay visible while scanning long entity lists (Campaigns → Ad Sets → Ads, Findings, Tasks, Activity),
- row hover state using `--mox-primary-soft` (a soft, not saturated, hover tint),
- numeric columns right-aligned with tabular figures (`.mox-num`, already established).

This is additive to the existing `.mox-table`/`.mox-table-wrap` classes already used by Meta Ads workspace views — it does not replace them.

## Filter standard

`.mox-filter-bar` is the compact, cross-module filter toolbar shape: a single-row (wrapping) flex bar with small labeled controls, low-chrome background (`--mox-surface-subtle`), and no more vertical padding than a table's own header. It is a generalized version of the existing `.mox-meta-filter-bar` (Meta Ads keeps its own class for now; both render visually consistently since they share the same underlying tokens). Filters never dominate a screen — per `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`, **data before filter chrome**: the filter bar is compact and secondary to the KPIs/tables it controls, never a large hero element.

## Navigation standard

- Primary navigation (module tabs, e.g. Overview / Campaigns / Creatives / Insights) uses `--mox-primary` for the active state indicator only (underline or left-bar accent), never a filled colored tab.
- Secondary/settings navigation (Connection / Sync / Data Health) is visually quieter (muted text, no accent) — consistent with `META_ADS_EXPERT_WORKSPACE.md`'s primary/secondary tab split.
- `<x-moxdop.workspace-header>` standardizes the identity block every workspace needs at the top: brand, account/asset identity, secondary meta line (business, currency, last sync, history coverage), and an actions slot — so every module's workspace header has the same shape even though the content differs per channel.

## Dark mode

Every token is a CSS custom property scoped under `.fi-body` (light) and `.dark .fi-body` (dark) — the existing Filament dark-mode convention already in `theme.css`. Components never hardcode a light or dark color; they reference `var(--mox-*)`. This means:

- Every new utility class and every `moxdop.*` Blade component is dark-mode-correct automatically, with zero `dark:` Tailwind variants needed in the component markup.
- Soft variants (`color-mix(in srgb, var(--mox-x) …%, transparent)`) are computed against the token's current-mode value, so they stay visually consistent (a light tint in light mode, a comparably subtle tint in dark mode) without a separate dark-mode soft color.

## Empty states

`<x-moxdop.empty-state>` standardizes "nothing here" across modules: an icon/mark, a short title, a one-sentence body, and an optional action slot. Per `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s Missing ≠ zero rule, empty states must say **why** there's nothing (no data collected yet vs. genuinely zero vs. filtered out) rather than rendering a bare blank area or a fabricated zero.

## Operation progress

`<x-moxdop.operation-progress>` renders the visible state of an async operation (`docs/product/OPERATOR_ASYNC_EXECUTION.md` states: `queued` / `running` / `completed` / `partial` / `failed` / `cancelled`): a title, current phase, one or more progress rows (label + done/total), elapsed time, and a status pill. It exists so every module's "collection running" surface (Meta sync, Google sync, Website diagnosis, GBP sync) looks and behaves the same, instead of each module inventing its own progress UI.

## Component inventory

All components are **anonymous Blade components** under `resources/views/components/moxdop/`, used as `<x-moxdop.NAME>`. No PHP view-component classes were introduced — anonymous components are sufficient and preferred per the task's guidance.

| Component | Path | Props (defaults) | Purpose |
| --- | --- | --- | --- |
| Page header | `resources/views/components/moxdop/page-header.blade.php` | `title`, `subtitle=null` + `actions` slot | Generic page-level heading used by non-workspace pages. |
| Workspace header | `resources/views/components/moxdop/workspace-header.blade.php` | `brand=null`, `account=null`, `business=null`, `currency=null`, `lastSync=null`, `historyCoverage=null` + `actions` slot | Standardized per-asset workspace identity block (Navigation standard). |
| Filter bar | `resources/views/components/moxdop/filter-bar.blade.php` | `label='Filters'`, `compact=true` + default slot | Compact toolbar wrapper (Filter standard). |
| KPI card | `resources/views/components/moxdop/kpi-card.blade.php` | `label`, `value`, `delta=null`, `hint=null`, `family='spend'` + optional `sparkline` slot | Single KPI tile (KPI card standard). |
| Metric delta | `resources/views/components/moxdop/metric-delta.blade.php` | `value=null`, `direction='na'`, `positiveIsGood=true` | Colored up/down/flat delta indicator; renders nothing when `direction='na'` (Missing ≠ zero). |
| Status pill | `resources/views/components/moxdop/status-pill.blade.php` | `label`, `tone='neutral'` | Small rounded status indicator (`active`/`paused`/`attention`/`ok`/semantic tones). |
| Section card | `resources/views/components/moxdop/section-card.blade.php` | `title=null`, `description=null` + default slot | General-purpose bordered card with an optional header. |
| Chart card | `resources/views/components/moxdop/chart-card.blade.php` | `title`, `description=null` + `toolbar` slot + default (body) slot | Chart container with a named-question title and small toolbar row. |
| Empty state | `resources/views/components/moxdop/empty-state.blade.php` | `title`, `body=null`, `icon=null`, `compact=false` + `action` slot | Standardized empty/"no data yet" surface. |
| Data health badge | `resources/views/components/moxdop/data-health-badge.blade.php` | `label='Data Health'`, `tone='neutral'`, `wireClick=null` | Compact clickable badge that opens a Data Health drawer/modal. |
| Operation progress | `resources/views/components/moxdop/operation-progress.blade.php` | `title`, `phase=null`, `rows=[]`, `elapsed=null`, `status='queued'` | Async operation progress surface (Operation progress). |
| Attention card | `resources/views/components/moxdop/attention-card.blade.php` | `severity='low'`, `title`, `body=null`, `entity=null` + `action` slot | Single "what needs attention" entry (Finding/Recommendation surfaced inline). |
| Result card | `resources/views/components/moxdop/result-card.blade.php` | `family='contact'`, `label`, `value`, `cost=null` | Single platform-attributed result-mix entry (contact/conversion vs. traffic/engagement family). |
| Entity table | `resources/views/components/moxdop/entity-table.blade.php` | `sticky=true` + default slot | Sticky-header table wrapper (Table standard). |

## Rules

- **Missing ≠ zero.** Every component that renders a number (`kpi-card`, `metric-delta`, `result-card`) must be given an explicit "no data" state by its caller (e.g. `value=null` renders `—`, `direction='na'` renders nothing) — a component never silently coerces a missing value to `0` or a fabricated `0%` delta. This is the same invariant as `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`; the design system only supplies the rendering primitives, it does not decide when data is missing.
- **No giant saturated KPI backgrounds.** Family color on a KPI card is a thin 3px top accent plus (optionally) a soft-tint icon chip — never a full-saturation colored card background. A wall of solid-colored tiles reads as decoration, not data (`OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s "no decorative dashboards").
- **Data before filter chrome.** Filter bars (`.mox-filter-bar`, `<x-moxdop.filter-bar>`) stay compact and visually subordinate to the KPIs/tables below them. A filter bar never grows into a hero section, never duplicates the same filter across tabs (single persistent filter bar per workspace, per `META_ADS_EXPERT_WORKSPACE.md`), and never blocks the data behind a "configure filters first" step.
- **No decorative charts.** Charts built inside `<x-moxdop.chart-card>` must be labeled with the operator question they answer (the `title` prop), never a generic "Chart" label — same rule as `OPERATOR_WORKSPACE_DESIGN_STANDARD.md`'s chart-quality standard.
- **Semantic color only.** A component's color always maps to one of the seven families above; provider brand colors never substitute for a semantic tone (see "Provider colors — identity only").
- **Additive, not destructive.** Existing `.mox-*` classes already used by Meta Ads workspace views, the Website workspace, the Integrations hub, and other shipped surfaces are untouched by this milestone. New utility classes and components live alongside them; nothing was renamed or deleted.

## Explicit non-goals (this milestone)

- Does not redesign any existing Brand, Google Ads, Website, GBP, Findings, Recommendations, Tasks, or Activity screen.
- Does not redesign any existing Meta Ads workspace tab (Overview / Campaigns / Creatives / Insights / Intelligence / Connections / Activity) — those keep their current `.mox-meta-*` markup; adopting the new components there is a later, separately scoped phase.
- Does not add any JS chart library, icon package, or CSS framework beyond the already-installed Tailwind CSS v4 (`@tailwindcss/vite`).
- Does not introduce PHP view-component classes; all components are anonymous Blade views.
- Does not change Filament panel configuration, guards, or the module registry.

## Acceptance intent

A later phase can point to this document and `resources/views/components/moxdop/*` to build or refresh a module's workspace (starting with Meta Ads, per `META_ADS_EXPERT_WORKSPACE.md`) using shared tokens and primitives, instead of inventing new `.mox-*` classes or ad hoc inline styles per screen.
