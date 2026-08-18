# RECOMMENDATION SOURCE ARCHITECTURE

## STATUS: PASS

**Prompt:** 41
**Date:** 2026-08-14
**Branch:** `cursor/recommendation-source-architecture-ea01`
**Base:** Prompt 40 HEAD `6e5b4d4` (Opportunity Production Persistence & Detection)

---

## 1. Purpose

A Recommendation is the first prescriptive object in MoxDOP: it says *what a human should consider doing*. Prompt 41 makes its provenance explicit and dual-sourced.

```text
Finding  ─┐
          ├─→ exactly one source (XOR) → Recommendation (persistent, operator-decided)
Opportunity ─┘
```

Before Prompt 41 a Recommendation could only hang off a Finding (`finding_id NOT NULL`, cascade delete). Prompt 40 created the Opportunity domain but deliberately created no Recommendations. Prompt 41 evolves the one canonical `recommendations` table so that a Recommendation is sourced by **exactly one** Finding **or** exactly one Opportunity, never both, never neither, and never by a fabricated placeholder Finding.

## 2. Frozen Recommendation Product Audit

The frozen `/app` Operations Recommendations card presents: priority badge, Digital Asset mark, status badge, AI-assisted badge, title, brand · asset line, "Why surfaced", effort, evidence provenance, expandable recommended action, source line (Finding or Opportunity), evidence summary, and the Accept / Defer / Dismiss / Create Task action row. Prompt 41 keeps that layout and only changes where the data comes from (DB instead of `DemoState`) plus adds an origin badge. See "Frozen Recommendation Migration Matrix".

## 3. Existing Recommendation Primitive Audit

See "Existing Recommendation Audit Matrix". One `App\Models\Recommendation` + `recommendations` table already existed (Prompt 4 era) with `finding_id NOT NULL` + `cascadeOnDelete`, `digital_asset_id`, `source_module`, `title`, `action`, `rationale`, `priority`, `effort`, `status`. `source_module` is the **module id** (`website-diagnosis`, `cross-asset-*`, `website-ai-insights`, …) and is kept with that meaning — it is *not* overloaded as source kind.

## 4. Canonical Recommendation Decision

**EVOLVE** the existing `recommendations` table. No `RecommendationV2` / `ProductionRecommendation` / `CanonicalRecommendation` — test-asserted absent. No `sourceable_type` / `sourceable_id` unrestricted `morphTo`: the polymorphism is closed and typed by `source_kind` plus two real, indexed, foreign-keyed columns.

## 5. Source Model

| Column | Meaning |
|---|---|
| `source_kind` | `finding` or `opportunity` (`App\Enums\RecommendationSourceKind`) |
| `finding_id` | set iff `source_kind = finding` |
| `opportunity_id` | set iff `source_kind = opportunity` |

`Recommendation::sourceKind()`, `isFindingSourced()`, `isOpportunitySourced()`, `sourceId()` read it; `finding()` and `opportunity()` are ordinary `BelongsTo` relations.

## 6. Why Not morphTo

An unrestricted `morphTo` would allow any future model (Evidence, Run, Task, a Demo fixture) to become a Recommendation source without review, cannot be foreign-keyed, and cannot be checked by the database. The closed two-column design keeps referential integrity, real indexes, a DB `CHECK` on PostgreSQL, and an explicit code review gate for any third source kind.

## 7. Why Not source_module Overloading

`source_module` answers "which module produced this row" and is already used for dedup keys (`firstOrNew([finding_id, source_module])`), AI-vs-deterministic provenance, and workspace filtering. Overloading it with `finding` / `opportunity` would break every existing writer and conflate two orthogonal facts. It is unchanged.

## 8. XOR Invariant

Exactly one source. Enforced at four levels:

1. `RecommendationSourceReference` — cannot be constructed with both or neither.
2. `RecommendationSourceGuard::normalize()` on the model `saving` event — derives `source_kind` from the keys when a legacy writer omits it, then asserts.
3. `RecommendationSourceGuard::assertConsistent()` — rejects both-set, neither-set, and kind/key mismatch.
4. PostgreSQL `CHECK` constraint `recommendations_source_xor_check`.

## 9. Database Constraint Strategy

The `CHECK` is added only where the driver supports altering an existing table to add one (PostgreSQL, the production driver). SQLite (test driver) cannot `ALTER TABLE … ADD CONSTRAINT CHECK`; there the application guard is authoritative and is directly test-asserted. MySQL/MariaDB fall back to the application guard as well.

