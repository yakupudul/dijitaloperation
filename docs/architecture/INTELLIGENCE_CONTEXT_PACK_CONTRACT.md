# Intelligence Context Pack Contract

> Prompt 54 — typed structure of `IntelligenceContextPack` and nested Memory sections.  
> Implementation: `docs/implementation/INTELLIGENCE_RETRIEVAL_LAYER.md`  
> Policy: [`INTELLIGENCE_RETRIEVAL_POLICY.md`](INTELLIGENCE_RETRIEVAL_POLICY.md)

Primary types:

- `App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack`
- `App\Support\IntelligenceRetrieval\Dto\TypedMemoryContextPack`

Immutable value objects. No numeric relevance score on any section.

---

## Top-level: `IntelligenceContextPack`

| Field | Type | Authority | Notes |
| --- | --- | --- | --- |
| `customerId` | `int` | scope | Must match Brand owner |
| `brandId` | `int` | scope | Exact Brand scope for Brand Memory |
| `agentDefinitionSignature` | `string` | provenance | Agent profile signature |
| `skillDefinitionSignature` | `string` | provenance | Primary Skill for this run |
| `currentBrandContext` | `array<string,mixed>` | `CURRENT_CANONICAL_CONTEXT` | Bounded BIC + asset snapshot |
| `evidencePack` | `?EvidencePack` | `CURRENT_CANONICAL_EVIDENCE` | P50 pack — never replaced by Memory |
| `relevantGoals` | `list<array>` | `CURRENT_CANONICAL_CONTEXT` | Active BrandGoal identity fields |
| `skillContext` | `array<string,mixed>` | `GENERAL_SKILL_KNOWLEDGE` | Exact Skill only; not full catalog |
| `memoryContextPack` | `TypedMemoryContextPack` | per-layer | Historical / aggregate / methodology |
| `decisions` | `list<RetrievalSectionDecision>` | audit | All sections including evidence/goals |
| `retrievalMetadata` | `array<string,mixed>` | audit | Policy snapshot + explicit false flags |
| `retrievalFingerprint` | `string` | provenance | SHA-256 composite |
| `retrievalPolicyVersion` | `string` | provenance | Default `intelligence_retrieval_v1` |

### Methods

| Method | Purpose |
| --- | --- |
| `blocksInference(): bool` | Any section or memory pack blocks |
| `toPromptSections(): array` | Agent prompt payload (`MEMORY_CONTEXT_JSON`) |
| `toManifestArray(): array` | Run provenance / abstention manifest |

---

## Memory nest: `TypedMemoryContextPack`

| Field | Type | Layer |
| --- | --- | --- |
| `customerId`, `brandId` | `int` | scope |
| `agentDefinitionSignature`, `skillDefinitionSignature` | `string` | provenance |
| `brandExperiences` | `list<BrandExperienceContextItem>` | Brand |
| `sectorPatterns` | `list<SectorPatternContextItem>` | Sector |
| `skillKnowledge` | `list<SkillKnowledgeContextItem>` | Skill |
| `decisions` | `list<RetrievalSectionDecision>` | memory sections only |
| `retrievalPolicyVersion` | `string` | `intelligence_retrieval_v1` |
| `contextFingerprint` | `string` | memory content hash |

| Method | Purpose |
| --- | --- |
| `isEmpty(): bool` | All three item lists empty |
| `toArray(): array` | Serialized Memory JSON for budget check |
| `toLegacyMemoryContextPack(): MemoryContextPack` | P51 gateway bridge |

---

## Section: Current Brand Context

Prompt key: `CURRENT_BRAND_CONTEXT`

```json
{
  "authority": "CURRENT_CANONICAL_CONTEXT",
  "data": {
    "brand_id": 1,
    "customer_id": 1,
    "sector": "dental",
    "digital_asset": { "id": 1, "type": "website", "name": "..." },
    "brand_intelligence": { "... bounded BIC ..." },
    "authority": "CURRENT_CANONICAL_CONTEXT"
  }
}
```

Current truth — not historical Memory.

---

## Section: Current Evidence

Prompt key: `CURRENT_EVIDENCE`

| Field | Meaning |
| --- | --- |
| `evidence_pack_fingerprint` | P50 fingerprint |
| `evidence_ids` | Allowed Evidence IDs from pack |

Does not embed full Evidence payloads (those remain in `CONTEXT_JSON` on Website path).

---

## Section: Relevant Goals

Prompt key: `RELEVANT_GOALS`

Each goal item:

| Key | Type |
| --- | --- |
| `id` | `int` |
| `label` | `string` |
| `kind` | `?string` |
| `status` | `?string` |
| `normalized_key` | `?string` |

---

## Section: Exact Skill

Prompt key: `EXACT_SKILL`

