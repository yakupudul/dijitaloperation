# FINDING PRODUCTION INTELLIGENCE

## STATUS: PASS

**Prompt:** 39
**Date:** 2026-08-14
**Branch:** `cursor/finding-production-intelligence-ea01`
**Base:** Prompt 38 HEAD `730934d691f7d505c32a42897d235de703062019`

---

## 1. Purpose

Canonical Evidence is factual. A Finding is a durable, non-prescriptive interpretation that an explicit versioned Finding Rule’s condition is or was true for a canonical scope/subject.

```text
Normalized Data → Canonical Evidence → Finding Rule Registry
→ Evidence Eligibility → Typed Rule Evaluation → Finding Fingerprint
→ Canonical Finding + Evaluation History
```

Prompt 40 owns Opportunity. Prompt 41 owns Recommendations. Prompt 39 creates none of those.

## 2. Existing Finding Audit

See matrix 371. One Finding row is a persistent issue identity for a Digital Asset, unique on `(digital_asset_id, fingerprint)`.

Finding belongs to DigitalAsset (Customer/Brand via asset; now denormalized). There was never a mandatory `evidence_id`. Production evaluations attach Evidence via `finding_evaluation_evidence`. One Evidence may support many Findings through different rules. Filament cannot create Findings. Statuses: `open` / `acknowledged` / `resolved` (no dismissed). `recommendations.finding_id` is preserved. Demo Livewire remains Demo-only.

## 3. Canonical Finding Decision

Evolve `App\Models\Finding` / `findings`. No FindingV2 / ProductionFinding / CanonicalFinding. Legacy bound evaluators remain LEGACY writers into the same table. Prompt 39 production path is `FindingEvaluationService` via `CanonicalEvidenceReadService` only.

## 4. Evidence vs Finding

Evidence answers what was measured. Finding answers what factual condition that Evidence demonstrates under a MoxDOP rule. Evidence does not imply a Finding.

## 5. Finding Rule Registry

`docs/data-contracts/MOXDOP_FINDING_RULES_V1.json` loaded by `FindingRuleRegistry`, validated by `FindingRuleValidator` against `EvidenceDefinitionRegistry`.

## 6. Rule Versioning

`stable_id` is the semantic issue family (also the persisted `findings.fingerprint` for PER_DIGITAL_ASSET, matching frozen catalog IDs). `version` is the evaluation implementation. Material meaning change requires a new `stable_id`.

## 7. Rule Types

Typed conditions only: `VALUE_*`, `STATE_*`, `BOOLEAN_IS`, `ABSENCE_CONFIRMED`, `PRESENCE_CONFIRMED`, `ABS_DECREASE_GTE`, `ABS_INCREASE_GTE`. Combiner ALL/ANY. Optional `negate`. No `eval()`, expressions, or stored PHP/SQL.

## 8. Rule Validation

Unknown Evidence definition, missing numeric threshold, auto_resolve without clear condition, executable expression keys, unbounded high-cardinality grain without a bound: validation failure.

## 9. Frozen Finding Audit

See matrix 372. Production rules cover Website PerformanceFindingsCatalog families that Prompt 38 Evidence can prove: GSC clicks/impressions/CTR comparison and GA4 sessions comparison. Unsupported Demo Atlas cards and diagnosis/Ads/Meta/DataForSEO catalogs are documented, not fabricated.

## 10. Metric Spam Prevention

A measurement does not create a Finding. No generic CTR/CPC/rank/traffic/word-count/search-volume rules. V1 grain: PER_DIGITAL_ASSET max 1.

## 11. Evidence Eligibility

Integrity, freshness (only `FRESH` / `FRESH_WITH_LIMITATION` for new current Findings), completeness (formula `VALUE` states), scope, period, currency, attribution. Missing/stale/partial/provider-limited/unverified/integrity-blocked are not condition false and not cleared.

## 12. Finding Scope

Customer, Brand, DigitalAsset from Evidence’s asset. Service Scope is context only.

## 13. Finding Subject

V1 grain is DigitalAsset. Subject is not invented from display text.

## 14. Finding Fingerprint

See matrix 376. Semantic hash includes stable rule ID, customer, brand, digital asset, subject. Persistence key for asset-grain rules is the frozen `stable_id` so legacy rows converge.

## 15. Evaluation Fingerprint

See matrix 377. Includes Finding fingerprint, rule version, observation fingerprints (identity + operand values), period, condition config. Excludes job ID and `evaluated_at`.

## 16. Finding Evaluation History

`finding_evaluations` + `finding_evaluation_evidence`. Operand/threshold snapshots only.

## 17. Finding ↔ Evidence

