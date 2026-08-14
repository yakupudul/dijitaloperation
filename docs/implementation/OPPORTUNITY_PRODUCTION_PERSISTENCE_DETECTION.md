# OPPORTUNITY PRODUCTION PERSISTENCE & DETECTION

## STATUS: PASS

**Prompt:** 40
**Date:** 2026-08-14
**Branch:** `cursor/opportunity-production-detection-ea01`
**Base:** Prompt 39 HEAD `69adf24` (Finding Production Intelligence)

---

## 1. Purpose

Canonical Evidence is factual. A Finding is a durable interpretation that a Finding Rule's condition is true. An Opportunity is a further, distinct interpretation: a durable, non-prescriptive commercial-relevance identity that an explicit versioned Opportunity Rule's activation condition is true, given canonical Evidence **and** an existing Finding produced by the Finding Rule Registry.

```text
Canonical Evidence + Canonical Finding → Opportunity Rule Registry
→ Evidence Eligibility → Finding Eligibility → Typed Rule Evaluation
→ Opportunity Fingerprint → Canonical Opportunity + Evaluation History
```

Prompt 40 owns Opportunity. Prompt 41 owns Recommendations. Prompt 40 creates none of those. No Finding is auto-promoted to an Opportunity: an Opportunity exists only when an explicit Opportunity Rule names both an Evidence Definition and a Finding Rule and its typed activation condition evaluates true.

## 2. Frozen Opportunity Product Audit

The frozen `/app` Operations Opportunities card and Brand Growth panels present: title, category, status (`open`/`reviewing`/`deferred`/`converted`/`dismissed`), service label, linked Goal/Offering, market, asset list, Evidence summaries, "why matters" tags, what/why/known/unknown narrative, `observed_at`, `is_new`, `recommendation_id`, `ai_assisted`. There is no numeric "opportunity score" field anywhere in the frozen product surface — priority is presented as a qualitative label only. See matrix "Frozen Opportunity Migration Matrix".

## 3. Existing Opportunity Primitive Audit

See matrix "Existing Opportunity Audit Matrix". Before Prompt 40 there was no `Opportunity` Eloquent model, no `opportunities` table, and no rule registry. `App\Support\Demo\OpportunityFixtures` is a static, hard-coded array of 4 Demo Atlas cards read by `App\Support\Demo\DemoState` and the `Operations\OpportunitiesIndex` Livewire component. `DemoState::setOpportunityStatus()` / `DemoState::createRecommendationFromOpportunity()` mutated session state only. `MOXDOP_FORMULA_REGISTRY_V1.json` already declares `REJECTED_OPPORTUNITY_SCORE` ("Opportunities are domain concepts") — Prompt 40 does not reopen that decision.

## 4. Canonical Opportunity Decision

**CREATE** `App\Models\Opportunity` (`opportunities` table) and `App\Models\OpportunityEvaluation` (`opportunity_evaluations` table). No `OpportunityV2` / `ProductionOpportunity` / `CanonicalOpportunity` — the production test suite asserts these class names do not exist. `App\Support\Demo\OpportunityFixtures` remains Demo-only for any Livewire surface that still embeds it (specialist overview cards); it is never read by the production evaluation, persistence, or read path.

## 5. Evidence vs Finding vs Opportunity

Evidence answers what was measured. A Finding answers what factual condition that Evidence demonstrates. An Opportunity answers whether that factual condition, combined with the customer's commercial context (Service Scope, Goal, Offering, market), currently represents a durable, rule-defined chance to act — without prescribing what action to take (that is Recommendation, Prompt 41) or asserting a business outcome (that is Business Outcome, out of scope). `FINDING_NOT_OPPORTUNITY` and `EVIDENCE_NOT_FINDING` are both enforced invariants in the registry.

## 6. Opportunity Rule Registry

`docs/data-contracts/MOXDOP_OPPORTUNITY_RULES_V1.json`, loaded by `OpportunityRuleRegistry`, validated by `OpportunityRuleValidator` against `EvidenceDefinitionRegistry` (Evidence Definition IDs must exist) and `FindingRuleRegistry` (Finding rule stable IDs must exist). Config: `config/moxdop-opportunity-rules.php` (`opportunity_rule_registry_path`, `opportunity_rule_registry_id` = `MOXDOP_OPPORTUNITY_RULES`, `supported_opportunity_rule_registry_versions`, `evaluate_after_findings`). Three enabled production rules — see "Opportunity Rule Matrix".

## 7. Rule Versioning

`stable_id` is the semantic Opportunity family and the rule half of the persisted `opportunities.fingerprint` key (`{stable_id}:{semantic_fingerprint}`). `version` is the evaluation implementation. A material meaning change requires a new `stable_id`, not an in-place threshold edit, mirroring the Finding Rule Registry convention (Prompt 39 §6).

## 8. Rule Validation

`OpportunityRuleValidator` rejects: unknown registry id/version; missing any of the 13 declared invariants (`NO_ARBITRARY_EXPRESSIONS`, `NO_RUNTIME_EVAL`, `NO_GENERIC_METRIC_PROMOTION`, `NO_GENERIC_FINDING_PROMOTION`, `EVIDENCE_NOT_FINDING`, `FINDING_NOT_OPPORTUNITY`, `NO_PROVIDER_TABLE_BYPASS`, `NO_MAGIC_SCORE`, `MISSING_IS_NOT_CLEARED`, `STALE_IS_NOT_CLEARED`, `PARTIAL_IS_NOT_CLEARED`, `NO_GOAL_OFFERING_NAME_INFERENCE`, `NO_SERVICE_SCOPE_AUTO_CREATE`); duplicate rule id; missing `evidence_definition_ids` or `finding_rule_stable_ids`; unknown Evidence Definition or Finding rule stable ID reference; `expression`/`php`/`sql` keys; `score`/`opportunity_score`/`weight` keys; invalid subject grain; unbounded high-cardinality grain (`PER_QUERY_BOUNDED`/`PER_WEBSITE_PAGE`) without an explicit `cardinality.max_per_digital_asset`; empty activation conditions; `auto_close=true` without a clear condition; unsupported condition type; numeric condition types without a numeric threshold; `FINDING_PRESENT`/`FINDING_ABSENT_WITH_PROOF` referencing a Finding stable ID not declared in `finding_rule_stable_ids`. `php artisan opportunities:validate-rules` runs this check standalone.

