# Global Agency Operating Layer — Product Completeness Review

Status: Demo / presenter milestone (no provider expansion, no warehouse, no second panel).

## Canonical surface

- Operator product: `/app` (TailAdmin Livewire Demo Mode)
- System/admin: `/system` (Filament) — not duplicated as a second operator shell
- Navigation groups: Menu · Portfolio · Operations · System
- Modules are developer architecture — not operator navigation

## Brand legacy fields vs Brand Intelligence Context

| Field / concept | Legacy Brand form columns | Brand Business Context | Canonical UX |
| --- | --- | --- | --- |
| Description / summary | `description` | `business_summary` | Business Context |
| Audience | `audience` | `target_audiences` | Business Context |
| Offerings | `offerings` (free text) | `products_services` / `priority_offerings` | Business Context |
| Competitors | `competitors` | `known_competitors` | Business Context |
| Markets | `target_markets` | `target_markets` | Business Context |
| Goals / positioning | (limited) | `business_goals`, `positioning`, `differentiators` | Business Context |

Decision: **Brand Intelligence Context is the canonical strategic business truth.**  
Legacy columns remain for backward compatibility; Brand edit form labels them as legacy and does not encourage duplicate authoritative editing. No destructive migration in this milestone.

## Task scope gap

Eloquent `Task` currently fillable-requires relational scope including `customer_id`, `brand_id`, and `digital_asset_id`.

Product need:

- Customer-scoped tasks (e.g. request legal document)
- Brand-scoped tasks (e.g. update positioning)
- Digital Asset-scoped tasks (current default)

Demo Mode illustrates a Brand-scoped task (`t-brand-positioning`) with `scope_level = brand` and null asset. Production schema migration remains deferred — do not rush polymorphic redesign solely for Demo UI.

## Activity Event gap

Runs capture technical execution. Human operational actions (Finding acknowledged, Recommendation accepted, Task completed, resource bound) are partially demo/presenter today via `GlobalOperatingFixtures::activityTimeline()`.

Future: lightweight append-only **Activity Event** for meaningful operator/system actions only (not page views / hovers / trivial updates).

## Semantic backbone (deferred)

Not normalized in this milestone:

1. Brand Offering (stable id)
2. Brand Audience / Persona (stable id)
3. Brand Business Action (business meaning ≠ provider event name)
4. Brand Location
5. Asset Relationship (distinct from provider Binding)

Demo relationship presenters continue to use existing fixture architecture.

## Historical performance store (deferred)

Custom date / trends / comparisons in specialist workspaces use deterministic Demo fixtures. Production arbitrary ranges, previous-period comparison, fatigue, pacing history require a normalized historical facts store — prerequisite documentation only; warehouse not built here.

## Digital Asset types

First-class Demo types include: `website`, `gbp`, `google_ads`, `meta_ads`, `ga4`, `gsc`, plus infrastructure `domain` / `hosting`.  
Canonical keys remain `ga4` and `gsc` (no parallel `google_analytics` alias).

Provider identity stays on bindings / external resources — not dumped onto the generic Digital Asset table.

## Responsibility

- Customer: `responsible_user_ids` + Account Owner UX
- Brand: existing `responsibleUsers` pattern
- Digital Asset: Demo responsible users via `GlobalOperatingFixtures::enrichAsset`

Responsibility ≠ permission.