## 10. Migration

`2026_08_14_160000_add_recommendation_source_architecture_columns.php`, additive and idempotent (`Schema::hasColumn` / named-index guards), in this order:

1. add `source_kind`, `opportunity_id`, `origin`, `idempotency_key`;
2. add indexes `source_kind`, `opportunity_id`, `(source_kind, finding_id)`, `(source_kind, opportunity_id)` and the unique index on `idempotency_key`;
3. backfill `source_kind='finding'`, `origin=COALESCE(origin,'legacy')` for every row with a `finding_id`;
4. drop the `finding_id` foreign key, make `finding_id` nullable, re-add it as `restrictOnDelete`, add `opportunity_id → opportunities` as `restrictOnDelete`;
5. add the PostgreSQL XOR `CHECK`.

`down()` reverses in mirror order. No `migrate:fresh`, no data loss, no table rename.

## 11. Delete Semantics

Source deletion no longer deletes the Recommendation: both source foreign keys are `RESTRICT`. A Recommendation is an operator-facing decision record and a Task's provenance anchor; silently vaporising it when a Finding is cleaned up would erase decision history. Deleting a Finding or an Opportunity that still has Recommendations raises a database error (test-asserted) — the caller must deal with the Recommendations explicitly.

## 12. Lifecycle Independence

Resolving a Finding or closing/dismissing an Opportunity does **not** change, close, or delete its Recommendations. Recommendation status is a human decision (`open` → `accepted` / `dismissed` / `converted`) and moves only through `UpdateRecommendation`.

## 13. Status Model

Canonical statuses stay `open`, `accepted`, `dismissed`, `converted` (`Recommendation::STATUSES`). Prompt 41 adds no `deferred` status: the Demo UI's "Defer" is a review posture and now leaves the row `open` with an explicit flash, rather than inventing a fifth persisted state. Demo's `approved`/`rejected` vocabulary is retired from the production surface (`approve → accepted`, `reject → dismissed`).

## 14. Origin

`origin` (`App\Enums\RecommendationOrigin`) records *how* a row came to exist, orthogonally to its source: `operator`, `deterministic_template`, `legacy`, `ai_future`. `ai_future` is reserved and unused — Prompt 41 activates no AI generation path.

## 15. Idempotency

`idempotency_key` is a nullable unique string. `CreateRecommendation` returns the existing row when the key is already present (before writing), and also recovers from a concurrent unique-violation by re-reading the winner. The Opportunity conversion flow uses `opportunity-convert:{opportunity_id}`, making a double click a no-op instead of a duplicate.

## 16. Source Resolution

`RecommendationSourceResolver::resolve()` loads the Finding or Opportunity with `brand`, `customer`, `digitalAsset` and `latestEvaluation.evidence`, and returns a `ResolvedRecommendationSource` (model + `RecommendationSourceViewData`). A missing source is a `ValidationException`, never a silent null. It never reads Demo fixtures and never mutates the source.

## 17. Server-Side Trust

Callers pass a `RecommendationSourceReference`, not a source payload. Title/action/rationale/priority/effort/status are validated; `digital_asset_id` defaults from the resolved source; `source_module` defaults from the Finding's module or `operations` for an Opportunity. A client cannot inject brand, customer, evidence counts, or rule ids.

## 18. Tenant Safety

`RecommendationSourceGuard::assertTenantMatch()` rejects a `digital_asset_id` whose Brand differs from the source's Brand (test-asserted cross-brand denial). No silent rewriting to the "correct" asset.

## 19. Creation Services

- `CreateRecommendation` — the single production writer: idempotency → validation → source resolution → tenant guard → transactional insert + Activity.
- `CreateRecommendationFromFinding` / `CreateRecommendationFromOpportunity` — thin, intention-revealing wrappers.

## 20. What Creation Never Does

No Task, Work item, Approval, Client Request, Playbook, Business Outcome, Service Scope, Goal, or Offering is created. No provider HTTP call, no AI route call. Test-asserted (`Task::count()` stays 0, `Http::assertNothingSent()`).

## 21. Update Service

`UpdateRecommendation` accepts only `title`, `action`, `rationale`, `priority`, `effort`, `status`, and throws when `source_kind`, `finding_id`, `opportunity_id` or `idempotency_key` appear in the payload.

## 22. Source Immutability / Relink

