# MOXDOP INTELLIGENCE RETRIEVAL LAYER

## STATUS: REAL (Prompt 54)

**Prompt:** 54  
**Canonical path:** `docs/implementation/INTELLIGENCE_RETRIEVAL_LAYER.md`  
**Context pack contract:** [`docs/architecture/INTELLIGENCE_CONTEXT_PACK_CONTRACT.md`](../architecture/INTELLIGENCE_CONTEXT_PACK_CONTRACT.md)  
**Retrieval policy:** [`docs/architecture/INTELLIGENCE_RETRIEVAL_POLICY.md`](../architecture/INTELLIGENCE_RETRIEVAL_POLICY.md)  
**Depends on:** Prompt 51 Intelligence Memory Architecture · Prompt 52 Brand Experience Records · Prompt 53 Sector Learning & Privacy · Prompt 50 AI Agent Production Execution  
**Branch:** `cursor/intelligence-retrieval-layer-ea01`  
**Base HEAD:** Prompt 53 sector learning (`1a34832`)

| Fact | Value |
| --- | --- |
| Intelligence Retrieval orchestrator | **REAL** (`IntelligenceRetrievalService`) |
| Retrieval policy version | `intelligence_retrieval_v1` |
| Typed context pack | **REAL** (`IntelligenceContextPack` / `TypedMemoryContextPack`) |
| Gateway Memory Pack resolution | **REAL** (`IntelligenceMemoryGateway::resolveMemoryContextPack`) |
| Website Agent injection | **REAL** (`WebsiteAiRecommendationService` → `MEMORY_CONTEXT_JSON`) |
| Numeric relevance scores | **NONE** |
| Vector DB / embeddings / similarity | **NOT IMPLEMENTED** |
| RetrievalV2 | **NONE** |
| Fine-tuning | **NONE** |
| Agent memory tools / LLM retrieval | **FORBIDDEN** |
| Provider / AI calls during retrieval | **0** |
| Retrieval evaluation | **NOT YET** / Prompt 55 |

---

## 1. Purpose

Implement server-side **Intelligence Retrieval** — deterministic, policy-bounded assembly of typed context for Agent inference — without vectors, embeddings, numeric relevance scores, fine-tuning, Agent memory tools, or a second retrieval framework (`RetrievalV2`).

## 2. Why Server-Side Retrieval (Not RAG / Vectors)

MoxDOP Memory is layered (Brand / Sector / Skill) with strict privacy and authority rules. Unrestricted semantic search would violate cross-Brand isolation, Sector privacy, and Evidence authority. Prompt 54 uses **structured filters + lexicographic ordering + explicit match reasons**, not text similarity or embedding ranking.

## 3. Existing Retrieval Primitive Audit

| Primitive | Path | Decision |
| --- | --- | --- |
| `IntelligenceMemoryGateway::resolveMemoryContextPack` | P51 stub (empty pack) | **Superseded — REAL** (P54) |
| `MemoryContextPack` | P51 DTO | **Bridged** via `TypedMemoryContextPack::toLegacyMemoryContextPack()` |
| `ExperienceBrandMemoryContextProvider` | P52 read refs | **Input source** — not retrieval orchestrator |
| `ReleasedSectorMemoryContextProvider` | P53 refs | **Input source** — not Agent injection |
| `SectorMemoryReadService` | P53 consumer read | **Used by** `SectorPatternRetriever` |
| RAG / vector index / RetrievalV2 | none | **NONE** |
| Agent memory browse tools | none | **FORBIDDEN** |

## 4. Existing Vector / Embedding Audit

| Item | Status |
| --- | --- |
| pgvector / Pinecone / Qdrant / Weaviate / Milvus | **NOT IMPLEMENTED** |
| `createEmbedding()` / cosine similarity in retrieval | **NOT IMPLEMENTED** |
| Text similarity in `BrandExperienceRetriever` | **NOT IMPLEMENTED** |
| `IntelligenceRetrievalPolicy::snapshot()['embeddings']` | `false` |
| `IntelligenceRetrievalPolicy::snapshot()['vector_db']` | `false` |