No mandatory `findings.evidence_id`. Current supporting Evidence is the latest evaluation pivot.

## 18. Lifecycle

`open` / `acknowledged` / `resolved` plus separate `condition_state` (`true` / `false` / `unknown` / `blocked`).

## 19. Activation

Rule true + no Finding → create one. Existing active/acknowledged → reuse.

## 20. Clearing

Clearing is not “rule did not fire”. Missing/stale/partial/integrity/auth/cost-guard ≠ clear.

## 21. Auto Resolution

Only when `auto_resolve=true` and eligible complete current Evidence proves the explicit clear condition.

## 22. Reopening

`REOPEN_SAME_FINDING`. Same row, new evaluation, history retained.

## 23. Operator Disposition

`acknowledged` is preserved while condition remains true. Frozen product has no dismissed status; none was added.

## 24. Severity

Existing strings. V1 rules are static. Severity change does not create a new Finding. No magic score. Severity is not Opportunity value.

## 25. Category

Existing product categories. No provider-specific category explosion. Provenance stays on Evidence.

## 26. Origin

`rule_engine` / `operator` / `legacy_unverified` / `ai_future` (never written in Prompt 39).

## 27. Thresholds

Copied from frozen `PerformanceFindingsCatalog` into versioned JSON. Percent gates stored as ratio (`-0.2`). CTR drop remains ratio points (`0.005`). No hidden constants in the production evaluator.

## 28. Multi-Evidence Rules

Join key declared. Scope/period/currency/attribution mismatches block. No production cross-source rules invented.

## 29. Cardinality Control

One Finding per Digital Asset per stable rule. Unbounded query/page Evidence without a mapped bounded rule → 0 Findings.

## 30. Goal / Offering Context

Inherited only from explicit Evidence Goal/Offering IDs. No name inference. Unscoped allowed. Not in V1 fingerprint.

## 31. Service Scope Context

Inactive/ended service does not suppress factual Findings and is not created by Finding evaluation.

## 32. Website Rules

Production: GSC clicks/impressions/CTR decline. Diagnosis meta/title/DNS/TLS/word-count: MISSING_EVIDENCE_SUPPORT.

## 33. GA4 Rules

Production: sessions relative decline. Users/totalUsers not mapped onto `activeUsers`. No generic low traffic. No qualified-lead language.

## 34. GSC Rules

Production: clicks, impressions, CTR. Position worsen deferred (no position on canonical comparison). Missing query row ≠ Finding.

## 35. Google Ads Rules

LEGACY catalog only. No canonical Ads Evidence in Prompt 38. No default high CPC / low CTR / wasted-spend production rule.

## 36. Meta Ads Rules

LEGACY catalog only. No generic Results Finding. `action_type` / attribution / non-additive reach hazards documented.

## 37. DataForSEO Rules

No production Finding rules. High volume, rank, ETV, competitor, relevant page are not Findings.

## 38. Cross-Source Boundary

None in V1. High volume + low visibility is Opportunity (Prompt 40).

## 39. Execution Pipeline

`FindingEvaluationService`: freeze Evidence set → rules → eligibility → typed conditions → fingerprints → persistence → history → meaningful Activity. Does not query provider pool tables.

## 40. Trigger Architecture

`EvidenceCanonicalized` after Evidence pipeline assertion → `EvaluateFindingsForAssetJob`. PHPUnit sets `FINDINGS_EVALUATE_AFTER_EVIDENCE=false` so Prompt 38 tests remain Evidence-only. Manual: `php artisan findings:evaluate {digital_asset_id}`. No page-render evaluation. No scheduler.

## 41. Run / Recovery

Generic `Run` `module_id=finding-evaluation`. Retry uses frozen plan + unique evaluation fingerprint. Finding failure does not invalidate Evidence.

## 42. Idempotency

Unique `(digital_asset_id, fingerprint)` and unique `evaluation_fingerprint`. UniqueConstraintViolationException reuse.

## 43. Concurrency

`lockForUpdate` plus unique constraints.

## 44. Activity / History

`BrandContextActivity` only for CREATED / SEVERITY_CHANGED / RESOLVED / REOPENED. Reconfirmation is evaluation history, not timeline spam.

## 45. Legacy Finding Migration

`php artisan findings:migrate-legacy-origin`. Fingerprint matching a known `stable_id` → `rule_engine`. Otherwise `legacy_unverified`. Recommendation FKs untouched. Demo arrays not inserted.

## 46. Demo Retirement

Filament `/app/findings` is DB-backed (`No findings` empty state). `DemoCatalog::findings()` remains Demo Livewire only. No exception fallback to Demo.

## 47. Opportunity Boundary