Relinking a Recommendation to another source is **not supported in V1**. There is no relink service and no update path that can change the source. If a Recommendation is attached to the wrong source, the correct operation is to dismiss it and create a new one from the right source, which preserves decision history.

## 23. Activity

`RecommendationActivityRecorder` writes `RECOMMENDATION_CREATED`, `RECOMMENDATION_UPDATED`, `RECOMMENDATION_STATUS_CHANGED` to `brand_context_activities`, resolved to a Brand via the Digital Asset or the source. Deterministic content refreshes by the upsert writers do not write Activity — no timeline spam. A status "change" to the same status writes nothing (test-asserted).

## 24. Read Service

`RecommendationReadService` supports `source_kind`, `finding_id`, `opportunity_id`, `status` (string or list), `priority`, `origin`, `source_module`, `digital_asset_id`, `brand_id`, `customer_id`; plus `forFinding()`, `forOpportunity()`, `forBrand()`, `forCustomer()`, `forAsset()`, and `forListPresentation()` for the frozen Operations card shape.

## 25. No N+1

Sources are hydrated in batch: the read service collects distinct references and `RecommendationSourceResolver::resolveManyViewData()` issues at most one `Finding` query and one `Opportunity` query per page, each eager-loading brand/customer/asset/evaluation-evidence.

## 26. Presentation Shape

`forListPresentation()` returns `id`, `source_kind`, `finding_id`, `source_opportunity_id`, `source_title`, `source_status`, `origin`, `origin_label`, `title`, `action`, `why`, `evidence`, `status`, `priority`, `effort`, `provenance`, `ai_assisted`, `source_module`, `brand_id`, `brand`, `customer_id`, `asset`, `asset_type`, `service`, `market`, `category`, `task_ids`, `task_id`, `updated_at`. No raw Evidence payload and no score.

## 27. Existing Deterministic Writers

`WebsiteDiagnosisService::upsertRecommendationForFinding()`, `FindingLifecycleService::upsertRecommendation()` and the seven `CrossAsset*ConsistencyService` upserts keep their `firstOrNew([finding_id, source_module])` dedup key and now always set `source_kind=finding`, `opportunity_id=null`, `origin=deterministic_template`. Their behaviour, titles, and terminal-status protection are unchanged.

## 28. AI Acceptance Writers

`WebsiteAiRecommendationAcceptance`, `GoogleAdsAiRecommendationAcceptance` and `MetaAdsAiRecommendationAcceptance` already required a human to accept an AI draft. They now set `source_kind=finding` and `origin=operator` — the actor is the operator who clicked accept; `source_module` still carries the AI module id, which is what drives the "AI-assisted" provenance badge. No new AI generation path was added.

## 29. Factory

`RecommendationFactory` defaults to a Finding-backed row (`source_kind=finding`, `origin=legacy`). `forOpportunity(Opportunity $o)` sets `opportunity_id`, nulls `finding_id`, and switches kind/module/origin; `forFinding(Finding $f)` is the explicit Finding counterpart. No factory ever fabricates a Finding to satisfy an Opportunity-sourced row.

## 30. Operations UI — Opportunity Conversion

`OpportunitiesIndex::createRecommendation()` marks the Opportunity `converted` (unchanged `OpportunityDispositionService` call) and then creates exactly one Recommendation titled `Act on: {opportunity title}` with the Opportunity description as the action, `origin=operator`, and idempotency key `opportunity-convert:{id}`. It creates no Task.

## 31. Operations UI — Recommendations Index

`RecommendationsIndex` is DB-backed through `RecommendationReadService::forListPresentation()`. `approve → accepted`, `reject → dismissed`, `defer → stays open with a flash`, `createTask → flash only, no Task`. `DemoState` is used only for the flash message channel, never as a data source.

## 32. Work Boundary

The Livewire "Create Task" button deliberately creates nothing in Prompt 41; Work alignment is owned by a later Prompt. The pre-existing Filament "Create Task from Recommendation" action is a shipped product feature and is left intact.

## 33. Task Snapshot

`CreateTaskFromRecommendation` now also resolves the Digital Asset through an Opportunity source and records `source_kind` plus an `opportunity` snapshot block (`id`, `fingerprint`, `rule_id`, `rule_version`, `category`, `status`, `qualitative_priority`, `service_definition_code`, `last_detected_at`) alongside the existing `finding` block.

## 34. Filament Surface