## 5. Frozen Product Surface Audit

No new Filament/Livewire retrieval UI, memory browser, or Agent tool palette. Retrieval runs inside application services before inference. Settings registries unchanged.

## 6. Canonical Intelligence Retrieval Decision

One orchestrator: `App\Services\IntelligenceRetrieval\IntelligenceRetrievalService`.  
One policy version: `intelligence_retrieval_v1`.  
One typed output: `IntelligenceContextPack`.  
**No RetrievalV2.**

## 7. IntelligenceContextPack vs EvidencePack

| Pack | Owner | Authority | Substitutable? |
| --- | --- | --- | --- |
| `EvidencePack` (P50) | Prompt 50 gateway | Current canonical Evidence | Required facts — Memory **cannot** replace |
| `IntelligenceContextPack` (P54) | Retrieval service | Mixed sections with explicit authority labels | Wraps Evidence + Memory sections separately |

Evidence is passed through unchanged. Memory sections are additive context only.

## 8. TypedMemoryContextPack

`TypedMemoryContextPack` holds the three Memory layers as typed item lists:

- `brandExperiences` → `BrandExperienceContextItem[]`
- `sectorPatterns` → `SectorPatternContextItem[]`
- `skillKnowledge` → `SkillKnowledgeContextItem[]`

Distinct from `EvidencePack`. Immutable. Serialized budget applies to Memory sections only.

## 9. IntelligenceRetrievalService Orchestrator

`IntelligenceRetrievalService::retrieve()` is the canonical entry point.

Resolves, in order:

1. Brand scope validation (`customerId` / `brandId`)
2. `SkillMemoryContract` (+ optional overrides)
3. `AgentMemoryPermission` (+ optional overrides)
4. `SkillRetrievalContract`
5. Section retrievers + decisions
6. Memory fingerprint + budget check
7. `IntelligenceContextPack` with `retrievalFingerprint`

The LLM participates in **none** of these steps.

## 10. IntelligenceRetrievalPolicy (`intelligence_retrieval_v1`)

Versioned packaging mechanics in `App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy`.

| Constant | Value |
| --- | --- |
| `POLICY_ID` | `intelligence_retrieval` |
| `VERSION` | `intelligence_retrieval_v1` |
| `HARD_MAX_BRAND_EXPERIENCES` | 10 |
| `HARD_MAX_SECTOR_PATTERNS` | 5 |
| `HARD_MAX_SKILL_KNOWLEDGE` | 10 |
| `HARD_MAX_MEMORY_SERIALIZED_BYTES` | 48000 |
| `DEFAULT_EXPERIENCE_ORDER` | lexicographic keys (see policy doc) |

`snapshot()` explicitly sets `numeric_relevance_score: null`, `embeddings: false`, `vector_db: false`, `fine_tuning: false`, `llm_ranking: false`.

## 11. SkillRetrievalContract

`App\Support\IntelligenceRetrieval\SkillRetrievalContract` declares contextual classes a Skill may receive:

- Wraps `SkillMemoryContract`
- Declares match dimensions (`experienceMatchDimensions`, `sectorMatchDimensions`)
- Declares goal inclusion rules (`includeGoals`, `goalsRequired`, `maxGoals`, `allowBrandWideGoals`)
- Declares allowed Experience quality states (`sufficient`, `partial` by default)

Absent Skill Memory contract ⇒ empty Memory (fail closed).

## 12. SkillMemoryContract Intersection

`SkillMemoryContract` (P51) declares **which layers** a Skill requests and per-layer `maximumRetrievalCount` / `required`.

Retrieval enforces: if `!$memoryContract->requests(layer)` → section `NOT_REQUESTED`.

Skill contract is resolved via `SkillMemoryContractResolver` unless test/runtime override supplied.

## 13. AgentMemoryPermission Intersection

`AgentMemoryPermission` (P51) is the Agent **upper bound**.

Rules:

