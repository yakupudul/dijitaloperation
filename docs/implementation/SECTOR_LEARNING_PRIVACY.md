# SECTOR LEARNING & PRIVACY

## STATUS: REAL (Prompt 53)

**Prompt:** 53  
**Canonical path:** `docs/implementation/SECTOR_LEARNING_PRIVACY.md`  
**Privacy policy:** [`docs/architecture/SECTOR_MEMORY_PRIVACY_POLICY.md`](../architecture/SECTOR_MEMORY_PRIVACY_POLICY.md)  
**Artifact contract:** [`docs/architecture/SECTOR_LEARNING_ARTIFACT_CONTRACT.md`](../architecture/SECTOR_LEARNING_ARTIFACT_CONTRACT.md)  
**Depends on:** Prompt 51 Intelligence Memory Architecture; Prompt 52 Brand Experience Records

| Fact | Value |
| --- | --- |
| Sector Learning pipeline | **REAL** |
| Privacy policy | `sector_privacy_v1` (`SectorLearningPrivacyPolicy`) |
| Production privacy gate | **REAL** (`ProductionSectorLearningPrivacyGate`) |
| Contribution projection | `sector_projection_v1` |
| Contribution bounding | `sector_bounding_v1` |
| Aggregation | `sector_aggregation_v1` |
| Formal k-anonymity / DP | **NOT CLAIMED** |
| Privacy score | **NONE** |
| Contribution source | Brand Experience **only** |
| Sector identity | `OperatorConfirmedSectorIdentityResolver` + `IndustryOptions` (no AI) |
| Consumer DTO | `SectorMemoryConsumerDto` (no contributor IDs) |
| Retrieval / Memory Pack injection | **NOT YET** / Prompt 54 |
| Vectors / embeddings / AI / provider calls / scheduler | **NONE** |

---

## 1. Purpose

Make Sector Memory real through a **privacy-qualified, deterministic aggregation pipeline** over confirmed Brand Experience Records — without inventing SectorLearningV2, without formal k-anonymity/differential-privacy claims, without vectors/embeddings, without AI sector inference, and without Agent retrieval injection (Prompt 54).

## 2. Existing Sector Primitive Audit

| Primitive | Path | Decision |
| --- | --- | --- |
| `sector_learning_artifacts` | migration `2026_08_16_012554` | **NONE prior → created** |
| `sector_learning_revisions` | same | **NONE prior → created** |
| `sector_learning_lineage_entries` | same | **NONE prior → created** (restricted internal) |
| IndustryBenchmark / SimilarCustomer / PeerBenchmark tables | none | **NONE** |
| `DeferredSectorLearningPrivacyGate` | P51 stub | **Superseded** by production gate (kept historically) |
| `NullSectorMemoryContextProvider` | P51 stub | **Replaced** by `ReleasedSectorMemoryContextProvider` |
| Demo fake sector benchmarks | none found | **NONE to migrate** |

## 3. Existing Industry Benchmark Audit

No production IndustryBenchmark, PeerBenchmark, or external benchmark import tables exist. Released artifacts carry `industry_benchmark_claim: false` and `source_label: moxdop_cohort_observation`.

## 4. Existing Similar-Customer / Vector Audit

No SimilarCustomer, nearest-neighbor Brand Memory, shared vector namespace, or embedding generation. Prompt 53 does not add any.

## 5. Frozen Product Surface Audit

No new Filament/Livewire Sector Learning navigation or benchmark dashboards. Backend persistence, privacy gate, artifact release, consumer read service, and restricted audit lineage are production-ready without new top-level UI.

## 6. Canonical Sector Learning Decision

One domain: `sector_learning_artifacts` + immutable `sector_learning_revisions` + restricted `sector_learning_lineage_entries`. **No SectorLearningV2.**

## 7. Sector Memory vs Brand Memory

| Dimension | Brand Memory | Sector Memory |
| --- | --- | --- |
| Owner | Customer + Brand | Sector cohort (privacy-qualified) |
| Source | Brand Experience (in-tenant) | Aggregated Experience (cross-brand) |
| Contributor IDs in consumer view | Allowed (in-tenant) | **FORBIDDEN** |
| Privacy class | `tenant_confidential` | `privacy_qualified_aggregate` |