`RecommendationResource` stays view-only (no create/edit/delete). The infolist gains `source_kind` (badge), a linked `finding_id`, `opportunity_id`, a real `origin` badge, and keeps the derived AI/Deterministic provenance entry. The table gains a `source_kind` column and a source-kind filter.

## 35. No Automatic Generation

Nothing generates Recommendations from Finding or Opportunity evaluation. No observer, no event listener, no scheduler entry was registered. `FindingEvaluationService`, `OpportunityEvaluationService` and `CanonicalEvidencePipeline` still assert their `Recommendation::count()` guard, and the Prompt 41 suite re-asserts zero Recommendations after a full Evidence → Finding → Opportunity run.

## 36. AI Boundary

Prompt 41 adds no AI generation, no prompt, no route. `RecommendationOrigin::AiFuture` exists as a reserved vocabulary item only, and no code path writes it.

## 37. Provider Boundary

No provider client is touched. `Http::assertNothingSent()` holds across the Prompt 41 creation paths.

## 38. Out-of-Scope Domains

No Client Requests, Approvals, Playbooks, Business Outcomes, or Service Scope mutation. No new base directories beyond `app/Services/Recommendations` and `app/Support/Recommendations`, which mirror the Findings/Opportunities layout.

## 39. Concurrency

Creation runs in a transaction. Two concurrent creations with the same idempotency key resolve to one row: the loser catches the unique violation and returns the winner.

## 40. Legacy Data

Every pre-existing Recommendation is Finding-sourced by construction (`finding_id` was `NOT NULL`), so the backfill sets `source_kind='finding'` and `origin='legacy'` for all of them and preserves `finding_id`, titles, statuses, and Task links. Test-asserted by rolling the migration back, inserting a legacy-shaped row, and re-migrating.

## 41. Demo Retirement

`DemoState::setRecommendationStatus()`, `DemoState::createTaskFromRecommendation()`, `DemoState::createRecommendationFromOpportunity()` and `DemoCatalog::recommendationsSeed()` are no longer read by the Operations Recommendations index. They remain in the Demo layer for residual Demo surfaces (Brand workspace attention/priorities, dashboards, specialist workspace cards) which are outside Prompt 41's scope. See "Demo Retirement Matrix".

## 42. Authorization

The Operations index runs behind the existing `/app` auth stack; the Filament resource keeps its existing policy surface and the Task action keeps its `userCanConvert` gate. Prompt 41 introduces no new permission and no public entry point.

## 43. Privacy

The read DTOs expose ids, titles, statuses, categories, rule ids, counts, and context labels — never raw Evidence payloads.

## 44. Performance

Indexes on `source_kind`, `opportunity_id`, `(source_kind, finding_id)`, `(source_kind, opportunity_id)` and the existing `finding_id` / `digital_asset_id` indexes cover the read filters. List rendering is 1 Recommendation query + 1 tasks eager-load + 1 asset/brand eager-load + at most 2 source queries.

## 45. Rollback

`php artisan migrate:rollback` restores the pre-Prompt-41 shape: Opportunity-sourced rows (which cannot exist in the old schema) are removed, `finding_id` returns to `NOT NULL` with `cascadeOnDelete`, and the four columns and their indexes are dropped.

## 46. Tests

`tests/Feature/Recommendations/RecommendationSourceArchitectureTest.php` (21 tests): no parallel Recommendation entity and no morph columns; reference XOR valid/invalid; sourceless save rejected; guard rejects both sources; Finding-sourced create; Opportunity-sourced create without fabricating a Finding; many Recommendations per source; update cannot change the source; resolving a Finding keeps its Recommendation and deleting it is restricted; closing an Opportunity keeps its Recommendation and deleting it is restricted; Evidence → Finding → Opportunity evaluation generates zero Recommendations and zero Tasks with no HTTP; cross-brand Digital Asset denied; missing source rejected; idempotency key reuse; Livewire conversion creates 1 Recommendation and 0 Tasks (double click safe); Operations list DB-backed with no Demo titles; Livewire decisions persist and never create Tasks; Activity for create/status-change only; Task snapshot carries Opportunity provenance; migration backfills legacy rows. `RecommendationMigrationAndModelTest` covers the new columns, restrict FKs, indexes, unique idempotency key and nullable `finding_id`. `CommercialGrowthIntelligenceTest`, `DemoProductRoutesTest`, `GlobalAgencyOperatingLayerTest`, `ProductVisionRecoveryTest` and `OpportunityProductionDetectionTest` were updated to the DB-backed reality.