## 9. Evidence Inputs

Every rule declares `evidence_definition_ids`. `OpportunityEvidenceEligibilityService` resolves the current (highest-id) row per definition from the frozen Evidence set already read via `CanonicalEvidenceReadService`, then checks: rule enabled; row belongs to the evaluated Digital Asset (else `ScopeMismatch`); integrity not fail/blocked; freshness not `IntegrityBlocked`/`Partial`/`ProviderLimited`/`Stale`/`Unknown`/`ActionRequired` against `freshness_requirement=fresh_or_fresh_with_limitation`; multi-definition rules share period/currency/attribution (`compatibility()`); declared `required_operand_states` paths equal `VALUE`. Missing/stale/partial/provider-limited/unverified is a **block**, never a false condition — mirrors Finding's `MISSING_IS_NOT_CLEARED`/`STALE_IS_NOT_CLEARED`/`PARTIAL_IS_NOT_CLEARED`.

## 10. Finding Inputs

Every rule also declares `finding_rule_stable_ids` and `allowed_finding_states` (`open`, `acknowledged`). `OpportunityFindingEligibilityService` queries `Finding` rows for the asset filtered by those stable IDs — it never scans all Findings and never mutates a Finding. If the named Finding rule has **never** fired for this Digital Asset (zero rows), the rule is blocked `MissingFinding` — absence of history is not proof of absence ("missing is not cleared" applied to Findings, not just Evidence). Once at least one Finding row exists, its current `status` feeds `FINDING_PRESENT` / `FINDING_ABSENT_WITH_PROOF`.

## 11. Service Scope Context

`OpportunityContextResolver::resolveServiceScope()` reads `CustomerServiceScopeReadService` read-only. If the rule declares no `service_definition_codes`, `commercial_scope_state = not_service_relevant`. If the Brand has an active/paused scope matching one of the rule's codes, `in_current_scope`; otherwise `outside_current_scope` (the rule's `service_scope_policy=context_only_outside_allowed` means the Opportunity still activates). No Brand → `service_scope_unknown`. Opportunity evaluation never creates, ends, or edits a `CustomerServiceScope` row (test-asserted: scope count is unchanged before/after).

## 12. Goal Context

`brand_goal_id` is inherited only from an explicit `brand_goal_id` already present on the matched Evidence row, or failing that from the matched Finding's `brand_goal_id`. No name-based inference. Unscoped (`null`) is allowed and common. `include_goal_in_fingerprint` is per-rule (all 3 V1 rules: `false`).

## 13. Offering Context

Same pattern as Goal: `brand_offering_id` inherited only from explicit Evidence or Finding scope, never inferred from title/category text. All 3 V1 rules exclude Offering from the fingerprint (`include_offering_in_fingerprint=false`).

## 14. Target Market Context

Evidence payloads do not currently carry `market_location`/`market_language`, so `OpportunityContextResolver::resolveMarket()` falls back to the Digital Asset's own configured SEO market (`seo_market_location_name`/`code`, `seo_market_language_name`/`code`) — an explicit, already-persisted field, not an inference. All 3 V1 rules exclude market from the fingerprint (`include_market_in_fingerprint=false`).

## 15. No-Magic-Score Policy

`opportunities` has no `score`/`opportunity_score`/`impact_score`/`weight` column. `OpportunityRuleValidator` rejects those keys in the rule JSON. `qualitative_priority` is a static string (`high`/`medium`/`low`) declared per rule (`qualitative_priority_policy=static` in V1), not computed. `MOXDOP_FORMULA_REGISTRY_V1.json`'s `REJECTED_OPPORTUNITY_SCORE` stands unmodified. Tests assert the JSON payload never contains `"opportunity_score"`/`"impact_score"` and the read DTO never exposes a `score` key.

## 16. Opportunity Types / Categories

V1 categories are `visibility` (both GSC rules) and `growth` (GA4 rule) — carried on the rule (`category`) and copied onto `opportunities.category` at creation/reconfirmation. The frozen Demo category vocabulary (`demand`, `content`, `paid`, `creative`, `local`, `conversion`, `cross_channel`) remains for the Demo-only rows still surfaced by `OpportunityFixtures`; the Livewire filter list was extended, not replaced, so both vocabularies coexist without collision.

## 17. Qualitative Priority

`qualitative_priority` is a rule-declared string, never computed from a formula or metric magnitude. `qualitative_priority_policy=static` for all 3 V1 rules — the same priority every time the rule fires. It is stored per Opportunity (not derived at read time) and refreshed on every reconfirming evaluation, but it is not part of any fingerprint.

## 18. Opportunity Fingerprint

`OpportunityFingerprintBuilder::make()` — semantic hash inputs: `stable_rule_id`, `customer_id`, `brand_id`, `digital_asset_id`, `subject_kind`, `subject_id`, plus optionally `brand_goal_id`/`brand_offering_id`/`market_identity`/`period` **only** when the rule sets the corresponding `include_*_in_fingerprint` flag (V1: none do). Excluded always: Evidence row IDs, Finding row IDs, any metric value, `CollectionRun`/`Run` id, `title`, `description`, `severity`, and current `commercial_scope_state`. Unlike Finding (which may collapse to the bare `stable_id` for `PER_DIGITAL_ASSET` legacy convergence), the **persisted** `opportunities.fingerprint` column is always `{stable_id}:{semantic_fingerprint}` (`OpportunityFingerprintBuilder::persistenceKey()`) and is globally unique — there is no legacy Opportunity table to converge onto.