- Agent cannot expand Skill contract — if Skill requests nothing, Agent allowance is irrelevant (`test_agent_cannot_expand_skill_memory`)
- If Skill requests layer but Agent disallows → `NOT_ALLOWED` / `AgentLayerNotAllowed`
- Operational specialists (`website-seo-analyst`, `google-ads-analyst`, `meta-ads-analyst`, `brand-discovery-analyst`) may allow all three layers when Skill opts in (`AgentMemoryPermissionCatalog`)

## 14. BrandExperienceRetriever

`BrandExperienceRetriever` — same-Brand structured retrieval only.

| Rule | Implementation |
| --- | --- |
| Scope | `BrandMemoryScope(customerId, brandId)` exact match on `brand_experiences` |
| Status | `confirmed` only |
| Quality | `allowedExperienceQualityStates`; excludes `insufficient` |
| Matching | Structured dimensions: goal / market / channel / action_kind — **no text similarity** |
| Ordering | Lexicographic on `IntelligenceRetrievalPolicy::DEFAULT_EXPERIENCE_ORDER` |
| Match reasons | `CONFIRMED_ELIGIBLE`, `EXACT_GOAL_MATCH`, `EXACT_MARKET_MATCH`, `EXACT_CHANNEL_MATCH`, etc. |
| Text | Summaries bounded to 400 chars (`Str::limit`) |
| Opaque ref | `brand_experience:{id}` |

Cross-Brand and same-Customer-other-Brand return empty lists (scope query).

## 15. SectorPatternRetriever

`SectorPatternRetriever` — Prompt 53 **consumer DTO only**.

| Rule | Implementation |
| --- | --- |
| Input | `SectorMemoryReadService::listReleasedForSector()` → `SectorMemoryConsumerDto` |
| Identity | `SectorIdentityResolver` — rejects AI-inferred sector |
| Privacy | Consumer DTO `privacyDisposition.isEligible()`; no lineage / contributor IDs |
| Cross-brand | Never reads other Brands' Experiences directly |
| Match reasons | `CURRENT_SECTOR_MATCH`, `PRIVACY_RELEASED`, optional `EXACT_CHANNEL_MATCH` |
| Opaque ref | `sector_artifact:{artifactStableKey}` |

Does not expose `sector_learning_lineage_entries` to Agent context.

## 16. RelevantGoalRetriever

`RelevantGoalRetriever` — Prompt 37 `BrandGoal` identity only.

| Rule | Implementation |
| --- | --- |
| Source | Active `BrandGoal` rows for exact `brand_id` |
| Explicit IDs | `explicit_goal_ids` option filters with `whereIn` |
| No keyword inference | No text search / embedding match on labels |
| Required single goal | If `goalsRequired && maxGoals === 1` and multiple active goals without explicit ID → `BLOCKED` / `GoalSelectionRequired` |
| Ordering | `sort_order`, then `id` |

Retrieved goals feed Brand Experience filters (`goal_ids`) when present.

## 17. IntelligenceContextReferenceValidator

Separates Evidence refs from Memory refs.

| Check | Error |
| --- | --- |
| Claimed Evidence ID not in pack Evidence | `UNKNOWN_EVIDENCE_REF` |
| Claimed Memory ref not in allowed pack refs | `UNKNOWN_MEMORY_REF` |
| Memory ref prefix used as Evidence | `MEMORY_REF_USED_AS_EVIDENCE` |
| Contributor / lineage refs | `SECTOR_CONTRIBUTOR_REF_FORBIDDEN` |

Allowed Memory refs: `brand_experience:*`, `sector_artifact:*`, skill knowledge `opaqueRef` values from the pack.

## 18. IntelligenceMemoryGateway.resolveMemoryContextPack

`IntelligenceMemoryGateway` now delegates to `IntelligenceRetrievalService`:

```php
$pack = $this->intelligenceRetrievalService->retrieve(...);
return $pack->memoryContextPack->toLegacyMemoryContextPack();
```

`evaluate()` sets `retrievalImplemented: true` with notes confirming structured deterministic selection only.

## 19. WebsiteAiRecommendationService Integration

Prompt 54 insertion in the Website operational Agent path:

```text
ContextBuilder → EvidencePack (P50)
  → IntelligenceRetrievalService::retrieve(evidencePack: $pack, ...)
  → blocksInference()? abstain pre-inference (no provider call)
  → renderPrompt(..., $intelligencePack->toPromptSections())
  → MEMORY_CONTEXT_JSON section in prompt
  → intelligence_retrieval_manifest pinned on Run / Evidence / AgentExecutionRun
```

Flow: **EvidencePack → Retrieval → prompt `MEMORY_CONTEXT_JSON`**.

Other operational Agents (Google Ads, Meta Ads, Brand Discovery) remain on the P50 path until wired similarly.

## 20. Current Brand Context Section

Resolved from Brand + optional `DigitalAsset` + bounded `BrandIntelligenceContextReadService` output. Authority: `CURRENT_CANONICAL_CONTEXT`. Website path may override via `current_brand_context` option with module ContextBuilder payload.

## 21. Evidence Section (Prompt 50 Reuse)

When `EvidencePack` provided → section `INCLUDED` with metadata:

- `memory_cannot_substitute: true`
- `sector_cannot_substitute: true`
- `skill_knowledge_cannot_substitute: true`

Evidence IDs and fingerprint come from the P50 pack — never recomputed from Memory.

## 22. Goals Section

Included when `SkillRetrievalContract.includeGoals`. Payload: goal `id`, `label`, `kind`, `status`, `normalized_key`. Used for Experience dimension matching, not as Memory.

## 23. Exact Skill Section

Single Skill signature for this run from `SkillRegistry` — **not** full catalog. Metadata: `full_catalog_not_included: true`. Match reason: `SKILL_EXPLICIT_REFERENCE`.

## 24. Brand Experience Selection Pipeline

```
Skill requests Brand layer?
  → Agent allows Brand?
    → BrandExperienceRetriever.retrieve(scope, contract, filters)
      → filter confirmed + quality
      → compute structured match reasons + priority keys
      → usort lexicographic
      → slice to min(skill max, policy hard max, retriever limit)
```

Decisions: `INCLUDED`, `SELECTED_WITH_LIMIT`, `UNAVAILABLE`, `REQUIRED_MISSING`, `NOT_REQUESTED`, `NOT_ALLOWED`.

## 25. Sector Pattern Selection Pipeline

```
Skill requests Sector layer?
  → Agent allows Sector?
    → canonical sector identity present?
      → listReleasedForSector (consumer DTO)
      → filter eligible disposition
      → slice to limits
```

No broader sector fallback. No cross-brand Experience reads.

## 26. Skill Knowledge Selection Pipeline

When Skill + Agent allow Skill layer:

- `SkillKnowledgeContextProvider::listGeneralKnowledgeReferences(signature, bound)`
- Bound = `min(HARD_MAX_SKILL_KNOWLEDGE, skill maximumRetrievalCount, default 5)`
- Items: opaque ref + citation + revision + `SKILL_EXPLICIT_REFERENCE`

Customer-free guard remains upstream in provider.

## 27. Lexicographic Ordering (No Text Similarity)

Brand Experience ordering uses explicit priority keys aligned to `DEFAULT_EXPERIENCE_ORDER`:

`exact_goal` → `exact_offering` → `exact_market` → `exact_channel` → `exact_action_kind` → `quality_class` → `recency` → `stable_id` (ascending tie-break).

No BM25, no embedding distance, no LLM reranking.

## 28. Match Reasons (Not Scores)

`IntelligenceMatchReason` enum documents **why** an item was selected — not how relevant it is numerically.

Examples: `EXACT_GOAL_MATCH`, `CURRENT_SECTOR_MATCH`, `PRIVACY_RELEASED`, `CONFIRMED_ELIGIBLE`, `SKILL_EXPLICIT_REFERENCE`.

`numeric_relevance_score` is always `null` in pack metadata and manifest.

## 29. Retrieval Decisions & Reason Codes

`IntelligenceRetrievalDecision` per section: `INCLUDED`, `NOT_REQUESTED`, `NOT_ALLOWED`, `NOT_APPLICABLE`, `UNAVAILABLE`, `BLOCKED`, `SELECTED_WITH_LIMIT`, `REQUIRED_MISSING`.