## 47. Definition of Done

One canonical Recommendation with an explicit, enforced, single source; Opportunity-sourced Recommendations exist without fake Findings; sources are immutable and never cascade-delete their Recommendations; the Operations decision inbox is DB-backed; no Task, AI, or provider side effects anywhere in the Prompt 41 paths.

---

## 48. Existing Recommendation Audit Matrix

| Primitive | File | Semantic | Writer | Reader | Prod | Demo | Decision |
|---|---|---|---|---|---|---|---|
| `Recommendation` model | `App\Models\Recommendation` | canonical Recommendation | deterministic upserts, AI acceptance, Prompt 41 services | Filament, workspaces, read service | yes | no | EVOLVED |
| `recommendations.finding_id` | migration `2026_08_07_083933` | Finding source, `NOT NULL`, cascade | upserts | relations | yes | no | EVOLVED — nullable + restrict |
| `recommendations.source_module` | same | module id | all writers | dedup key, provenance badge | yes | no | KEPT — not overloaded |
| `recommendations.opportunity_id` | Prompt 41 | Opportunity source | Prompt 41 services | relations | yes | no | ADDED |
| `recommendations.source_kind` | Prompt 41 | XOR discriminator | all writers | filters, UI | yes | no | ADDED |
| `recommendations.origin` | Prompt 41 | how the row was created | all writers | UI badge | yes | no | ADDED |
| `recommendations.idempotency_key` | Prompt 41 | duplicate suppression | `CreateRecommendation` | lookup | yes | no | ADDED |
| `DemoCatalog::recommendationsSeed()` | `App\Support\Demo\DemoCatalog` | 3 hard-coded Demo cards | static | Demo surfaces | no | yes | DEMO_ONLY — dropped from Operations index |
| `DemoState::setRecommendationStatus()` | `App\Support\Demo\DemoState` | session status overlay | Livewire (pre-P41) | session | no | yes | REPLACED by `UpdateRecommendation` |
| `DemoState::createTaskFromRecommendation()` | same | session fake Task | Livewire (pre-P41) | session | no | yes | REPLACED by a no-Task flash |
| `DemoState::createRecommendationFromOpportunity()` | same | session fake Recommendation | Demo surfaces | session | no | yes | REPLACED by `CreateRecommendationFromOpportunity` |
| `RecommendationV2` / `ProductionRecommendation` / `CanonicalRecommendation` | — | — | — | — | — | — | NOT CREATED |

## 49. Source Kind Matrix

| `source_kind` | `finding_id` | `opportunity_id` | Valid? | Enforced by |
|---|---|---|---|---|
| `finding` | set | null | YES | reference, guard, pgsql CHECK |
| `opportunity` | null | set | YES | reference, guard, pgsql CHECK |
| `finding` | null | set | NO | guard (`finding_id` required) |
| `opportunity` | set | null | NO | guard (`opportunity_id` required) |
| any | set | set | NO | reference + guard + CHECK |
| null / unknown | any | any | NO | guard (derives when derivable, else rejects) |

## 50. Rejected Design Matrix

| Option | Rejected because |
|---|---|
| `RecommendationV2` table | duplicates a live domain; splits history and every reader |
| Unrestricted `morphTo` | no FK, no DB check, any future model becomes a source without review |
| Overloading `source_module` | conflates producing module with source kind; breaks all dedup keys |
| `UNIQUE(source_kind, source_id)` | forbids the legitimate many-Recommendations-per-source case |
| Placeholder Finding for Opportunity rows | fabricates factual interpretations that were never evaluated |
| `deferred` status | not a product status; Demo-only vocabulary |
| Relink service | source rewriting destroys decision provenance; dismiss + create instead |

## 51. Frozen Recommendation Migration Matrix

| Frozen field | Pre-P41 source | Post-P41 source |
|---|---|---|
| title / action / why | Demo fixture | `recommendations.title` / `action` / `rationale` |
| status badge | Demo `pending/approved/rejected` | `open/accepted/dismissed/converted` |
| priority / effort | Demo fixture | `recommendations.priority` / `effort` |
| brand · asset | Demo strings | `digitalAsset.brand.name` · `digitalAsset.name` |
| evidence line | Demo string | source label + rule id + supporting Evidence count |
| source line | Demo `source_opportunity_id` / `finding_id` | `source_kind` + real `finding_id` / `opportunity_id` |
| AI-assisted badge | Demo flag | `source_module` ∈ AI modules |
| origin badge | — | `recommendations.origin` |
| commercial context | `CommercialContextFixtures` | Opportunity `service_definition_code` label |