## 19. Evaluation Fingerprint

`OpportunityEvaluationFingerprintBuilder::make()` — includes `opportunity_fingerprint`, `rule_version`, sorted `evidence_observation_fingerprints` (Evidence identity fingerprint + operand values, so a value refresh creates a new evaluation), sorted `finding_observation_fingerprints` (Finding semantic fingerprint + status + condition_state, so a Finding status change creates a new evaluation without changing Opportunity identity), `period`, `condition_config` (`rule->conditionConfigIdentity()`), `service_context_snapshot`, `goal_id`, `offering_id`, `market_identity`. Excludes job/queue id and a bare `evaluated_at` (evaluated_at is stored on the row but not hashed).

## 20. Opportunity Evaluation History

`opportunity_evaluations` (unique `evaluation_fingerprint`) plus `opportunity_evaluation_evidence` (evaluation ↔ Evidence pivot, unique per pair, carries `evidence_observation_fingerprint`) and `opportunity_evaluation_finding` (evaluation ↔ Finding pivot, unique per pair, carries `finding_evaluation_id`). Every evaluation snapshots `operand_snapshot`, `threshold_snapshot`, `freshness_state`, `integrity_state`, `completeness_state`, `lifecycle_action`, `service_context_snapshot`, `goal_ids_snapshot`, `offering_ids_snapshot`, `market_context_snapshot`, `commercial_scope_state`, `qualitative_priority`. `Opportunity.latest_evaluation_id` is the current pointer; full history stays in `evaluations()`.

## 21. Opportunity ↔ Evidence

`opportunity_evaluation_evidence` is a many-to-many pivot from `OpportunityEvaluation`, not a direct FK on `Opportunity`. There is no mandatory `opportunities.evidence_id` — an Opportunity's supporting Evidence is read through its `latestEvaluation.evidence` relation. Evidence rows are attached only if they still exist at persistence time (`Evidence::whereKey($id)->exists()` guard).

## 22. Opportunity ↔ Finding

`opportunity_evaluation_finding` is a many-to-many pivot from `OpportunityEvaluation` to `Finding`, carrying the Finding's `finding_evaluation_id` at the time of the Opportunity evaluation. There is no `opportunities.finding_id` column — Findings are read the same way as Evidence, via `latestEvaluation.findings`. A Finding row is only attached if it still exists.

## 23. Opportunity ↔ Goal

`opportunities.brand_goal_id` (nullable FK-shaped column, `belongsTo(BrandGoal::class)`), always inherited per §12. Not required.

## 24. Opportunity ↔ Offering

`opportunities.brand_offering_id` (nullable, `belongsTo(BrandOffering::class)`), always inherited per §13. Not required.

## 25. Service Context

`opportunities.service_definition_code` + `opportunities.commercial_scope_state` are the persisted read-side projection of §11; `OpportunityEvaluation.service_context_snapshot` (JSON: `rule_service_definition_codes`, `active_service_codes`) is the historical audit trail per evaluation.

## 26. Lifecycle

Detection state (`App\Enums\OpportunityDetectionState`: `detected` / `no_longer_detected` / `blocked_input`) is system truth, tracked independently from operator status (`Opportunity` constants: `open` / `reviewing` / `deferred` / `converted` / `dismissed`). `OpportunityLifecycleAction` (`created`/`reconfirmed`/`reused_evaluation`/`closed`/`reopened`/`blocked`/`condition_false_no_opportunity`/`context_changed`/`none`) records what the persistence layer actually did on a given evaluation, mirroring Finding's separation of lifecycle status from `condition_state` (Prompt 39 §18).

## 27. Activation

Activation condition true (`FINDING_PRESENT` for the named Finding rule, `allowed_finding_states` satisfied, plus any additional typed Evidence conditions ANDed/ORed per `activation.combiner`) + no existing Opportunity for `(digital_asset_id, rule_id)` → create with `status=open`, `detection_state=detected`, `origin=rule_engine`. If an Opportunity already exists and is not operator-terminal, it is reconfirmed (`last_detected_at` refreshed, `detection_state=detected`); if it was previously `closed_at` (auto-closed) and not operator-terminal, it is reopened (§29). `activation_policy=IMMEDIATE` for all 3 V1 rules — no delay/cooldown window.

## 28. Clearing

Clearing requires `FINDING_ABSENT_WITH_PROOF` for the named Finding rule (Finding has history and its current status is outside `allowed_finding_states`, i.e. resolved) **and** `rule.auto_close=true` **and** the existing Opportunity is in an auto-close-eligible status (`open`/`reviewing`) **and** not already closed. Missing Evidence, stale Evidence, partial Evidence, integrity-blocked Evidence, or a Finding rule that has simply never fired are never treated as clearing proof (test-covered explicitly: `test_missing_stale_partial_integrity_do_not_close`).

## 29. Reopening

`reopen_policy=REOPEN_SAME_OPPORTUNITY` for all 3 V1 rules. A previously auto-closed Opportunity (`closed_at` set, status not operator-terminal) that re-activates gets the **same row** re-opened (`closed_at=null`, `detection_state=detected`, `action=Reopened`), with full evaluation history retained — never a duplicate row.

## 30. Operator Disposition

`OpportunityDispositionService` — `review()` (→`reviewing`), `defer()` (→`deferred`), `dismiss()` (→`dismissed`), `markConvertedWithoutRecommendation()` (→`converted`). Every status transition records a meaningful `BrandContextActivity` (`OPPORTUNITY_ACKNOWLEDGED`/`DEFERRED`/`DISMISSED`/`CONVERTED`) via `OpportunityActivityRecorder`. Operator-terminal statuses (`deferred`, `converted`, `dismissed`) are never silently overwritten or reopened by re-detection — the persistence layer checks `OPERATOR_TERMINAL_STATUSES` before reopening (test: `test_dismissed_opportunity_is_not_duplicated_or_reopened`).