`IntelligenceRetrievalReasonCode` explains denials: `SKILL_DOES_NOT_REQUEST`, `AGENT_LAYER_NOT_ALLOWED`, `GOAL_SELECTION_REQUIRED`, `NO_CANONICAL_SECTOR`, `NO_RELEASED_SECTOR_PATTERN`, `CONTEXT_BUDGET_EXCEEDED`, etc.

`RetrievalSectionDecision::blocksInference()` is true for `REQUIRED_MISSING` and `BLOCKED`.

## 30. Source Authority Classes

`IntelligenceSourceAuthority` labels each section:

| Authority | Sections |
| --- | --- |
| `CURRENT_CANONICAL_CONTEXT` | current brand, goals |
| `CURRENT_CANONICAL_EVIDENCE` | evidence |
| `HISTORICAL_BRAND_EXPERIENCE` | brand experiences |
| `PRIVACY_AGGREGATED_SECTOR_CONTEXT` | sector patterns |
| `GENERAL_SKILL_KNOWLEDGE` | exact skill, skill knowledge |

Prompt sections label Memory as **data, not instructions** (`toPromptSections()`).

## 31. Context Budgeting

Serialized Memory JSON (`TypedMemoryContextPack::toArray()`) must be ≤ `HARD_MAX_MEMORY_SERIALIZED_BYTES` (48000).

If exceeded:

- Section decision `context_budget` → `BLOCKED` / `ContextBudgetExceeded`
- Deterministic reduction: **clear all Memory items** (brand/sector/skill arrays empty)
- Evidence is **never** evicted (`required_evidence_never_evicted: true`)

Per-layer count limits still apply before budget check.

## 32. Fingerprints & Provenance

| Fingerprint | Inputs |
| --- | --- |
| `memoryContextPack.contextFingerprint` | policy version + experience revision IDs + sector artifact keys + skill refs |
| `retrievalFingerprint` | policy + agent/skill signatures + tenant scope + evidence fingerprint + memory fingerprint + goal IDs |

Pinned on Agent runs via `toManifestArray()` alongside Evidence pack fingerprint.

## 33. Memory Cannot Substitute Evidence

Enforced in:

- Retrieval metadata flags on Evidence section
- `IntelligenceContextReferenceValidator`
- Prompt safety rules in `WebsiteAiRecommendationService` (`MEMORY_CONTEXT_JSON` untrusted; Memory refs cannot satisfy Evidence requirements)

## 34. Cross-Brand Isolation

`BrandExperienceRetriever` queries `where('brand_id', $scope->brandId)` and `where('customer_id', $scope->customerId)`. Cross-Brand Experiences never appear in pack (`test_cross_brand_experience_not_retrieved`).

## 35. Same-Customer Other-Brand Forbidden

Even under the same Customer, Brand B cannot retrieve Brand A Experiences (`test_same_customer_other_brand_forbidden`).

## 36. Sector Privacy Consumer-Only

Sector items embed `SectorMemoryConsumerDto` only. Serialized prompt JSON must not contain `contributor`, `lineage`, `customer_id`, or `brand_id` keys (`test_sector_retrieval_uses_released_artifacts_only`).

## 37. No Agent Memory Tools

Agents do not receive browse/search/write memory tools. Retrieval is application-side only before `laravel/ai` prompt call.

## 38. No LLM in Retrieval

`IntelligenceRetrievalService` and retrievers perform **zero** provider calls. `retrievalMetadata.provider_calls_during_retrieval = 0`.

## 39. No RetrievalV2

No `RetrievalV2` class, namespace, or parallel framework. Tests assert class does not exist.

## 40. No Fine-Tuning

`retrievalMetadata.fine_tuning = false`. No fine-tune API usage in retrieval services.

## 41. No Vectors / Embeddings

Policy snapshot and service scan tests forbid embedding APIs and vector stores in `app/Services/IntelligenceRetrieval/`.

## 42. Provider Calls During Retrieval = 0

Retrieval is pure PHP + Eloquent/read services. AI inference happens only after pack assembly (Website path).