Prompt 39 creates 0 Opportunities, Recommendations, Tasks, Playbooks. Production path does not fire `FindingEvaluationCompleted` (legacy lifecycle still may, for bound collectors).

## 48. Authorization

Tenant scope from Evidence’s DigitalAsset → Brand → Customer. No request-authored rules. Filament cannot create/edit/delete Findings.

## 49. Privacy

Aggregate operands only. Raw Evidence payload is not on the read DTO.

## 50. Performance

Frozen in-memory Evidence set; latest row per definition. Fingerprint unique index. Evaluation history paginated.

## 51. Tests

`tests/Feature/Findings/FindingProductionIntelligenceTest.php` plus existing Finding lifecycle/resource/migration and Prompt 38 Evidence tests.

## 52. Reality Matrix

| Capability | After Prompt 39 |
|---|---|
| Provider Pool / Canonical Evidence / Service Scope / Goals / Offerings | REAL |
| Finding Domain | CONVERGED / REAL |
| Rule Registry / Eligibility / Fingerprints / History / Dedup / Idempotency / Concurrency | REAL |
| Auto-resolution | REAL only with explicit clear proof |
| Website / GA4 / GSC Findings | REAL for supported rules |
| Google Ads / Meta / DataForSEO Findings | NOT YET (missing canonical Evidence) |
| Demo fallback | NONE on production reads |
| Opportunities / Recommendations / Tasks / AI | NOT YET / NO |

## 53. Prompt 40 Handoff

`FindingReadService` exposes current Findings by Customer/Brand/DigitalAsset/subject/Goal/Offering/category/severity/rule without ad-hoc Evidence queries.

Do not implement Opportunity detection here.

## 54. Definition of Done

The Evidence → versioned Rule → fingerprint → idempotent Finding → history path is production. Unsupported frozen Demo types are documented rather than fabricated.

---

## 371. Existing Finding Audit Matrix

| Primitive | File | Semantic | Writer | Reader | Evidence | Rec | Prod | Demo | Decision |
|---|---|---|---|---|---|---|---|---|---|
| Finding | `findings` | Persistent issue | Lifecycle + P39 | Filament | eval pivot | hasMany | yes | no | EVOLVE |
| fingerprint unique | `(asset, fingerprint)` | Identity | both | queues | n/a | n/a | yes | no | REUSE |
| FindingLifecycleService | Core | Upsert + unmatched resolve + Recs | bound/diagnosis | n/a | JSON bags | creates Recs | yes | no | LEGACY/REUSE |
| Bound evaluators | website/ads/meta modules | JSON bags | CollectLiveBoundData | n/a | legacy types | via lifecycle | yes | no | LEGACY |
| Website diagnosis | WebsiteDiagnosisService | probes | diagnose jobs | n/a | http_fetch | Recs | yes | no | LEGACY |
| PerformanceFindingsCatalog | modules | Frozen thresholds | bound eval | n/a | JSON | Recs | config | no | REUSE (threshold source) |
| DemoCatalog::findings | Demo | Atlas cards | DemoState | Livewire | fake | fake | no | yes | DEMO_ONLY |
| FindingResource | Filament | DB list | none | operators | no | yes | yes | no | CANONICAL |
| FindingEvaluation | new | History | P39 | read | pivot | no | yes | no | CANONICAL |
| FindingV2 / ProductionFinding | — | — | — | — | — | — | — | — | NOT CREATED |

## 372. Frozen Finding Migration Matrix

| Frozen Finding | Source | Support | Rule | Production |
|---|---|---|---|---|
| GSC clicks decline | Website catalog | REAL_RULE_SUPPORTED | website:gsc:clicks-decline | REAL |
| GSC impressions decline | Website catalog | REAL_RULE_SUPPORTED | website:gsc:impressions-decline | REAL |
| GSC CTR decline | Website catalog + FORMULA_GSC_CTR | REAL_RULE_SUPPORTED | website:gsc:ctr-decline | REAL |
| GSC position worsen | Website catalog | MISSING_EVIDENCE_SUPPORT | — | DEFERRED |
| GA4 sessions decline | Website catalog | REAL_RULE_SUPPORTED | website:ga4:sessions-decline | REAL |
| GA4 users decline | Website catalog | MISSING_EVIDENCE (`activeUsers` ≠ totalUsers) | — | DEFERRED |
| Document head meta/title/heuristics | DocumentHeadCatalog | MISSING_EVIDENCE / JUDGMENT | — | DEFERRED |
| Diagnosis reachability/TLS/robots | WebsiteDiagnosisService | MISSING_EVIDENCE | — | LEGACY |
| Google Ads catalog (incl. waste/opportunity) | google-ads module | MISSING_EVIDENCE / Opportunity-like | — | DEFERRED |
| Meta Ads catalog | meta-ads module | MISSING_EVIDENCE | — | DEFERRED |
| Demo Atlas cards (lead, CPL, frequency, LCP, canonicals, waste, GBP, hosting) | DemoCatalog | DEMO_ONLY / JUDGMENT / RECOMMENDATION_DISGUISED | — | NOT MIGRATED |