## 31. Cardinality / Spam Control

All 3 V1 rules: `cardinality.strategy=PER_DIGITAL_ASSET`, `max_per_digital_asset=1` — at most one Opportunity per rule per Digital Asset, enforced by the unique `fingerprint` column plus the `(digital_asset_id, rule_id)` lookup in `existingOpportunity()`. High-volume, per-keyword Evidence (e.g. `dataforseo.keyword.search_volume`, 40 rows in the test) produces **zero** Opportunities because no V1 rule references that Evidence Definition — test-asserted (`test_search_volume_and_keyword_evidence_do_not_spam`).

## 32. DataForSEO Opportunities

None in V1. No production rule references any `dataforseo.*` Evidence Definition. High search volume, rank position, ETV, or competitor-gap facts are not Opportunities — `NO_GENERIC_METRIC_PROMOTION` applies. `opp-content-coverage` and `opp-implant-organic-gap`'s DataForSEO-adjacent framing in Demo are `MISSING_EVIDENCE` for production.

## 33. GSC Opportunities

Two production rules: `website:gsc:organic-click-recovery` (Finding `website:gsc:clicks-decline`) and `website:gsc:organic-ctr-improvement` (Finding `website:gsc:ctr-decline`), both single-Evidence (`gsc.property.period_comparison`), `PER_DIGITAL_ASSET`, `service seo`.

## 34. GA4 Opportunities

One production rule: `website:ga4:session-recovery` (Finding `website:ga4:sessions-decline`, Evidence `ga4.property.period_comparison`), `PER_DIGITAL_ASSET`, `service seo`.

## 35. Google Ads Opportunities

None in V1. Prompt 39 confirmed no canonical Ads Finding rule exists yet; Prompt 40 therefore has no Ads Finding to reference. No fabricated waste/CPA/pacing Opportunity rule was added.

## 36. Meta Ads Opportunities

None in V1. Same reasoning as Ads — no canonical Meta Finding rule to compose from. `opp-meta-creative-angle` remains Demo-only and is itself closer to a Recommendation ("shift creative mix") than a factual Opportunity — documented as `MISSING_EVIDENCE / Recommendation-like`, not migrated.

## 37. Website Opportunities

All 3 V1 rules are `source_module=website`, composed from the Website module's GSC/GA4 canonical Evidence and Finding rules established in Prompt 38/39. Website diagnosis (meta/title/DNS/TLS/word-count) Findings do not exist in production yet, so no diagnosis-derived Opportunity exists either.

## 38. Cross-Source Opportunities

None in V1. `opp-implant-organic-gap` ("high paid demand, weak organic coverage") is conceptually the closest Demo Atlas card to a real cross-source Opportunity, but a true production version would require both a canonical Ads Finding (does not exist, §35) and DataForSEO/organic Evidence compatibility — only a **partial** conceptual mapping to `website:gsc:organic-click-recovery` exists (organic-side only, no paid-demand comparison). It is documented as `PARTIAL` and explicitly **not** migrated as a Demo-parity row.

## 39. Causal-Claim Boundary

An Opportunity states that a rule-defined commercial-relevance condition is true given Evidence and a Finding. It does not claim the underlying cause (e.g. it does not assert *why* clicks declined), and it does not claim that acting on it will produce a specific business outcome. Causal narrative stays out of the Opportunity row; `title_template`/`summary_template` are template strings, not generated claims.

## 40. Recommendation Boundary

Prompt 40 creates zero Recommendations. `OpportunityDispositionService::markConvertedWithoutRecommendation()` sets `status=converted` and records `OPPORTUNITY_CONVERTED` activity — it does not instantiate `App\Models\Recommendation`. The Livewire `createRecommendation()` action name is preserved from the frozen UI but is now explicitly documented as *not* creating a Recommendation; that wiring is Prompt 41's responsibility (test-asserted: `Recommendation::query()->count()` stays `0` after calling it).

## 41. Work Boundary

Prompt 40 creates zero Tasks. `OpportunityEvaluationService::evaluateAsset()` asserts `Task::query()->count()` is unchanged before/after the run and throws if not.

## 42. Business Outcome Boundary

Opportunity evaluation does not read or write Business Outcome data. No Business Outcome model exists in this pipeline.

## 43. Trigger Architecture

`EvidenceCanonicalized` → `EvaluateFindingsForAssetJob` (Prompt 39) → after Findings are written, if `config('moxdop-opportunity-rules.evaluate_after_findings')` is true, dispatches `EvaluateOpportunitiesForAssetJob` for the same `digital_asset_id`. PHPUnit sets `OPPORTUNITIES_EVALUATE_AFTER_FINDINGS=false` (mirroring `FINDINGS_EVALUATE_AFTER_EVIDENCE=false`) so Prompt 38/39 tests stay Evidence/Finding-only and Opportunity tests call the evaluator explicitly. Manual: `php artisan opportunities:evaluate {digital_asset_id} [--rule=] [--definition=]`. No page-render evaluation, no scheduler.

## 44. Run / Recovery

Generic `Run` row, `module_id=opportunity-evaluation` (`OpportunityEvaluationService::MODULE_ID`), `metadata` includes `actor_user_id`, `pipeline=opportunity_evaluation`, `generated_by_ai=false`, `provider_calls=0`, `ai_calls=0`, and final run stats (rules considered/eligible/blocked, conditions true/false, opportunities created/reused/reopened/closed, evaluations reused, errors, block reason breakdown). A per-rule exception is caught and tallied (`stats->errors++`) without failing the whole run; Opportunity evaluation failure never invalidates canonical Evidence or Findings.

## 45. Idempotency