## 43. blocksInference Semantics

When any section blocks inference, Website path abstains pre-inference:

- `AgentExecutionRun` → `ABSTAINED`
- No `laravel/ai` call
- Manifest includes `intelligence_retrieval_manifest`

## 44. Prompt Injection Labels

`IntelligenceContextPack::toPromptSections()` emits labelled sections:

`CURRENT_BRAND_CONTEXT`, `CURRENT_EVIDENCE`, `RELEVANT_GOALS`, `EXACT_SKILL`, `HISTORICAL_BRAND_EXPERIENCE`, `SECTOR_AGGREGATE_CONTEXT`, `GENERAL_METHODOLOGY`, `RETRIEVAL_METADATA`.

Historical / sector sections carry explicit non-authority labels.

## 45. Legacy MemoryContextPack Bridge

`TypedMemoryContextPack::toLegacyMemoryContextPack()` maps typed items to P51 ref arrays for gateway consumers and architectural tests expecting `MemoryContextPack`.

## 46. Security

Fail closed on unknown Agent signatures (`AgentMemoryPermission::none`). Brand/customer scope mismatch throws `InvalidArgumentException`. Validator rejects forbidden contributor refs.

## 47. Privacy

Sector retrieval never exposes contributor identities. Brand Experience text is bounded. Pack manifest sets `sector_contributor_identities: null`.

## 48. Performance

Retrieval is synchronous, bounded by hard caps and single-brand queries. No N+1 vector searches. Sector list capped at policy max.

## 49. Tests

`tests/Feature/IntelligenceRetrieval/IntelligenceRetrievalLayerTest.php` covers:

- Policy has no scores/vectors
- Skill without contract → empty memory
- Same-Brand Experience match reasons
- Cross-Brand / same-Customer-other-Brand isolation
- Sector consumer DTO only
- Agent cannot expand Skill
- Deterministic fingerprints
- Memory/Evidence ref separation
- Gateway `retrievalImplemented`
- No fine-tuning/vector classes
- Exact skill only (not full catalog)

## 50. Mandatory Matrices

See §51 Reality Matrix and architecture policy/context pack contracts. Cross-reference P51 matrices in `INTELLIGENCE_MEMORY_ARCHITECTURE.md` §241–258.

### Skill ∩ Agent ∩ Layer Matrix

| Skill requests | Agent allows | Result |
| --- | --- | --- |
| none | any | Empty memory (`NOT_REQUESTED`) |
| Brand | no | `NOT_ALLOWED` |
| Brand | yes | Retrieve same-Brand Experiences |
| Sector | no | `NOT_ALLOWED` |
| Sector | yes + eligible sector artifact | Consumer DTO items |
| Skill | yes | General knowledge refs |

### Authority vs Substitutability Matrix

| Section | Can override Evidence? | Can override Goals? |
| --- | --- | --- |
| CURRENT_EVIDENCE | — (authority) | No |
| HISTORICAL_BRAND_EXPERIENCE | **No** | No |
| SECTOR_AGGREGATE_CONTEXT | **No** | No |
| GENERAL_METHODOLOGY | **No** | No |

## 51. Reality Matrix

| Capability | Status |
| --- | --- |
| Intelligence Retrieval orchestrator | **REAL** |
| `intelligence_retrieval_v1` policy | **REAL** |
| `IntelligenceContextPack` / `TypedMemoryContextPack` | **REAL** |
| `SkillRetrievalContract` | **REAL** |
| SkillMemoryContract ∩ AgentMemoryPermission | **REAL** |
| `BrandExperienceRetriever` (same-Brand, lexicographic) | **REAL** |
| `SectorPatternRetriever` (P53 consumer DTO) | **REAL** |
| `RelevantGoalRetriever` (BrandGoal identity) | **REAL** |
| `IntelligenceContextReferenceValidator` | **REAL** |
| Gateway `resolveMemoryContextPack` | **REAL** |
| Website `MEMORY_CONTEXT_JSON` injection | **REAL** |
| Numeric relevance scores | **NONE** |
| Vector DB / embeddings / similarity | **NOT IMPLEMENTED** |
| RetrievalV2 | **NONE** |
| Fine-tuning | **NONE** |
| Agent memory tools | **FORBIDDEN** |
| Retrieval evaluation / ranking QA | **NOT YET** / Prompt 55 |