## 8. Sector Memory vs Skill Memory

Sector Memory is cohort observational context only. Skill Memory remains general non-customer methodology. Sector artifacts must not auto-mutate Skills.

## 9. Brand Experience Only Contribution Source

`SectorLearningContributionRepository` reads **confirmed** Brand Experiences with sufficient/partial support status only. No raw provider rows, Evidence copies, Findings, Tasks, or Agent outputs enter the pipeline directly.

## 10. Contribution Eligibility

Projector requires: `status=confirmed`, `support_status ∈ {sufficient, partial}`, `outcome_observed_at` present, operator-confirmed sector code, valid `IndustryOptions` code.

## 11. Sector Identity Resolution

`OperatorConfirmedSectorIdentityResolver`: `Brand.sector` first, else `Customer.industry`, both validated against `IndustryOptions`. Missing/invalid ⇒ sector unknown (blocked).

## 12. IndustryOptions Catalog

Sector codes are closed-catalog operator fields. No free-text sector labels in consumer artifacts.

## 13. No AI Sector Inference

`SectorIdentityRef.aiInferred` must be `false`. AI-inferred sector identity is rejected at gate construction and qualification.

## 14. Privacy Policy Version

`SectorLearningPrivacyPolicy::VERSION = sector_privacy_v1`. Snapshot includes projection (`sector_projection_v1`) and aggregation (`sector_aggregation_v1`) version IDs.

## 15. NOT Formal k-Anonymity / Differential Privacy

Policy is documented as `product_disclosure_control_policy`. `formal_k_anonymity_claim: false`, `differential_privacy_claim: false`. Do not describe thresholds as legal anonymity guarantees.

## 16. No Privacy Score

Gate decisions are explicit PASS/BLOCK + reason codes only. `privacy_score` is always `null`.

## 17. Default Cohort Thresholds

`MIN_DISTINCT_BRANDS = 5`, `MIN_DISTINCT_CUSTOMERS = 5`. Both must pass for release.

## 18. Categorical Cell Thresholds

Per action_kind × outcome_clarity cell: `MIN_CATEGORICAL_CELL_BRANDS = 3`, `MIN_CATEGORICAL_CELL_CUSTOMERS = 3`. Below ⇒ `SUPPRESSED_PRIVACY` (not zero).

## 19. Numeric Cohort Thresholds

When `requires_numeric_cohort=true`: `MIN_NUMERIC_AGGREGATE_BRANDS = 10`, `MIN_NUMERIC_AGGREGATE_CUSTOMERS = 10`. MVP `action_outcome_association` uses categorical distribution (numeric gate not required).

## 20. Max Effective Share

`MAX_SINGLE_BRAND_EFFECTIVE_SHARE = 0.20`, `MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE = 0.20`. Enforced in bounder and re-checked by gate.

## 21. Why Brand + Customer Dual Threshold

Prevents one agency Customer with many Brands from dominating a cohort, and prevents one Brand with many Experiences from dominating aggregates. Bounding + dual minimums address both axes.

## 22. Contribution Projection (`sector_projection_v1`)

`SectorLearningContributionProjector` maps Brand Experience revision → `SafeSectorContributionProjection`. Strips identifiers and Experience free text. Internal `InternalSectorContribution` retains lineage IDs separately.

## 23. Safe Projection Fields

`sector_code`, `channel`, `market_code`, `action_kind`, `outcome_clarity`, `time_bucket` (YYYY-MM from `outcome_observed_at`), `support_status`, `quality_policy_version`, `causality_status`, `contribution_fingerprint`.

## 24. Blocked Identifier Keys

`SectorLearningPrivacyPolicy::BLOCKED_IDENTIFIER_KEYS` — includes `customer_id`, `brand_id`, `experience_id`, names, domain, URL, email, phone, campaign/ad/keyword/creative fields, free text summaries, `goal_id`, `offering_id`, `provider_resource_id`, internal contributor IDs.