Unique `opportunities.fingerprint` (persistence key) and unique `opportunity_evaluations.evaluation_fingerprint`. Re-running the same evaluator call with unchanged inputs hits the existing evaluation fingerprint and reuses it (`OpportunityLifecycleAction::ReusedEvaluation`) rather than writing a duplicate row. `UniqueConstraintViolationException` is caught and the persist call retries by re-reading and reusing the now-existing row.

## 46. Concurrency

`Opportunity::query()->where('fingerprint', $persistenceKey)->lockForUpdate()` inside a `DB::transaction()`, combined with the two unique constraints, makes concurrent evaluations of the same asset/rule converge on one row rather than racing to create duplicates.

## 47. Activity

`OpportunityActivityRecorder` writes `BrandContextActivity` only for `OPPORTUNITY_CREATED` / `CLOSED` / `REOPENED` / `ACKNOWLEDGED` / `DEFERRED` / `DISMISSED` / `CONVERTED` / `CONTEXT_CHANGED`. Reconfirmation on an unchanged eligible Opportunity does not write a new activity row — mirrors Finding's "reconfirmation is evaluation history, not timeline spam" (Prompt 39 §44). Activity requires `asset->brand_id`; assets without a Brand produce no activity (defensive, not expected in practice).

## 48. Legacy Migration

There is no legacy Opportunity table or column to migrate — Demo Opportunities were session-only fixtures, never persisted rows. No migration command was needed or written; this differs from Finding's `findings:migrate-legacy-origin` (Prompt 39 §45), which existed because a real legacy `findings` table already existed.

## 49. Demo Retirement

`Operations\OpportunitiesIndex` Livewire is now fully DB-backed via `OpportunityReadService::forListPresentation()` — `DemoState::opportunitiesWithStatus()`, `DemoCatalog::findings`-style Demo reads, and the fixture-derived `serviceScopeLabels` were removed from this component. Empty result set means genuinely empty — no Demo fallback (test-asserted: `assertDontSee('High paid implant demand but weak organic coverage')`, the Demo Atlas title). `OpportunityFixtures` itself is left in place only because other specialist/overview Livewire cards outside this component's scope may still embed it as Demo narrative — those are out of Prompt 40's scope and remain `DEMO` per the Reality Matrix.

## 50. Authorization

Tenant scope is derived from the evaluated Digital Asset → Brand → Customer, same as Finding. No request-authored rule content — the registry is a trusted, versioned JSON file loaded from `config()`. Livewire operator actions (`review`/`defer`/`dismiss`/`createRecommendation`) resolve the Opportunity by numeric ID (`ctype_digit` guard) and go through `OpportunityDispositionService`; there is no Filament create/edit/delete surface for Opportunities in Prompt 40.

## 51. Privacy

Read DTOs and the Operations list presentation expose title/category/status/service/goal/offering/market/asset references and Evidence *summary* strings only — no raw Evidence payload, no `payload` key on evidence summary rows (test-asserted: `assertArrayNotHasKey('payload', $item)`).

## 52. Performance

Evidence is frozen once per `evaluateAsset()` call via `CanonicalEvidenceReadService` and reused across all rules in the plan; Findings are queried per rule (bounded by `finding_rule_stable_ids`, indexed on `digital_asset_id`+`rule_id`). Fingerprint columns are uniquely indexed. Evaluation history is paginated via `OpportunityReadService::paginateEvaluations()`.

## 53. Tests

`tests/Feature/Opportunities/OpportunityProductionDetectionTest.php` — registry validation without magic score/expressions and without forbidden class names; Evidence-alone creates zero Opportunities; Finding-evaluation-alone creates zero Opportunities; an open Finding that no rule references creates zero; rule-true creates one Opportunity and a retry reuses it; a new Evidence revision creates a new evaluation on the same Opportunity; different Brands produce different Opportunities; missing/stale/partial/integrity-blocked Evidence never closes an existing Opportunity; explicit clear closes and a later re-detection reopens the same row; a dismissed Opportunity is not duplicated or reopened; outside-service-scope still creates the Opportunity without creating a Service Scope row; active-scope-then-ended does not change the fingerprint; no Goal/Offering text inference; 40 rows of DataForSEO keyword Evidence create zero Opportunities; the Operations Livewire index is DB-backed with no Demo fallback and no "Opportunity score" text, and `createRecommendation()` converts without creating a `Recommendation`; the read service exposes context without raw payload or score; CTR and GA4 rules are bounded to one Opportunity each. Existing Finding/Evidence/Formula-registry test suites are unaffected (`OPPORTUNITIES_EVALUATE_AFTER_FINDINGS=false` in `phpunit.xml`).

## 54. Reality Matrix

| Capability | After Prompt 40 |
|---|---|
| Provider Pool / Canonical Evidence / Findings / Service Scope / Goals / Offerings | REAL |
| Opportunity Domain | CONVERGED / REAL |
| Opportunity Rule Registry / Eligibility / Fingerprints / History / Dedup / Idempotency / Concurrency | REAL |
| Auto-close | REAL only with explicit `FINDING_ABSENT_WITH_PROOF` |
| Website GSC / GA4 Opportunities | REAL for the 3 supported rules |
| DataForSEO / Google Ads / Meta Ads / Cross-Source Opportunities | NOT YET (missing canonical Ads/Meta Findings or DataForSEO rule support) |
| Demo fallback on Operations Opportunities index | NONE |
| `OpportunityFixtures` residual Demo (specialist overview cards outside Operations index) | DEMO (out of Prompt 40 scope) |
| Recommendations / Tasks / Business Outcomes / AI | NOT YET / NO |

## 55. Prompt 41 Handoff

