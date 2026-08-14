# GOALS & OFFERING IDENTITY PERSISTENCE

## STATUS: PASS

**Prompt:** 37  
**Date:** 2026-08-14  
**Branch:** `cursor/goals-offerings-production-persistence-ea01`  
**Base:** Prompt 36 HEAD `c522c8599cb809138d11ce7cfacff10ce76d56d9`

---

## 1. Purpose

Make Brand Goal and Offering context durable, identity-backed, and referenceable while preserving `BrandIntelligenceContext` as the sole Brand intelligence aggregate.

## 2. Frozen Product Contract

Frozen Filament Brand Intelligence UI (chips/repeaters) is preserved. Backend resolves stable Goal/Offering IDs. Demo Atlas fixtures remain DEMO_ONLY.

## 3. BrandIntelligenceContext Preservation

`BrandIntelligenceContext` remains. No `BrandContextV2` / `BrandStrategyContext` / competing aggregate.

## 4. Existing BIC Field Audit

| Field | Storage | Semantic | Writer | Reader | Identity-bearing? | Production data? | Canonical after P37? | Migrated? | Compatibility projection? | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| business_goals | JSON array `list<{goal,note?}>` | Business outcomes | WriteService / migrator | ReadService / BrandContextProvider | YES | YES | Goal entities (BUSINESS) | YES | YES (one-way) | CONVERGED |
| conversion_goals | JSON array `list<{type,label?,note?}>` | Conversion outcomes | WriteService / migrator | ReadService / BrandContextProvider | YES | YES | Goal entities (CONVERSION) | YES | YES (one-way) | CONVERGED |
| priority_offerings | JSON array `list<string>` ordered | Priority offering names | WriteService / migrator | ReadService / BrandContextProvider | YES | YES | Offering + priority_rank | YES | YES (one-way) | CONVERGED |
| target_audiences | JSON array | Audience context | BIC direct | BIC / ReadService | NO | YES | BIC field | N/A | N/A | PRESERVED |
| target_markets | JSON array | Market context | BIC direct | BIC / ReadService | NO | YES | BIC field | N/A | N/A | PRESERVED |
| products_services | JSON array | Freeform products list | BIC direct | BrandContextProvider | NO (catalog text) | YES | BIC field | N/A | N/A | PRESERVED (not Offering ID) |
| other BIC fields | text/json | summary/model/positioning/etc. | BIC direct | BrandContextProvider | NO | YES | BIC | N/A | N/A | PRESERVED |

## 5. Existing Goal Primitive Audit

| Candidate | Classification |
|---|---|
| BIC `business_goals` / `conversion_goals` | LEGACY → CONVERGED |
| `ConversionGoalTypes` | REUSE (type vocabulary) |
| Demo `CommercialContextFixtures` goals | DEMO_ONLY |
| Eloquent Goal model (pre-P37) | NONE — CREATED `BrandGoal` |

## 6. Existing Offering Primitive Audit

| Candidate | Classification |
|---|---|
| BIC `priority_offerings` | LEGACY → CONVERGED |
| BIC `products_services` | REUSE as freeform BIC (not identity) |
| MoxDOP `ServiceDefinition` / Service Scope | DISTINCT — not Offering |
| Demo fixtures offerings | DEMO_ONLY |
| Eloquent Offering model (pre-P37) | NONE — CREATED `BrandOffering` + `BrandOfferingName` |

## 7. Canonical Truth Decision

| Question | Canonical |
|---|---|
| What is the Goal? | `brand_goals.id` |
| Goal kind? | `brand_goals.kind` (`business` / `conversion`) |
| Goal applies to? | `applicability_mode` + `brand_goal_offering` |
| What is the Offering? | `brand_offerings.id` |
| Primary name? | `brand_offering_names` where `is_primary` |
| Aliases? | non-primary active name claims |
| Priority? | `brand_offerings.priority_rank` |
| Priority order? | ascending `priority_rank` |
| Legacy write allowed? | NO after insert (projection flag only) |

## 8–11. Goal Identity / Kinds / Lifecycle

- Table: `brand_goals` (Brand-scoped)
- Kinds: BUSINESS, CONVERSION (distinct; same label allowed across kinds)
- Status: ACTIVE / ARCHIVED (no ordinary hard delete)
- Rename preserves ID; unique `(brand_id, kind, normalized_key)`

## 12–15. Offering Identity / Names

- Table: `brand_offerings` + `brand_offering_names`
- Brand-scoped; cross-Brand sharing forbidden; same display name in two Brands allowed
- Name claim unique `(brand_id, normalized_key)` across primary + aliases
- Rename preserves Offering ID; former primary retained as `former_primary`

## 16–19. Normalization / Duplicate Prevention / Concurrency

Algorithm **v1** (`IdentityLabelNormalizer`):

1. Unicode NFC  
2. Trim + collapse Unicode whitespace  
3. `mb_strtolower` UTF-8  
4. NFC again; canonicalize `i`+U+0307 → `i` (İ/i)

**Not applied:** Turkish locale I→ı (breaks English “LIFT”), translation, transliteration, stemming, diacritic stripping, fuzzy/AI.

DB unique claim + `UniqueConstraintViolationException` handling for concurrency.

## 20–23. Rename / Priority / Applicability / Relations

- Priority: `priority_rank` nullable; deprioritize ≠ archive  
- Archived offerings cannot stay priority  
- Applicability: `brand_wide` (empty pivot required) vs `specific_offerings` (≥1 same-Brand Offering)  
- No generic polymorphic relationship graph  

## 24–27. Legacy Migration / Projection / Write / Read

- Command: `php artisan bic:migrate-goals-offerings`  
- Idempotent; structural collapse; semantic variants NOT merged  
- Projection: entities → BIC JSON only (`withLegacyIdentityProjection`)  
- Write: `BrandIntelligenceContextWriteService`, `BrandGoalService`, `BrandOfferingService`  
- Read: `BrandIntelligenceContextReadService` (+ `BrandContextProvider` exposes stable IDs)

## 28–34. Auth / Activity / Boundaries / Demo

- Brand/Customer tenancy via Brand ownership; forged cross-Brand relations rejected  
- Activity: `brand_context_activities` (`GOAL_*`, `OFFERING_*`, `BIC_*`)  
- Offering ≠ Service / WebsitePage / Campaign / Keyword / DigitalAsset  
- Goal ≠ provider conversion / KPI / Business Outcome  
- Creates 0 Evidence / Findings / Opportunities / Recommendations / Tasks / AI  
- Production empty = empty; no Demo fallback on production reads  

## 35. Tests

`tests/Feature/GoalsOfferings/GoalsOfferingsIdentityPersistenceTest.php`  
Regression: `BrandIntelligenceContextTest`, Service Scope suite.

## 36. Reality Matrix

| Capability | State |
|---|---|
| Brand / BIC | REAL / PRESERVED |
| Target Audiences / Markets | PRESERVED |
| Business / Conversion Goal Identity | REAL |
| Goal Kind / Lifecycle / Applicability | REAL |
| Offering Identity / Names / Aliases / Priority | REAL |
| Duplicate prevention / concurrency | REAL |
| Legacy BIC identity arrays | CONVERGED / PROJECTION |
| Demo fallback | NONE |
| Service Scope | REAL (distinct) |
| Canonical Evidence / Findings / AI | NOT YET |

## 37. Prompt 38 Handoff

Evidence Canonicalization may reference Goal ID / Offering ID.

## 38. Definition of Done

All Prompt 37 DoD invariants satisfied for identity persistence without BIC replacement or semantic auto-merge.