## 25. Contribution Bounding (`sector_bounding_v1`)

`SectorLearningContributionBounder`: one effective unit per Brand (median-indexed by fingerprint), then customer share rebalance when raw share > 0.20.

## 26. Median Brand Reduction

Multiple Experiences from one Brand collapse to a single bounded contribution — deterministic median fingerprint pick, not best/favorable selection.

## 27. Customer Rebalance

When one Customer owns disproportionate Brand units, effective weights are reduced via closed-form solve so post-bounding customer share ≤ 0.20.

## 28. Production Privacy Gate

`ProductionSectorLearningPrivacyGate` implements `SectorLearningPrivacyGate`. Bound in `AppServiceProvider`. Deterministic qualification; no AI.

## 29. Deferred Stub Superseded

`DeferredSectorLearningPrivacyGate` (Prompt 51) returned `blocked_pipeline_not_implemented`. Production gate replaces it for DI binding; stub remains in codebase for historical reference/tests.

## 30. Privacy Gate Reason Codes

`SectorLearningPrivacyReasonCode` enum — explicit codes (e.g. `INSUFFICIENT_DISTINCT_BRANDS`, `SMALL_CATEGORICAL_CELL`, `DOMINANT_CUSTOMER_CONTRIBUTION`). No numeric score.

## 31. Pre-Aggregation Qualification

Gate checks cohort counts, max shares, safe dimensions, metric family allowlist, blocked keys, city/postcode/exact_date flags before aggregation.

## 32. Aggregation Service (`sector_aggregation_v1`)

`SectorLearningAggregatorService::aggregateActionOutcomeAssociation` — deterministic direction distribution over `action_kind × outcome_clarity` cells. No causality inference.

## 33. `action_outcome_association` Artifact

First released artifact kind. Schema `sector_aggregate_action_outcome_v1`. Aggregator `direction_distribution`. `causality: causality_not_established`.

## 34. SUPPRESSED_PRIVACY ≠ Zero

Suppressed cells use `status: SUPPRESSED_PRIVACY` with `count: null`. Must not be rendered or stored as numeric zero (re-identification risk).

## 35. Artifact / Revision / Lineage Tables

- `sector_learning_artifacts`: stable identity (`stable_key`), sector, kind, status, `current_revision_id`
- `sector_learning_revisions`: dimension contract, aggregate JSON, privacy assessment, version pins, internal distinct brand/customer counts
- `sector_learning_lineage_entries`: revision ↔ Experience/Brand/Customer linkage (restricted)

## 36. Artifact Lifecycle

Statuses: `active`, `superseded`, `stale`, `privacy_blocked`, `invalidated`. Consumer reads only `active` revisions with Eligible privacy disposition and matching policy version.

## 37. SectorLearningArtifactService

Sole cross-brand writer. `buildAndReleaseActionOutcomeAssociation(sectorCode)` runs repository → bounder → pre-gate → aggregator → post-gate → transactional release. Idempotent on `aggregate_fingerprint`.

## 38. Consumer DTO (`SectorMemoryConsumerDto`)

Consumer-safe artifact view. Constructor rejects contributor ID keys. Includes limitations, observational label, policy/aggregation/projection versions. `toMemoryPackReference()` for Prompt 54 handoff shape only.

## 39. SectorMemoryReadService

Lists/finds **released** artifacts only. Never returns lineage or Brand Experience records. Defense-in-depth strips blocked keys from aggregate JSON.

## 40. Restricted Lineage / Audit Service

`SectorLearningAuditService`: privileged lineage reads and artifact impact queries by Experience/Customer. **Not** for Agents, customer users, or normal Sector consumers.

## 41. Metric Registry

`SectorLearningMetricRegistry`: allowlist for `outcome_clarity_distribution`, `action_kind_frequency`. Blocks `exact_spend`, `exact_revenue`, blind CPC averages, cross-provider incompatible mixes.

## 42. Safe Dimension Registry