`OpportunityReadService` and `OpportunityDispositionService` expose current Opportunities by Customer/Brand/DigitalAsset/status/category/rule/service/Goal/Offering without ad-hoc Opportunity-table queries. `markConvertedWithoutRecommendation()` is the seam: Prompt 41 should create the actual `Recommendation` row (with `opportunity_id` provenance) when an Opportunity converts, rather than reopening the disposition service's status transition. Do not implement Recommendation creation here.

## 56. Definition of Done

The canonical Evidence + Finding → versioned Opportunity Rule → fingerprint → idempotent Opportunity → history path is production for the 3 declared rules. No magic score exists anywhere in the pipeline. Unsupported Demo Atlas Opportunity cards are documented as `MISSING_EVIDENCE`/`PARTIAL`/`Recommendation-like`, not fabricated into rules.

---

## 57. Existing Opportunity Audit Matrix

| Primitive | File | Semantic | Writer | Reader | Prod | Demo | Decision |
|---|---|---|---|---|---|---|---|
| `Opportunity` model | — (none before P40) | — | — | — | no | no | NOT PRESENT — CREATE |
| `opportunities` table | — (none before P40) | — | — | — | no | no | NOT PRESENT — CREATE |
| `OpportunityFixtures` | `App\Support\Demo\OpportunityFixtures` | 4 hard-coded Demo Atlas cards | static | Livewire | no | yes | DEMO_ONLY — retained for non-Operations surfaces |
| `DemoState::opportunitiesWithStatus()` | `App\Support\Demo\DemoState` | session status overlay on fixtures | session | `OpportunitiesIndex` (pre-P40) | no | yes | REPLACED in Operations index by `OpportunityReadService` |
| `DemoState::setOpportunityStatus()` | `App\Support\Demo\DemoState` | session-only status mutation | Livewire actions (pre-P40) | session | no | yes | REPLACED by `OpportunityDispositionService` |
| `DemoState::createRecommendationFromOpportunity()` | `App\Support\Demo\DemoState` | session-only fake Recommendation link | Livewire (pre-P40) | session | no | yes | REPLACED by `markConvertedWithoutRecommendation()` (no Rec created) |
| `REJECTED_OPPORTUNITY_SCORE` | `MOXDOP_FORMULA_REGISTRY_V1.json` | formula registry rejection record | Prompt 36/37 | registry | yes | no | REUSED — unmodified, still authoritative |
| `OpportunityV2` / `ProductionOpportunity` / `CanonicalOpportunity` | — | — | — | — | — | — | NOT CREATED |

## 58. Frozen Opportunity Migration Matrix

| Demo Atlas Opportunity | Category | Support | Rule | Production |
|---|---|---|---|---|
| `opp-implant-organic-gap` | cross_channel | PARTIAL conceptual map to `website:gsc:organic-click-recovery` (organic side only; no paid-demand comparison, no full Ads+DataForSEO cross-source support) | — | NOT MIGRATED as Demo row |
| `opp-content-coverage` | content | MISSING_EVIDENCE (no canonical content-coverage Evidence/Finding) | — | DEFERRED |
| `opp-meta-creative-angle` | creative | MISSING_EVIDENCE / Recommendation-like (no canonical Meta Finding; framing prescribes an action) | — | DEFERRED |
| `opp-gbp-local-gap` | local | MISSING_EVIDENCE (no canonical GBP Evidence/Finding pipeline) | — | DEFERRED |

## 59. Opportunity Rule Matrix

| Rule (`stable_id`) | Finding Rule(s) | Evidence Definition(s) | Category | Priority | Service | Grain | Auto-close | Reopen |
|---|---|---|---|---|---|---|---|---|
| `website:gsc:organic-click-recovery` | `website:gsc:clicks-decline` | `gsc.property.period_comparison` | visibility | high (static) | seo | PER_DIGITAL_ASSET | true | REOPEN_SAME_OPPORTUNITY |
| `website:gsc:organic-ctr-improvement` | `website:gsc:ctr-decline` | `gsc.property.period_comparison` | visibility | medium (static) | seo | PER_DIGITAL_ASSET | true | REOPEN_SAME_OPPORTUNITY |
| `website:ga4:session-recovery` | `website:ga4:sessions-decline` | `ga4.property.period_comparison` | growth | medium (static) | seo | PER_DIGITAL_ASSET | true | REOPEN_SAME_OPPORTUNITY |

All 3: `FINDING_PRESENT` activation / `FINDING_ABSENT_WITH_PROOF` clear, `allowed_finding_states=[open, acknowledged]`, `integrity_requirement=trusted`, `freshness_requirement=fresh_or_fresh_with_limitation`, `service_scope_policy=context_only_outside_allowed`, `goal_offering_policy=inherit_explicit_evidence_or_finding_scope_do_not_infer`, no Goal/Offering/Market/Period in fingerprint, `currency_policy=not_applicable`.

## 60. Evidence → Opportunity Matrix

| Evidence Definition | Rules referencing it | Automatic Opportunity? | Without a matching Finding? |
|---|---|---|---|
| `gsc.property.period_comparison` | organic-click-recovery, organic-ctr-improvement | only if Finding present + condition true | NO — blocked `MissingFinding` |
| `ga4.property.period_comparison` | session-recovery | only if Finding present + condition true | NO — blocked `MissingFinding` |
| `dataforseo.keyword.search_volume` and all other unmapped canonical definitions | none | NO | NO |

## 61. Finding → Opportunity Matrix

| Finding rule (`stable_id`) | Opportunity rule(s) | Promoted automatically? | Resolved Finding effect |
|---|---|---|---|
| `website:gsc:clicks-decline` | `website:gsc:organic-click-recovery` | only via explicit rule when Evidence also eligible | resolved + auto_close → Opportunity closes (if one exists) |
| `website:gsc:ctr-decline` | `website:gsc:organic-ctr-improvement` | only via explicit rule when Evidence also eligible | resolved + auto_close → Opportunity closes (if one exists) |
| `website:ga4:sessions-decline` | `website:ga4:session-recovery` | only via explicit rule when Evidence also eligible | resolved + auto_close → Opportunity closes (if one exists) |
| `website:gsc:impressions-decline` and all other Finding rules | none | NO | n/a |