## 52. Prompt 55 Handoff

Own **retrieval evaluation** — measuring whether selected context improved grounded Agent outcomes. Prompt 54 delivers deterministic selection + manifests + tests; Prompt 55 owns evaluation metrics, governance loops, and any future **non-vector** ranking policy changes. Prompt 54 does **not** implement evaluation.

## 53. Relationship to Prompt 51 / 52 / 53

| Prompt | Role for P54 |
| --- | --- |
| 51 | Layer contracts, gateway, access policy, empty pack shape |
| 52 | Brand Experience content + quality states |
| 53 | Released sector artifacts + consumer DTO + privacy gate |
| 54 | Orchestration, typed pack, injection, budgeting |

## 54. Code Map

| Area | Path |
| --- | --- |
| Orchestrator | `app/Services/IntelligenceRetrieval/IntelligenceRetrievalService.php` |
| Retrievers | `app/Services/IntelligenceRetrieval/{BrandExperience,SectorPattern,RelevantGoal}Retriever.php` |
| Validator | `app/Services/IntelligenceRetrieval/IntelligenceContextReferenceValidator.php` |
| Policy | `app/Support/IntelligenceRetrieval/IntelligenceRetrievalPolicy.php` |
| Contracts | `app/Support/IntelligenceRetrieval/SkillRetrievalContract.php` |
| DTOs | `app/Support/IntelligenceRetrieval/Dto/*` |
| Enums | `app/Enums/Intelligence{MatchReason,RetrievalDecision,RetrievalReasonCode,SourceAuthority}.php` |
| Gateway | `app/Services/IntelligenceMemory/IntelligenceMemoryGateway.php` |
| Agent permissions | `app/Support/IntelligenceMemory/AgentMemoryPermissionCatalog.php` |
| Website injection | `app-modules/website/src/Ai/WebsiteAiRecommendationService.php` |
| Tests | `tests/Feature/IntelligenceRetrieval/IntelligenceRetrievalLayerTest.php` |

## 55. Operational Agent Permissions

`AgentMemoryPermissionCatalog` grants Brand+Sector+Skill upper bound only to operational analyst slugs when Skill opts in. Designed Agents (GBP/GA4/GSC) remain `none`. Agent permission never widens Skill contract.

## 56. Agent Run Manifest Pinning

`IntelligenceContextPack::toManifestArray()` records:

- `retrieval_policy_version`, `retrieval_fingerprint`, `memory_context_fingerprint`
- `evidence_pack_fingerprint`, `goal_ids`
- `brand_experience_revision_ids`, `sector_artifact_refs`, `skill_knowledge_refs`
- per-section `decisions`
- explicit `numeric_relevance_score: null`, `sector_contributor_identities: null`

Website service stores this on abstain and success paths.

## 57. Definition of Done

Prompt 54 is satisfied when:

- `IntelligenceRetrievalService` orchestrates typed retrieval with policy version `intelligence_retrieval_v1`
- SkillMemoryContract ∩ AgentMemoryPermission enforced; Agent cannot expand Skill
- Same-Brand Experience retrieval uses lexicographic match reasons — no text similarity
- Sector retrieval uses Prompt 53 consumer DTO only — no lineage/contributor IDs
- Goals use BrandGoal identity — no keyword inference
- `IntelligenceContextPack` / `TypedMemoryContextPack` implemented with prompt + manifest serializers
- `IntelligenceContextReferenceValidator` separates Evidence vs Memory refs
- `IntelligenceMemoryGateway::resolveMemoryContextPack` is real
- `WebsiteAiRecommendationService` wires EvidencePack → Retrieval → `MEMORY_CONTEXT_JSON`
- No RetrievalV2, vectors, embeddings, fine-tuning, or Agent memory tools
- Tests pass; docs match implementation
- Evaluation explicitly deferred to Prompt 55