`SectorLearningSafeDimensionRegistry`: allowlist mirrors policy `SAFE_DIMENSIONS`. Forbidden: `city`, `goal_id`, `offering_id`, `raw_action_text`.

## 43. Action Kind = `BrandExperienceActionKind`

Canonical enum values: `task_completed`, `external_operator_confirmed`. No separate marketing Action Category taxonomy invented. `action_category` column on revisions is `null` for MVP artifact.

## 44. Goal / Offering IDs Forbidden Cross-Brand

Blocked in policy keys and safe dimension registry. Projections never emit `goal_id` / `offering_id`. Brand-scoped Goal/Offering identity must not appear in Sector consumer payloads.

## 45. ReleasedSectorMemoryContextProvider

Implements `SectorMemoryContextProvider`. Returns privacy-released artifact references (`toMemoryPackReference`) when gate disposition is Eligible. Does **not** inject into Agents.

## 46. Null Provider Replaced

`NullSectorMemoryContextProvider` returned empty lists. `ReleasedSectorMemoryContextProvider` now bound in `AppServiceProvider`.

## 47. No Retrieval Injection (Prompt 54)

`IntelligenceMemoryGateway` Memory Pack assembly and Agent prompt injection remain Prompt 54. Prompt 53 provides artifacts + consumer DTO + reference shape only.

## 48. No Vectors / Embeddings

No vector tables, embedding APIs, or similarity search added.

## 49. No Provider / AI Calls

Pipeline is DB-only over Brand Experiences. Zero provider HTTP, zero `laravel/ai` calls.

## 50. No Scheduler

No automatic Sector recompute cron. Release is on-demand via `SectorLearningArtifactService` (and Experience invalidation marks stale).

## 51. Experience Invalidation → Stale Artifacts

`BrandExperienceService::invalidate()` calls `markStaleForExperience()`. Stale artifacts excluded from consumer reads until recomputed.

## 52. Demo Retirement

No Demo sector benchmark fixtures migrated. No fake “winning creative” or industry standard claims.

## 53. Authorization / Access Boundaries

`SectorLearningContributionRepository` is privileged infrastructure — not a generic cross-tenant API. Agents and customer-scoped services must not call it. Consumer reads go through `SectorMemoryReadService`.

## 54. Privacy

Consumer Sector payloads never include contributor Customer/Brand/Experience IDs, names, URLs, keywords, or Experience summaries. Cohort exact counts stay internal; consumer sees `SectorLearningCohortBand` only.

## 55. Security

Lineage table is restricted. No raw DB Sector memory tool. No secrets in artifacts. Tenant isolation preserved — Sector aggregate does not expose per-tenant rows.

## 56. Performance

Indexed sector/status on artifacts; revision uniqueness on `(artifact_id, revision_number)` and `(artifact_id, aggregate_fingerprint)`. Bounded consumer list (default 20, max 50).

## 57. Tests

- `tests/Feature/SectorLearning/SectorLearningPrivacyTest.php`
- `tests/Feature/IntelligenceMemoryArchitectureTest.php` (gate binding, isolation)

## 58. Mandatory Matrices

See compact tables below and architecture docs.

## 59. Reality Matrix

| Capability | Status |
| --- | --- |
| Sector Learning Privacy Policy (`sector_privacy_v1`) | **REAL** |
| Production Sector Privacy Gate | **REAL** |
| Contribution Projection (`sector_projection_v1`) | **REAL** |
| Contribution Bounding (`sector_bounding_v1`) | **REAL** |
| Sector Aggregation (`sector_aggregation_v1`) | **REAL** |
| Sector Learning Artifacts + Revisions | **REAL** |
| Restricted Lineage Entries | **REAL** |
| Consumer DTO / Read Service | **REAL** |
| Released Sector Context Provider | **REAL** |
| Formal k-anonymity / DP claim | **NOT CLAIMED** |
| Privacy score | **NONE** |
| Memory Pack / Agent injection | **NOT YET / Prompt 54** |
| Vectors / embeddings | **NOT IMPLEMENTED** |
| Sector scheduler | **NOT IMPLEMENTED** |
| IndustryBenchmark / SimilarCustomer tables | **NONE** |

