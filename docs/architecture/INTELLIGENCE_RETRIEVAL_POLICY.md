# Intelligence Retrieval Policy

> Prompt 54 — filtering, matching, ordering, budgeting, no-score semantics, versioning.  
> Code: `App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy`  
> Implementation: `docs/implementation/INTELLIGENCE_RETRIEVAL_LAYER.md`

**Policy ID:** `intelligence_retrieval`  
**Version:** `intelligence_retrieval_v1`

This policy owns **packaging mechanics** — not Skill semantic relevance formulas. Skills declare what they need; policy enforces hard ceilings and deterministic ordering.

---

## Versioning

| Rule | Detail |
| --- | --- |
| Constant | `IntelligenceRetrievalPolicy::VERSION = 'intelligence_retrieval_v1'` |
| Snapshot | `IntelligenceRetrievalPolicy::snapshot()` embedded in pack metadata |
| Pinning | `retrievalFingerprint` includes policy version |
| Breaking changes | Require new version string — do not silently alter v1 behavior |

No `RetrievalV2` policy namespace. Future changes bump version explicitly.

---

## No-Score Semantics

| Mechanism | Allowed? |
| --- | --- |
| Numeric relevance score | **NO** — always `null` |
| Weighted ranking formula | **NO** |
| Embedding similarity | **NO** |
| LLM reranking | **NO** |
| Lexicographic ordering + match reason codes | **YES** |

`IntelligenceMatchReason` explains **why** an item matched — not **how much** it matched.

---

## Access Filtering (Skill ∩ Agent ∩ Policy)

Retrieval runs only after tenant scope validation.

### Layer gate

```
IF NOT skillMemoryContract.requests(layer):
  → NOT_REQUESTED / SkillDoesNotRequest

IF NOT agentPermission.allows(layer):
  → NOT_ALLOWED / AgentLayerNotAllowed

ELSE run layer retriever
```

Agent permission is an **upper bound**. Skill contract is the **request**. Intersection is empty unless both allow.

### Brand layer filters

| Filter | Rule |
| --- | --- |
| Scope | Exact `customer_id` + `brand_id` |
| Experience status | `confirmed` only |
| Quality | `allowedExperienceQualityStates` on contract (default `sufficient`, `partial`) |
| Insufficient support | excluded even if listed in allowed states guard |
| Cross-brand | forbidden at query level |

### Sector layer filters

| Filter | Rule |
| --- | --- |
| Sector identity | Operator-confirmed; reject `aiInferred` |
| Artifact source | Released consumer DTO via `SectorMemoryReadService` |
| Privacy disposition | `isEligible()` on consumer DTO |
| Lineage / contributors | never loaded into pack |
| Broader sector fallback | **NO** (`no_broader_sector_fallback: true`) |

### Skill knowledge filters

| Filter | Rule |
| --- | --- |
| Provider | `SkillKnowledgeContextProvider` references only |
| Customer data guard | upstream on provider |
| Catalog scope | current Skill signature only |

---

## Matching Dimensions

Configured on `SkillRetrievalContract` — retriever applies structured equality only.

### Brand Experience (`experienceMatchDimensions`)

Default: `goal`, `market`, `channel`, `action_kind`

| Dimension | Match when |
| --- | --- |
| `goal` | Experience revision goal IDs intersect filter `goal_ids` |
| `market` | `revision.market_code === filter market_code` |
| `channel` | `revision.channel === filter channel` |
| `action_kind` | included in ordering priority (not a hard filter in v1) |

**No** full-text similarity on summaries. **No** embedding nearest neighbors.

Match reasons appended: `EXACT_GOAL_MATCH`, `EXACT_MARKET_MATCH`, `EXACT_CHANNEL_MATCH`, plus baseline `CONFIRMED_ELIGIBLE`.

### Sector patterns (`sectorMatchDimensions`)

Default: `sector_code`, `channel`

| Dimension | Match when |
| --- | --- |
| `sector_code` | artifact listed for resolved sector |
| `channel` | soft channel tag on aggregate cells when filter present |

Baseline reasons: `CURRENT_SECTOR_MATCH`, `PRIVACY_RELEASED`.

### Goals