## 373. Finding Rule Matrix

See `MOXDOP_FINDING_RULES_V1.json`. Four enabled rules, all PER_DIGITAL_ASSET, inherit Goal/Offering if explicit, trusted freshness, VALUE completeness, IMMEDIATE activation, auto-resolve with explicit clear, REOPEN_SAME_FINDING, static severity.

## 374. Evidence → Finding Matrix

| Evidence Definition | Rules | Automatic Finding? | Without Finding? |
|---|---|---|---|
| gsc.property.period_comparison | clicks, impressions, CTR | only if condition true | YES |
| ga4.property.period_comparison | sessions | only if condition true | YES |
| unmapped canonical rows | none | NO | YES |

## 375. Metric Spam Matrix

GA4 Sessions/Users, GSC Clicks/Impr/CTR/Position, Ads Spend/CPC/CTR/Conversions, Meta Spend/Actions/Reach/Frequency, DataForSEO volume/rank/ETV, Website word/page count, technical states: **generic Finding = NO**. Explicit rule required. V1 bound = 1 per asset where a rule exists, else 0.

## 376. Finding Fingerprint Matrix

Included: stable rule ID, customer, brand, digital asset, subject. Excluded: rule version, Evidence ID/revision/value, period (V1), CollectionRun, DatasetRun, title, severity. Goal/Offering excluded in V1 (not semantic to these rules). No period-specific V1 rule.

## 377. Evaluation Fingerprint Matrix

Included: Finding fingerprint, rule version, observation fingerprints, period, condition config. Excluded: job ID, CollectionRun, evaluated_at.

## 378. Lifecycle Matrix

TRUE + none → OPEN created. TRUE + OPEN → reconfirm. TRUE + ACK → keep ACK. TRUE + RESOLVED → reopen. FALSE with proof + auto_resolve → RESOLVED. Missing/stale/partial/integrity → no status change, no new Finding.

## 379. Auto-Resolution Matrix

Clicks/impr/sessions/CTR: auto-resolve only with complete trusted Evidence proving clear gates. Missing and provider-limited do not resolve.

## 380. Reopen Matrix

RESOLVED + proven TRUE → same Finding ID, new evaluation, history retained, status OPEN. ACKNOWLEDGED + TRUE → no duplicate, ACK preserved.

## 381. Severity Matrix

Static catalog severity. Change records history/activity, does not create a new Finding. Not Opportunity impact.

## 382. Cardinality Matrix

V1: max 1 Finding per asset per rule. Unmapped high-cardinality query/page Evidence: 0 Findings.

## 383. Multi-Evidence Compatibility Matrix

V1 production rules are single-Evidence. Compatibility checker blocks scope/period/currency/attribution mismatch. Covered by tests.

## 384. Provider Rule Matrix

Website diagnosis: deferred. GA4: sessions only. GSC: three comparison rules; position deferred. Ads/Meta/DataForSEO: no production rules.

## 385. Goal / Offering Matrix

All V1: inherit explicit Evidence IDs only. Infer by name: NO. In fingerprint: NO. Unscoped: YES.

## 386. Service Scope Matrix

Not required. No hidden suppression. Inactive/ended does not delete Findings. Finding creation does not create Service Scope.

## 387. Origin Matrix

RULE_ENGINE: Evidence+Rule required, auto-resolve per rule, AI=0. OPERATOR: no create UI in P39. LEGACY_UNVERIFIED: no guessed rule. AI_FUTURE unused.

## 388. Legacy Finding Migration Matrix

Catalog-stable fingerprint → map rule_id, origin rule_engine. Other fingerprints → legacy_unverified. Rec FKs kept. Demo arrays skipped. Idempotent.

## 389. Demo Retirement Matrix

DemoCatalog findings remain Demo Livewire. FindingFactory remains tests. Filament empty state is genuine empty. Bound-catalog Recs remain LEGACY.

## 390. Domain Boundary Matrix

Provider Fact, Evidence, Goal, Offering, Service Scope, Opportunity, Recommendation, Task, Playbook, Approval, QA, Business Outcome, Activity, Decision are not Findings. Prompt 39 auto-creates none of Opportunity/Recommendation/Task/Playbook/Business Outcome.