## 62. No-Magic-Score Matrix

| Surface | Score field present? | Enforcement |
|---|---|---|
| `opportunities` table columns | NO | migration has no `score`/`opportunity_score` column (test-asserted `Schema::hasColumn` false) |
| `MOXDOP_OPPORTUNITY_RULES_V1.json` | NO | `OpportunityRuleValidator` rejects `score`/`opportunity_score`/`weight` keys |
| `OpportunityReadDto` / list presentation | NO | test-asserted `assertArrayNotHasKey('opportunity_score'/'score')` |
| Operations Livewire UI | NO | test-asserted `assertDontSee('Opportunity score')` |
| `MOXDOP_FORMULA_REGISTRY_V1.json` | N/A | `REJECTED_OPPORTUNITY_SCORE` explicit rejection, unmodified |
| `qualitative_priority` | static string only | `qualitative_priority_policy=static`, no formula computes it |

## 63. Opportunity Fingerprint Matrix

| Input | Included in semantic fingerprint (V1)? |
|---|---|
| `stable_rule_id` | YES (always) |
| `customer_id`, `brand_id`, `digital_asset_id` | YES (always) |
| `subject_kind`, `subject_id` | YES (always) |
| `brand_goal_id` | only if `include_goal_in_fingerprint` (V1: NO for all 3 rules) |
| `brand_offering_id` | only if `include_offering_in_fingerprint` (V1: NO) |
| `market_identity` | only if `include_market_in_fingerprint` (V1: NO) |
| `period` | only if `include_period_in_fingerprint` (V1: NO) |
| Evidence/Finding row IDs, metric values | NO — never |
| `Run`/`CollectionRun` id | NO — never |
| `title`, `description`, `severity`(N/A), `commercial_scope_state` | NO — never |
| Persisted `opportunities.fingerprint` | `{stable_id}:{semantic_fingerprint}`, globally unique |

## 64. Evaluation Fingerprint Matrix

| Input | Included? |
|---|---|
| `opportunity_fingerprint` | YES |
| `rule_version` | YES |
| Evidence observation fingerprints (Evidence identity + operand values, sorted) | YES |
| Finding observation fingerprints (Finding semantic fingerprint + status + condition_state, sorted) | YES |
| `period` | YES |
| `condition_config` (activation + clear conditions) | YES |
| `service_context_snapshot` | YES |
| `goal_id`, `offering_id`, `market_identity` | YES |
| Job/queue id | NO |
| Bare `evaluated_at` (stored, not hashed) | NO |

## 65. Service Context Matrix

| Rule `service_definition_codes` | Active matching scope? | `commercial_scope_state` | Opportunity created? | Scope row created? |
|---|---|---|---|---|
| `[seo]` | active/paused `seo` scope exists | `in_current_scope` | YES | NO |
| `[seo]` | no active/paused `seo` scope | `outside_current_scope` | YES (still activates) | NO |
| `[seo]` | asset has no Brand | `service_scope_unknown` | YES (blocked path still evaluated) | NO |
| `[]` (none declared) | n/a | `not_service_relevant` | YES | NO |

## 66. Goal Context Matrix

| Source | Goal inherited? | Fingerprint impact |
|---|---|---|
| Explicit `brand_goal_id` on matched Evidence | YES | none (V1 excludes Goal) |
| Explicit `brand_goal_id` on matched Finding (Evidence had none) | YES | none |
| No explicit Goal anywhere | `null` | none |
| Inference from title/category text | NEVER | n/a |

## 67. Offering Context Matrix

| Source | Offering inherited? | Fingerprint impact |
|---|---|---|
| Explicit `brand_offering_id` on matched Evidence | YES | none (V1 excludes Offering) |
| Explicit `brand_offering_id` on matched Finding | YES | none |
| No explicit Offering anywhere | `null` | none |
| Inference from title/category text | NEVER | n/a |

## 68. Market Context Matrix

| Source | Priority order |
|---|---|
| `market_location`/`market_language` present on matched Evidence payload | 1st |
| Digital Asset's configured `seo_market_location_*`/`seo_market_language_*` | 2nd (fallback) |
| Free-text inference from title/category | NEVER |

## 69. Lifecycle Matrix

| Prior state | Evaluation result | Action |
|---|---|---|
| none | activation TRUE + eligible | CREATE (`open`, `detected`, `rule_engine`) |
| existing, not operator-terminal, not closed | activation TRUE + eligible | RECONFIRM (`last_detected_at` refreshed) |
| existing, closed, not operator-terminal | activation TRUE + eligible | REOPEN (same row, `closed_at=null`) |
| existing, operator-terminal (`deferred`/`converted`/`dismissed`) | activation TRUE + eligible | fields refreshed, status preserved, no reopen |
| existing | clear proven + `auto_close` + eligible-for-close status | CLOSE (`closed_at` set, `detection_state=no_longer_detected`) |
| existing | blocked (missing/stale/partial/integrity) | `last_detected_at` refreshed only, no status/closed_at change |
| none | activation FALSE or blocked | no row created |

## 70. Clearing Matrix

| Condition | Clears (closes) an existing Opportunity? |
|---|---|
| `FINDING_ABSENT_WITH_PROOF` true + `auto_close=true` + status open/reviewing + not already closed | YES |
| Finding rule has never fired (no history) | NO — blocked, not proof |
| Missing Evidence | NO |
| Stale/Partial/ProviderLimited Evidence | NO |
| Integrity-blocked Evidence | NO |
| Opportunity already `deferred`/`converted`/`dismissed` | NO (operator terminal, closing is skipped) |

## 71. Reopen Matrix