## 52. Writer Matrix

| Writer | Source kind | Origin | Creates Task? | Calls AI/provider? |
|---|---|---|---|---|
| `WebsiteDiagnosisService::upsertRecommendationForFinding()` | finding | `deterministic_template` | NO | NO |
| `FindingLifecycleService::upsertRecommendation()` | finding | `deterministic_template` | NO | NO |
| 7 × `CrossAsset*ConsistencyService::upsertRecommendation()` | finding | `deterministic_template` | NO | NO |
| `WebsiteAiRecommendationAcceptance` | finding | `operator` | NO | already-run draft only |
| `GoogleAdsAiRecommendationAcceptance` | finding | `operator` | NO | already-run draft only |
| `MetaAdsAiRecommendationAcceptance` | finding | `operator` | NO | already-run draft only |
| `CreateRecommendationFromFinding` | finding | caller (`operator` default) | NO | NO |
| `CreateRecommendationFromOpportunity` | opportunity | caller (`operator` default) | NO | NO |
| Finding / Opportunity / Evidence evaluation | — | — | NO (creates no Recommendation at all) | NO |

## 53. Delete / Lifecycle Matrix

| Event on source | Effect on Recommendation |
|---|---|
| Finding `resolved` | none — row and status untouched |
| Finding reopened | none |
| Finding deleted | blocked by `RESTRICT` |
| Opportunity `deferred` / `dismissed` / `converted` | none |
| Opportunity `closed_at` set | none |
| Opportunity deleted | blocked by `RESTRICT` |
| Digital Asset deleted | `digital_asset_id` set null (unchanged pre-P41 behaviour) |

## 54. Status Mapping Matrix

| Demo action | Demo status (pre-P41) | Production status (post-P41) |
|---|---|---|
| Accept | `approved` | `accepted` |
| Dismiss | `rejected` | `dismissed` |
| Defer | `deferred` | unchanged (`open`) + flash |
| Create Task | `approved` + fake Task | unchanged + flash, no Task |
| Convert from Opportunity | fake `r-from-*` row | real row, Opportunity `converted` |

## 55. Idempotency Matrix

| Path | Key | Repeat behaviour |
|---|---|---|
| Livewire Opportunity conversion | `opportunity-convert:{opportunity_id}` | returns the same row; count stays 1 |
| `CreateRecommendation` with an explicit key | caller-supplied | returns the existing row before writing |
| `CreateRecommendation` without a key | `null` | creates a new row (many per source is legal) |
| Deterministic upserts | none (dedup on `finding_id` + `source_module`) | updates in place |

## 56. Read Filter Matrix

| Filter | Implementation |
|---|---|
| `source_kind`, `finding_id`, `opportunity_id`, `status`, `priority`, `origin`, `source_module`, `digital_asset_id` | direct column predicates |
| `brand_id` | `whereHas` across `digitalAsset` / `finding` / `opportunity` |
| `customer_id` | `whereHas` across `finding` / `opportunity` |
| source hydration | batched: ≤1 Finding query + ≤1 Opportunity query per page |

## 57. Boundary Matrix

| Domain | Prompt 41 behaviour |
|---|---|
| Task / Work | never created from a Prompt 41 path; Filament's pre-existing action remains |
| Approvals / Client Requests / Playbooks / Business Outcomes | untouched, no tables, no code |
| Service Scope / Goals / Offerings | read-only context only |
| AI | no generation; `ai_future` reserved and unwritten |
| Providers | no calls (`Http::assertNothingSent()`) |
| Demo | flash channel only on the Operations index |

## 58. Demo Retirement Matrix

| Surface | Pre-P41 | Post-P41 |
|---|---|---|
| `/app` Operations Recommendations index | Demo fixtures | DB-backed, no fallback |
| Filament `/app/recommendations` | DB-backed | DB-backed + source/origin |
| Brand workspace attention / priorities | Demo | DEMO (out of scope) |
| Dashboards / exec fixtures counts | Demo | DEMO (out of scope) |
| Specialist workspace recommendation cards | mixed (already DB-backed where applicable) | unchanged |