| Key | Meaning |
| --- | --- |
| `signature` | Skill definition signature |
| `version` | Skill version when registered |
| `name` | Display name |
| `full_catalog_not_included` | always `true` in P54 |

---

## Section: Historical Brand Experience

Prompt key: `HISTORICAL_BRAND_EXPERIENCE`

Label: `HISTORICAL — does not override current Evidence/Goals`

### `BrandExperienceContextItem`

| Field | Type | Notes |
| --- | --- | --- |
| `opaque_ref` | `string` | `brand_experience:{id}` |
| `experience_revision_id` | `int` | pinned revision |
| `revision_number` | `int` | |
| `market_code` | `?string` | |
| `channel` | `?string` | |
| `action_kind` | `string` | |
| `outcome_clarity` | `string` | |
| `support_status` | `string` | |
| `causality_status` | `string` | typically `causality_not_established` |
| `action_occurred_at` | `?string` ISO8601 | |
| `outcome_observed_at` | `?string` ISO8601 | |
| `situation_summary` | `?string` | max 400 chars |
| `action_summary` | `?string` | max 400 chars |
| `outcome_summary` | `?string` | max 400 chars |
| `match_reasons` | `list<string>` | `IntelligenceMatchReason` values |
| `limitations` | `list<string>` | e.g. `causality_not_established` |
| `authority` | `HISTORICAL_BRAND_EXPERIENCE` | |
| `label` | `HISTORICAL_BRAND_EXPERIENCE` | |

---

## Section: Sector Aggregate Context

Prompt key: `SECTOR_AGGREGATE_CONTEXT`

Label: `MOXDOP cohort observation — not industry proof; not Brand fact`

### `SectorPatternContextItem`

| Field | Type | Notes |
| --- | --- | --- |
| `opaque_ref` | `string` | `sector_artifact:{stableKey}` |
| `artifact` | `SectorMemoryConsumerDto` array | **consumer-safe only** |
| `match_reasons` | `list<string>` | |
| `authority` | `PRIVACY_AGGREGATED_SECTOR_CONTEXT` | |
| `label` | `SECTOR_AGGREGATE_CONTEXT` | |

Forbidden keys in serialized artifact: `customer_id`, `brand_id`, `experience_id`, `contributor_ids`, lineage identifiers.

---

## Section: General Methodology

Prompt key: `GENERAL_METHODOLOGY`

Label: `Methodology only — does not create Customer facts`

### `SkillKnowledgeContextItem`

| Field | Type | Notes |
| --- | --- | --- |
| `opaque_ref` | `string` | skill knowledge ref id |
| `citation` | `string` | human-readable |
| `revision` | `?string` | version pin |
| `match_reasons` | `list<string>` | typically `SKILL_EXPLICIT_REFERENCE` |
| `authority` | `GENERAL_SKILL_KNOWLEDGE` | |
| `label` | `GENERAL_METHODOLOGY` | |
| `customer_data` | `false` | explicit |

---

## Section: Retrieval Metadata

Prompt key: `RETRIEVAL_METADATA`

Always includes policy snapshot and explicit negatives:

| Key | Value |
| --- | --- |
| `policy.version` | `intelligence_retrieval_v1` |
| `retrieval_contract` | `SkillRetrievalContract::toArray()` |
| `agent_layers_allowed` | allowed layer enum values |
| `fine_tuning` | `false` |
| `embeddings` | `false` |
| `vector_db` | `false` |
| `llm_ranking` | `false` |
| `numeric_relevance_score` | `null` |
| `provider_calls_during_retrieval` | `0` |

---

## Decision envelope: `RetrievalSectionDecision`

| Field | Purpose |
| --- | --- |
| `section` | e.g. `brand_experience`, `sector_pattern`, `goals` |
| `decision` | `IntelligenceRetrievalDecision` |
| `reason_codes` | denial/omission codes |
| `match_reasons` | selection explanation (not scores) |
| `candidate_count` / `selected_count` / `omitted_count` | bounded counts |
| `authority` | source class |
| `safe_metadata` | audit-safe keys only — no contributor/customer IDs |
| `blocks_inference` | derived |

---

## Manifest contract (`toManifestArray`)

Audit-safe fields for Agent runs:

- Tenant scope + signatures
- Fingerprints (retrieval, memory, evidence)
- ID/ref lists per layer
- Full decisions array
- Explicit nulls: `sector_contributor_identities`, `numeric_relevance_score`

---

## Legacy bridge: `MemoryContextPack`

P51 shape preserved for gateway:

| Ref array | Source |
| --- | --- |
| `brandRefs[]` | Experience opaque ref + revision |
| `sectorRefs[]` | artifact stable key + revision |
| `skillRefs[]` | skill opaque ref + citation |
| `retrievalNotes[]` | section:decision strings |
| `contextFingerprint` | typed pack fingerprint |

Content payloads are **not** duplicated in legacy refs — references only.