| Trigger | Result |
|---|---|
| Closed (auto-closed) Opportunity, activation TRUE again, status not operator-terminal | Same row reopened, `action=Reopened`, activity recorded, full evaluation history retained |
| Dismissed Opportunity, activation TRUE again | Status stays `dismissed`, NOT reopened, NOT duplicated |
| Deferred/Converted Opportunity, activation TRUE again | Status preserved, NOT reopened |

## 72. Cardinality Matrix

| Grain | Max per Digital Asset | Enforcement |
|---|---|---|
| `PER_DIGITAL_ASSET` (all 3 V1 rules) | 1 | unique `fingerprint` + `(digital_asset_id, rule_id)` lookup |
| `PER_QUERY_BOUNDED` / `PER_WEBSITE_PAGE` (declared in validator, unused in V1) | validator requires explicit `max_per_digital_asset` | rejected at load time if unbounded |
| Unmapped high-cardinality Evidence (e.g. 40 keyword rows) | 0 | no rule references the definition → zero Opportunities |

## 73. Cross-Source Compatibility Matrix

| Rule | Evidence sources | Cross-source? | Compatibility check |
|---|---|---|---|
| `website:gsc:organic-click-recovery` | GSC only | NO | n/a (single Evidence definition) |
| `website:gsc:organic-ctr-improvement` | GSC only | NO | n/a |
| `website:ga4:session-recovery` | GA4 only | NO | n/a |
| Any future multi-definition rule | — | would require `compatibility()`: same asset, same period, same currency (if applicable), same attribution | implemented, unused in V1 |

## 74. Provider Opportunity Matrix

| Provider | Production Opportunity rules | Reason if none |
|---|---|---|
| Website / GSC | 2 (`organic-click-recovery`, `organic-ctr-improvement`) | — |
| Website / GA4 | 1 (`session-recovery`) | — |
| Google Ads | 0 | no canonical Ads Finding rule exists (Prompt 39 §35) |
| Meta Ads | 0 | no canonical Meta Finding rule exists (Prompt 39 §36) |
| DataForSEO | 0 | no production Finding rules reference DataForSEO Evidence (Prompt 39 §37); `NO_GENERIC_METRIC_PROMOTION` |
| GBP | 0 | no canonical GBP analytical pool/Evidence pipeline (Milestone 5 matrix: GBP is DEMO) |

## 75. Context Dimensions Matrix

| Dimension | Source of truth | Inferred from text? | In fingerprint (V1)? |
|---|---|---|---|
| Service Scope | `CustomerServiceScopeReadService` (read-only) | NO | NO (context only) |
| Goal | explicit Evidence/Finding `brand_goal_id` | NO | NO |
| Offering | explicit Evidence/Finding `brand_offering_id` | NO | NO |
| Market | Evidence payload, else Digital Asset SEO market fields | NO | NO |
| Qualitative priority | static rule declaration | NO | NO (not identity) |

## 76. Recommendation Boundary Matrix

| Action | Creates `Recommendation` row? | Notes |
|---|---|---|
| `OpportunityDispositionService::markConvertedWithoutRecommendation()` | NO | sets `status=converted` only |
| Livewire `createRecommendation()` | NO | delegates to the above; name preserved from frozen UI |
| `OpportunityEvaluationService::evaluateAsset()` | NO | asserts `Recommendation::count()` unchanged, throws otherwise |
| Any Prompt 40 code path | NO | Recommendation creation is explicitly Prompt 41 |

## 77. Legacy Migration Matrix

| Item | Legacy source existed? | Migration needed? |
|---|---|---|
| `opportunities` table | NO (no prior Eloquent model/table) | NO — fresh create only |
| `OpportunityFixtures` rows | Demo-only, session-scoped, never persisted | NO — nothing to carry over into `opportunities` |
| Origin tagging (`rule_engine`/`operator`/`legacy_unverified`/`ai_future`) | n/a | all Prompt 40 rows are `rule_engine`; `legacy_unverified`/`ai_future` reserved, unused |

## 78. Demo Retirement Matrix

| Surface | Before Prompt 40 | After Prompt 40 |
|---|---|---|
| `Operations\OpportunitiesIndex` list | `DemoState::opportunitiesWithStatus()` (fixtures + session) | `OpportunityReadService::forListPresentation()` (DB, empty-means-empty) |
| `Operations\OpportunitiesIndex` status actions | `DemoState::setOpportunityStatus()` | `OpportunityDispositionService` |
| `Operations\OpportunitiesIndex` "create recommendation" | `DemoState::createRecommendationFromOpportunity()` (fake) | `markConvertedWithoutRecommendation()` (real status, no fake Rec) |
| `OpportunityFixtures` class | source of truth for Operations index | retained only for any other Demo-only Livewire surface (specialist overview cards) outside Prompt 40 scope |
| Formula registry `REJECTED_OPPORTUNITY_SCORE` | present | unchanged |

## 79. Domain Boundary Matrix

| Concept | Is it an Opportunity? |
|---|---|
| Provider Fact / Evidence | NO — Opportunity requires a Finding too |
| Finding | NO — Finding is a prerequisite input, not itself an Opportunity |
| Goal / Offering / Service Scope | NO — context only, never auto-created by Opportunity evaluation |
| Recommendation | NO — Prompt 41; Opportunity `converted` status does not create one |
| Task / Work | NO — never created by Opportunity evaluation |
| Playbook | NO |
| Business Outcome | NO — no causal or outcome claim is made |
| Activity | NO — Activity records that an Opportunity event happened, it is not the Opportunity |
| A numeric "opportunity score" | NO SUCH CONCEPT — `REJECTED_OPPORTUNITY_SCORE`, `NO_MAGIC_SCORE` |

---

Prompt 40 auto-creates zero Recommendations, Tasks, Playbooks, Service Scopes, Goals, Offerings, or Business Outcomes.