| Mode | Behavior |
| --- | --- |
| Explicit `explicit_goal_ids` | `whereIn('id', ...)` |
| Brand-wide | active goals ordered by `sort_order`, `id` |
| Required single goal | must pass explicit ID if multiple active |

**No** keyword / label inference.

---

## Ordering

### Brand Experience — lexicographic

Order keys (`DEFAULT_EXPERIENCE_ORDER`):

1. `exact_goal` (desc)
2. `exact_offering` (desc)
3. `exact_market` (desc)
4. `exact_channel` (desc)
5. `exact_action_kind` (desc)
6. `quality_class` (desc — sufficient > partial)
7. `recency` (desc — `outcome_observed_at` timestamp)
8. `stable_id` (asc — deterministic tie-break)

Implemented via `usort` comparing priority vectors key-by-key.

### Sector patterns

Released artifact list order from read service, then `array_slice` to limit. No similarity rerank.

### Skill knowledge

Provider reference order truncated to bound.

### Goals

`sort_order`, then `id` — no ranking score.

---

## Budgeting

### Per-layer count ceilings

| Layer | Hard max | Skill override |
| --- | --- | --- |
| Brand Experience | 10 | `SkillMemoryLayerRequirement.maximumRetrievalCount` (lower wins) |
| Sector patterns | 5 | same |
| Skill knowledge | 10 | same |

Effective limit: `min(retriever default, skill max, policy hard max)`.

When candidates exceed limit → decision `SELECTED_WITH_LIMIT` + `ContextBudgetExceeded` reason code + `omittedCount`.

### Serialized size ceiling

| Constant | Value |
| --- | --- |
| `HARD_MAX_MEMORY_SERIALIZED_BYTES` | 48000 |

Applied to JSON of `TypedMemoryContextPack::toArray()` **excluding** Evidence.

On exceed:

1. Add `context_budget` decision `BLOCKED`
2. Replace memory pack with **empty** brand/sector/skill arrays
3. Recompute memory fingerprint with `budget_cleared|...` suffix
4. Evidence pack untouched

`silent_truncation: false` in policy snapshot — omissions are recorded in decisions.

---

## Required vs Optional Layers

When `SkillMemoryLayerRequirement.required === true` and zero items after filtering:

- Brand → `REQUIRED_MISSING` / `NoRelevantBrandExperience`
- Sector → `REQUIRED_MISSING` / `NoReleasedSectorPattern`
- Goals → `REQUIRED_MISSING` / `GoalNotAvailable`

`blocksInference()` → true → pre-inference abstention on wired Agent paths.

Optional empty → `UNAVAILABLE` or `OptionalEmpty` — does not block.

---

## Evidence Authority (Non-Substitutable)

Policy metadata on Evidence section:

```php
'memory_cannot_substitute' => true,
'sector_cannot_substitute' => true,
'skill_knowledge_cannot_substitute' => true,
```

Validator enforces Memory opaque refs cannot satisfy Evidence ID claims.

---

## Explicit Prohibitions (v1)

| Prohibited | Policy flag |
| --- | --- |
| Embeddings | `embeddings: false` |
| Vector DB | `vector_db: false` |
| Fine-tuning | `fine_tuning: false` |
| LLM ranking | `llm_ranking: false` |
| Browser raising limits | `browser_may_raise_limits: false` |
| Silent truncation | `silent_truncation: false` |

---

## Evaluation Boundary

Prompt 54 policy does **not** define retrieval quality metrics or offline eval harnesses. Prompt 55 owns evaluation (`intelligence_evaluation_v1`) — see [`INTELLIGENCE_EVALUATION_POLICY.md`](INTELLIGENCE_EVALUATION_POLICY.md). v1 selection remains fully deterministic from inputs + policy version; evaluation does not mutate this policy.

---

## Quick Reference Matrix

| Concern | v1 mechanism |
| --- | --- |
| Filter | Skill∩Agent gates + structured equality |
| Match explain | `IntelligenceMatchReason` codes |
| Order | Lexicographic priority keys |
| Limit | min(skill, hard max) + serialized byte cap |
| Score | **NONE** |
| Vector | **NOT IMPLEMENTED** |
| Version | `intelligence_retrieval_v1` |