See also `MILESTONE_5_PANEL_FREEZE.md` Capability Reality Matrix.

## 60. Prompt 54 Handoff

Own Intelligence Retrieval: server-side `MemoryContextPack` construction from `SectorMemoryConsumerDto` / `toMemoryPackReference()`, bounded selection, citations, Agent run provenance pinning. Prompt 53 artifacts are inputs; injection is **not** implemented here.

## 61. Definition of Done

Prompt 53 satisfied when: versioned privacy policy + production gate replace stub; Brand Experience → projection → bounding → aggregation → artifact release path is real; consumer DTO has no contributor IDs; lineage is restricted; tests pass; docs match implementation; no SectorLearningV2; no formal DP/k-anonymity claims; no Prompt 54 retrieval.

---

## Compact matrices

### Existing Sector Primitive Matrix

| Primitive | Pre-P53 | Post-P53 |
| --- | --- | --- |
| Sector artifact tables | NONE | REAL |
| Privacy gate | Deferred stub | Production |
| Context provider | Null (empty) | Released refs |
| Demo benchmarks | none | none |

### Privacy Policy Matrix

| Rule | Value | Notes |
| --- | --- | --- |
| `min_distinct_brands` | 5 | Gate + release |
| `min_distinct_customers` | 5 | Gate + release |
| `min_categorical_cell_brands` | 3 | Per cell |
| `min_categorical_cell_customers` | 3 | Per cell |
| `min_numeric_aggregate_brands` | 10 | Numeric metrics only |
| `min_numeric_aggregate_customers` | 10 | Numeric metrics only |
| `max_single_brand_effective_share` | 0.20 | Bounder + gate |
| `max_single_customer_effective_share` | 0.20 | Bounder + gate |
| Formal k-anonymity | false | Explicit |
| Differential privacy | false | Explicit |
| Privacy score | null | Explicit |

### Contribution Field Matrix

| Field | In projection? | In consumer DTO? | In lineage? |
| --- | --- | --- | --- |
| `sector_code` | Yes | Yes | No |
| `action_kind` | Yes | Via aggregate | No |
| `outcome_clarity` | Yes | Via aggregate | No |
| `time_bucket` | Yes | Via time_scope | No |
| `brand_id` | Internal only | **No** | Yes |
| `customer_id` | Internal only | **No** | Yes |
| `experience_id` | Internal only | **No** | Yes |
| `situation_summary` | **No** | **No** | **No** |

### Safe Dimension Matrix

| Dimension | Allowed? | Source |
| --- | --- | --- |
| `sector_code` | Yes | IndustryOptions |
| `channel` | Yes | BrandExperienceChannel |
| `market_code` | Yes | CountryOptions ISO |
| `action_kind` | Yes | BrandExperienceActionKind |
| `outcome_clarity` | Yes | BrandExperienceOutcomeClarity |
| `time_bucket` | Yes | Month bucket |
| `city` | **No** | High identifying risk |
| `goal_id` | **No** | Brand-scoped |
| `offering_id` | **No** | Brand-scoped |

### Blocked Identifier Matrix (selected)

| Key | Blocked at |
| --- | --- |
| `customer_id`, `brand_id` | Gate, DTO, aggregate scan |
| `campaign_*`, `ad_*`, `keyword` | Gate |
| `goal_id`, `offering_id` | Policy + projection |
| `situation_summary`, `action_summary`, `outcome_summary` | Policy |
| `free_text`, `notes` | Gate |

### Pipeline Version Matrix

| Stage | Class | Version |
| --- | --- | --- |
| Policy | `SectorLearningPrivacyPolicy` | `sector_privacy_v1` |
| Projection | `SectorLearningContributionProjector` | `sector_projection_v1` |
| Bounding | `SectorLearningContributionBounder` | `sector_bounding_v1` |
| Aggregation | `SectorLearningAggregatorService` | `sector_aggregation_v1` |
| Gate | `ProductionSectorLearningPrivacyGate` | `sector_privacy_v1` |
