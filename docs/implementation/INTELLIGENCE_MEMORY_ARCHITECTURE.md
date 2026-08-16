# MOXDOP INTELLIGENCE MEMORY ARCHITECTURE

## STATUS: REAL (architecture / contracts) — Prompt 51

**Prompt:** 51  
**Canonical path:** `docs/implementation/INTELLIGENCE_MEMORY_ARCHITECTURE.md`  
**Layer contract:** [`docs/architecture/INTELLIGENCE_MEMORY_LAYER_CONTRACT.md`](../architecture/INTELLIGENCE_MEMORY_LAYER_CONTRACT.md)  
**Privacy boundaries:** [`docs/architecture/INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md`](../architecture/INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md)  
**Depends on:** Prompt 50 AI Agent Production Execution (`91f8f41`)  
**Branch:** `cursor/intelligence-memory-architecture-ea01`  
**Base HEAD:** Prompt 50 `91f8f4131cb7a4ccb177b7cad06a500dc6e2daf4`

| Fact | Value |
| --- | --- |
| Three layers | Brand / Sector / Skill — **REAL** (contracts) |
| Generic `memories` table | **NONE** |
| Brand Experience content | **NOT YET** / Prompt 52 |
| Sector Learning + privacy pipeline | **NOT YET** / Prompt 53 |
| Retrieval / Memory Pack injection | **NOT YET** / Prompt 54 |
| Vector DB / embeddings / similarity | **NOT IMPLEMENTED** |
| AI direct memory writes | **FORBIDDEN** |
| Provider / AI calls in Prompt 51 | **0** |

---

## 1. Purpose

Establish the centralized **three-layer Intelligence Memory foundation and boundaries** for MoxDOP Central Intelligence — without building one giant AI brain, without a universal writable Memory table, without vectors/embeddings/retrieval, and without Brand Experience or Sector Learning content.

## 2. Why MoxDOP Does Not Use One Giant AI Brain

A single `memories(id, type, customer_id, brand_id, content, embedding, metadata)` store would destroy tenant boundaries, privacy semantics, provenance, retention, Skill methodology boundaries, and future retrieval safety.

MoxDOP uses **three logically and semantically distinct layers** that may share typed contracts/interfaces but **must not** share unrestricted persistence.

## 3. Existing Memory Primitive Audit

| Primitive | Model/table/file | Current meaning | Brand-specific? | Cross-brand? | Knowledge? | Execution history? | Vector? | Persistent? | Demo? | Canonical? | Decision | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| BrandIntelligenceContext | `brand_intelligence_contexts` | Current Brand business context | Yes | No | No | No | No | Yes | No | Yes | **BRAND_CONTEXT_NOT_MEMORY** | Current canonical context; not Memory |
| AgentExecutionRun | `agent_execution_runs` | Prompt 50 run provenance | Scoped | No | No | Yes | No | Yes | No | Yes | **AGENT_RUN_HISTORY_NOT_MEMORY** | Execution ≠ Memory |
| SkillExecutionRun | `skill_execution_runs` | Skill run provenance | Scoped | No | No | Yes | No | Yes | No | Yes | **AGENT_RUN_HISTORY_NOT_MEMORY** | Same |
| AiProviderAttempt | `ai_provider_attempts` | Provider attempt log | Scoped | No | No | Yes | No | Yes | No | Yes | **AGENT_RUN_HISTORY_NOT_MEMORY** | Same |
| Activity | `brand_context_activities` | Operational timeline | Yes | No | No | Event | No | Yes | No | Yes | **ACTIVITY_NOT_MEMORY** | Timeline ≠ Memory |
| Playbook + Revision | `playbooks` / `playbook_revisions` | Human SOP | No | N/A | Methodology | No | No | Yes | No | Yes | **PLAYBOOK_NOT_MEMORY** | Canonical SOP; Skill Memory may reference |
| SkillDefinition | Markdown + `SkillRegistry` | Capability contract | No | N/A | Methodology | No | No | File | No | Yes | **SKILL_DEFINITION_NOT_MEMORY** | Canonical; Skill Memory references |
| Evidence | `evidences` (+ revisions) | Factual assertion | Yes | No | No | No | No | Yes | No | Yes | **EVIDENCE_NOT_MEMORY** | Fact ≠ Memory |
| Normalized Data Pool | warehouse tables | Provider facts | Yes | No | No | No | No | Yes | No | Yes | **RAW_DATA_NOT_MEMORY** | Outside Memory |
| OperatorFile | files | File storage | Scoped | No | Possible later | No | No | Yes | No | Yes | **FILE_NOT_MEMORY** | Files ≠ Memory |
| laravel/ai embeddings config | `config/ai.php` | Framework defaults | N/A | N/A | N/A | N/A | Config only | N/A | N/A | Framework | **VECTOR_INFRASTRUCTURE** (unused) | No app usage; no pgvector |
| Product KNOWLEDGE_MEMORY_ARCHITECTURE.md | docs | Planned 4-layer conceptual | Mixed | Planned | Yes | Mixed | Explicitly deferred | Doc | No | Direction | **CANONICAL_BUT_INCOMPLETE** | Align: Intelligence Memory primary layers = 3; Working/System are not P51 layers |
| Demo fixtures “insights” | demo atlas | Demo narrative | Often | Sometimes unsafe if globalized | Fake | No | No | Session/fixture | Yes | No | **DEMO_ONLY** | Not migrated |

**Canonical decision:** No production Intelligence Memory content store exists. Prompt 51 adds **contracts/policy only**.

## 4. Existing Knowledge Primitive Audit

| Source | Classification | Role for Skill Memory |
| --- | --- | --- |
| Skill Definition versions | CANONICAL | Reference only — do not duplicate |
| Playbook revisions | CANONICAL | Reference only — do not duplicate |
| Prompt 48 research provenance | GENERAL | Allowed general knowledge provenance |
| Primary references on Skills | GENERAL | Allowed |
| Brand Experience | NOT YET | Must not auto-promote to Skill Memory |
| Sector Learning | NOT YET | Must not auto-mutate Skills |

## 5. Brand Intelligence Context Audit

| Field | Classification |
| --- | --- |
| BIC model + write/read services | **CURRENT_CANONICAL_BRAND_CONTEXT** |
| Migrated into Memory? | **NO** |
| Duplicated as Memory? | **NO** |
| Preserved? | **YES** |

Memory may later reference historical BIC state; it does not replace BIC.

## 6. Agent Run History Audit

Prompt 50 `AgentExecutionRun` / `SkillExecutionRun` / validated structured results = **EXECUTION HISTORY**.  
A successful Agent output is **not** durable Memory. AI output → at most `MemoryCandidate` (untrusted).

## 7. Existing Vector / Embedding Audit

| Item | Status |
| --- | --- |
| pgvector / Pinecone / Weaviate / Qdrant / Milvus | **NONE** in app dependencies |
| App embedding generation | **NONE** |
| Similarity / similar-customer search | **NONE** |
| `config/ai.php` default_for_embeddings | Framework default only — unused by MoxDOP Memory |

## 8. Canonical Three-Layer Decision

| Layer | Enum | Privacy class |
| --- | --- | --- |
| Brand Memory | `IntelligenceMemoryLayer::Brand` | tenant_confidential |
| Sector Memory | `IntelligenceMemoryLayer::Sector` | privacy_qualified_aggregate |
| Knowledge / Skill Memory | `IntelligenceMemoryLayer::Skill` | general_non_customer |

Shared contracts (DTOs/interfaces) **≠** shared unrestricted storage.

## 9. Brand Memory

Answers: what has MoxDOP learned/observed/decided for **this** Brand that may help future reasoning for **this same** Brand?

Owner: **Customer + Brand** (stable Brand ID).  
Purpose: historical/supporting context.  
Not: raw provider data, every Finding/Task/Activity/Agent response, current Goal/BIC truth.  
Cross-Brand: **FORBIDDEN** (including same Customer, same sector, same service).

Future writer: Prompt 52 Brand Experience Records.

## 10. Sector Memory

Answers: what privacy-qualified aggregate patterns exist across a sufficiently broad cohort within an explicit sector/group?

Not: other-customer examples, raw provider/Evidence/notes, contributor IDs in consumer payloads, universal “industry standard” by default.

Future writer: Prompt 53 only, after privacy gate.

## 11. Knowledge / Skill Memory

Answers: what general methodology/knowledge is safe across Brands?

Sources: Skill Definition versions, Playbook revisions, primary references, Prompt 48 research.  
No Customer/Brand IDs or Brand-specific performance.  
Does not duplicate Skill/Playbook truth.

## 12. Memory vs Canonical Data

Memory is context/learning/experience. Canonical Data Pool remains business factual warehouse truth. Memory is **not** a second data warehouse.

## 13. Memory vs Evidence

Evidence = factual canonical assertion. Memory = retained context/experience. Evidence wins for current measurements.

## 14. Memory vs Finding / Opportunity / Recommendation

Those remain distinct domain objects. Memory does not create them in Prompt 51.

## 15. Memory vs Activity

Activity = operational event timeline. Not automatically Memory.

## 16. Memory vs Agent Run

Agent Run = execution history. Not automatically Memory.

## 17. Memory vs Playbook

Playbook = canonical human SOP. Skill Memory may reference revisions; must not clone corpus.

## 18. Memory vs Skill Definition

Skill Definition = canonical capability contract. Skill Memory references; must not create SkillV2/memory-duplicated methodology.

## 19. Brand Memory Ownership

`BrandMemoryScope(customerId, brandId)`. Brand rename does not change ownership identity.

## 20. Customer / Brand Isolation

Customer A cannot read Customer B Brand Memory. Brand A cannot read Brand B Memory even under the same Customer.

## 21. Sector Identity

Audit result: `brands.sector` + `customers.industry` via `IndustryOptions` catalog codes.

| Classification | Value |
| --- | --- |
| Type | **OPERATOR_CONFIRMED_CONTEXT** (catalog) |
| Stable SectorDefinition entity | **MISSING** prerequisite for Prompt 53 (documented; not invented blindly) |
| AI-inferred sector | **FORBIDDEN** |
| Keyword/website→sector | **FORBIDDEN** |

Resolver: `OperatorConfirmedSectorIdentityResolver`.

## 22. Sector Privacy Boundary

Interface: `SectorLearningPrivacyGate`.  
Implementation stub: `DeferredSectorLearningPrivacyGate` (`prompt_51_boundary_only`).  
Usable Sector Memory: **not until Prompt 53**.  
No magic cohort number. Explicit PASS/BLOCK dispositions — no privacy score.

## 23. Sector Aggregation Boundary

Path: Brand Experience → privacy qualification → contribution bounding → cohort qualification → aggregation → Sector Learning artifact.  
Raw GA4/GSC/Ads/Meta/Website/DataForSEO/Evidence/notes **cannot** pipe directly.

## 24. Knowledge / Skill Privacy Boundary

Customer-free. Guard: `SkillMemoryCustomerDataGuard`. Future governance against accidental paste of customer data into Skills/Playbooks.

## 25. Memory Provenance

DTO: `MemoryProvenance` — layer, source kind, source identity/revision, policy/methodology versions, effective/observed/created times, quality/validity states, supersession, consumer-safe citation.  
Does **not** duplicate source payloads. Sector consumer never sees contributor IDs.

## 26. Temporal Validity

DTO: `MemoryValidity` — Active / Historical / Superseded / NeedsReview / PrivacyBlocked / Expired.  
`created_at` ≠ event time ≠ effective time.

## 27. Supersession

Prefer explicit supersedes / superseded_by. Do not destructive-overwrite historical facts.

## 28. Memory Quality Without Scores

`MemoryQualityState` enum only. **No** memory_confidence / reliability / sector confidence numbers.

## 29. Current Truth vs Historical Memory

Authority helper: `IntelligenceMemoryAuthority`.  
Current Evidence / BIC / Goals / Offerings / Service Scope **outrank** historical Brand Memory and Sector aggregates for current fact questions. Memory may not override.

## 30. Agent Memory Permissions

`AgentMemoryPermission` + `AgentMemoryPermissionCatalog`.  
Default for all current Agents: **no layers**. Upper bound only; cannot expand Skill contract. Permission change ⇒ new Agent Definition Version (future).

## 31. Skill Memory Contract

`SkillMemoryContract` + `SkillMemoryContractResolver`.  
Absent contract ⇒ **no Memory**. Material change ⇒ new Skill Definition Version. Prompt 51 does not add `memory_context` to existing YAML.

## 32. Effective Memory Access

```
EffectiveMemory =
  SkillRequestedMemory
  ∩ AgentAllowedMemory
  ∩ CurrentAuthorizedScope
  ∩ LayerSpecificPolicy
  ∩ CurrentValidity
  ∩ PrivacyQualification
  ∩ RetrievalSelection
```

Implemented as policy intersection in `IntelligenceMemoryAccessPolicy`. RetrievalSelection = Prompt 54 (currently empty pack).

## 33. Intelligence Memory Gateway

`IntelligenceMemoryGateway` — routes/evaluates requests, enforces boundaries, returns manifests / empty packs.  
**No** table/model/SQL/DSL parameters. LLM must not call it. Not a god-brain service.

## 34. Brand Memory Write Boundary

Prompt 51 denies content writes. Prompt 52 owns Experience construction. AI → `MemoryCandidate` only.

## 35. Sector Memory Write Boundary

Only Prompt 53 pipeline. No Agent/Brand/Task listener direct write. Privacy qualification **before** usable persistence.

## 36. Skill Memory Write Boundary

Curated/versioned. Agent success ≠ Skill learning. No online auto Skill mutation.

## 37. AI Memory Candidate Boundary

`MemoryCandidate` status is always `memory_candidate`; `isTrustedMemory() === false`.

## 38. Cross-Brand Isolation

Tests enforce Brand A ↛ Brand B, Customer A ↛ Customer B, same-sector raw Brand Memory forbidden.

## 39. No Similar-Customer Raw Retrieval

Forbidden: nearest-neighbor Brand Memory, shared vector namespace, “find similar customers.” Allowed future: privacy-qualified Sector aggregates only (Prompt 53/54).

## 40. Memory Poisoning

AI output, website text, customer request text = untrusted. Operator input only via explicit domain actions. External references need provenance.

## 41. Sector Re-identification Risk

Architecture requires Prompt 53 to handle rare sector × city × week × exact metric combinations. Prompt 51 forbids emitting contributor-identifying consumer payloads.

## 42. Memory Retention

Layer-specific (Brand: customer lifecycle; Sector: aggregate/privacy; Skill: version history). No global retention_days. No purge scheduler in Prompt 51.

## 43. Memory Invalidation

Explicit states; do not physically delete history solely for applicability change (except privacy deletion). Invalid memory must not enter future Agent context as current (Prompt 54).

## 44. Retrieval Boundary

Owned by Prompt 54. Prompt 51 returns empty `MemoryContextPack`.

## 45. Evidence Pack vs Memory Pack

| Pack | Role |
| --- | --- |
| EvidencePack (Prompt 50) | Customer-specific factual support |
| MemoryContextPack (Prompt 54) | Historical / aggregate / methodological context |

Must not merge into one untyped blob. Prompt 51 does not inject Memory into Agent prompts.

## 46. Future Agent Run Memory Provenance

When Prompt 54 uses Memory, pin layer, artifact IDs/revisions, retrieval policy/version, query fingerprint alongside Evidence revisions.

## 47. No Vector DB Decision

No Pinecone/Weaviate/Qdrant/Milvus/pgvector/Elasticsearch vector indexes.

## 48. No Embeddings Decision

No embedding APIs/models for Memory. No similarity search.

## 49. Demo Memory Retirement

Demo insights/fake benchmarks/shared learning **not** migrated to production Memory.

## 50. Security

No raw DB memory tool; no generic search_all_memory; no secrets/OAuth in Memory; no full prompt/CoT archive as Memory; tenant isolation mandatory.

## 51. Privacy

Brand = tenant-confidential. Sector = privacy-qualified aggregate. Skill = general non-customer. **No mixed privacy class** artifact.

## 52. Tests

- `tests/Feature/IntelligenceMemoryArchitectureTest.php`
- `tests/Unit/IntelligenceMemoryPrimitiveAuditTest.php`

Cover: three layers, isolation, sector gate, skill privacy, authority, provenance, no vectors/tables/tools, empty pack, AI write forbid, effective access intersections.

## 53. Reality Matrix

See §259 update in `docs/implementation/MILESTONE_5_PANEL_FREEZE.md` and section below.

| Capability | Status |
| --- | --- |
| Intelligence Memory Architecture | **REAL** |
| Three Memory Layers | **REAL** |
| Brand Memory Ownership Contract | **REAL** |
| Brand Experience Records | **NOT YET / Prompt 52** |
| Sector Memory Privacy Contract | **REAL** |
| Sector Learning Records | **NOT YET / Prompt 53** |
| Sector Aggregation / Qualification | **NOT YET / Prompt 53** |
| Knowledge / Skill Memory Contract | **REAL** |
| Skill/Playbook integration | **REAL / REFERENCED** |
| Memory Provenance / Temporal | **REAL** (contracts) |
| Memory Access Policy / Gateway | **REAL** |
| Agent Memory Permission | **REAL** (default none) |
| Skill Memory Context Contract | **REAL** (absent ⇒ none) |
| Generic Memory Table | **NONE** |
| Vector / Embeddings / Similarity | **NOT IMPLEMENTED** |
| Memory Retrieval / Pack / Injection | **NOT YET / Prompt 54** |
| AI Direct Memory Write | **FORBIDDEN** |
| Cross-Brand Raw Access | **FORBIDDEN** |

## 54. Prompt 52 Handoff

Own Brand Experience Records: typed schema, intentional Experience construction from recommendation/task/QA/approval/review/outcome/operator decisions, immutable/history-oriented, no every-event mirroring, no AI direct trusted write.

## 55. Prompt 53 Handoff

Own Sector Learning & Privacy: versioned privacy policy, cohort thresholds, contribution bounding, aggregation provenance, re-identification controls, restricted internal lineage separate from consumer reads, SectorDefinition if required.

## 56. Prompt 54 Handoff

Own Intelligence Retrieval: server-side MemoryContextPack construction, bounded selection, citations, Agent run memory provenance pinning, still no LLM direct memory tools, still no unrestricted similar-customer retrieval.

## 57. Definition of Done

Prompt 51 PASS criteria from the Autopilot prompt (§278) are satisfied by: audit + three-layer contracts + policy/gateway + docs + architectural tests + zero vectors/content tables/provider calls + Prompt 50 boundaries preserved.

---

## Mandatory matrices (241–258)

### 241. Existing Memory Primitive Matrix

See §3.

### 242. Three-Layer Memory Matrix

| Dimension | Brand | Sector | Skill |
| --- | --- | --- | --- |
| Owner | Customer+Brand | Sector cohort (privacy-qualified) | Methodology/knowledge stewards |
| Scope | Exact Brand ID | Explicit sector identity | Skill/Playbook/reference scope |
| Source | Experience (P52) | Aggregated Experience (P53) | Skill/Playbook/refs/research |
| Privacy | Tenant confidential | Privacy-qualified aggregate | Non-customer |
| Customer ID allowed? | Yes (owner) | Consumer: **NO** | **NO** |
| Brand ID allowed? | Yes (owner) | Consumer: **NO** | **NO** |
| Raw provider data? | **NO** | **NO** | **NO** |
| Free-text customer data? | Bounded Experience only (P52) | **NO** by default | **NO** |
| Cross-brand? | **NO** | Aggregate only | N/A (general) |
| Aggregation required? | No | **YES** | No |
| Versioning | Experience history (P52) | Aggregation/policy version (P53) | Skill/Playbook versions |
| Validity | Temporal states | + privacy status | Version-aware |
| Future writer | Prompt 52 | Prompt 53 | Curator / Skill versioning |
| Future reader | Prompt 54 | Prompt 54 | Prompt 54 |
| Retrieval owner | Prompt 54 | Prompt 54 | Prompt 54 |

### 243. Memory vs Domain Matrix

| Concept | Canonical factual truth? | Historical context? | General knowledge? | May feed Memory? | Is Memory? |
| --- | --- | --- | --- | --- | --- |
| Normalized Data | Yes | No | No | No direct | No |
| Evidence | Yes | Support | No | No direct | No |
| Finding | Domain | Maybe later via Experience | No | Via P52 transform | No |
| Opportunity | Domain | Maybe | No | Via P52 | No |
| Recommendation | Domain | Accepted/rejected → Experience | No | Via P52 | No |
| Task | Domain | Completion ≠ success | No | Via P52 | No |
| QA / Approval | Domain | Decision history | No | Via P52 | No |
| Activity | Event history | Source signal | No | No auto | No |
| Playbook | SOP truth | Versioned | Methodology | Reference | No |
| Skill Definition | Capability truth | Versioned | Methodology | Reference | No |
| Agent Run | Execution | Provenance | No | Candidate only | No |
| BIC | Current Brand context | Historical later | No | Reference | No |
| Business Outcome | Future P57 | Yes for Experience | No | Later | No |
| Brand Experience | — | Yes | No | Is Brand Memory content | P52 |
| Sector Learning | — | Cohort observation | No | Is Sector Memory | P53 |

### 244. Brand Memory Source Matrix

| Potential source | Allowed future? | Direct Memory? | Requires Experience transform? | Why |
| --- | --- | --- | --- | --- |
| Evidence | Indirect | No | Yes | Fact ≠ Memory |
| Finding / Opportunity | Indirect | No | Yes | Domain ≠ Memory |
| Recommendation accepted/rejected | Yes | No | Yes | Decision history |
| Task completed | Signal | No | Yes | ≠ strategy success |
| QA passed / Approval | Signal | No | Yes | ≠ business success |
| Recurring Review | Signal | No | Yes | — |
| Agent Result | Candidate | No | Yes + validation | Untrusted |
| Business Outcome | Yes (later) | No | Yes | P57 |
| Operator note | Yes | No | Yes | Explicit action |

### 245. Sector Memory Source Matrix

| Source | Direct? | Requires Brand Experience? | Privacy gate? | Aggregation? | Raw Brand data? | Decision |
| --- | --- | --- | --- | --- | --- | --- |
| Raw provider rows | No | N/A | N/A | N/A | Yes | **FORBIDDEN** |
| Evidence copy | No | N/A | N/A | N/A | Yes | **FORBIDDEN** |
| Customer notes | No | N/A | N/A | N/A | Yes | **FORBIDDEN** |
| Brand Experience | No direct copy | **YES** | **YES** | **YES** | Must strip | **P53 path** |
| One Brand only | No | — | Block | — | — | **FORBIDDEN** |

### 246. Skill Memory Source Matrix

| Source | General? | Customer-specific? | Allowed? | Canonical owner | Versioned? | Reason |
| --- | --- | --- | --- | --- | --- | --- |
| Skill Definition | Yes | No | Reference | SkillRegistry | Yes | Truth |
| Playbook | Yes | No | Reference | Playbooks | Yes | SOP |
| Primary Reference | Yes | No | Yes | Skill refs | Yes | — |
| Prompt 48 research | Yes | No | Yes | Research docs | Provenance | — |
| Brand Experience | No | Yes | **NO auto** | — | — | Boundary |
| Sector Learning | Cohort | No IDs | **NO auto** | — | — | Needs eval/governance |
| Agent output | No | Often | **NO** | — | — | Untrusted |
| Customer file | No | Yes | **NO** | Files | — | Not Memory |

### 247. Current Truth Matrix

| Question type | Canonical source | Brand Memory override? | Sector override? | Skill override? | Reason |
| --- | --- | --- | --- | --- | --- |
| Current Goal | Goal / BIC | **NO** | **NO** | **NO** | Current wins |
| Current Offering | Offering | **NO** | **NO** | **NO** | Current wins |
| Service Scope | Scope | **NO** | **NO** | **NO** | Current wins |
| Measured metric | Evidence / pool | **NO** | **NO** | **NO** | Fact wins |
| Historical decision | Experience (P52) | Context only | No | No | Historical |
| Sector pattern | Sector Learning (P53) | No | Context only | No | Not Brand fact |
| Methodology | Skill/Playbook | No | No | Versioned | Capability |

### 248. Memory Privacy Matrix

| Layer | Customer data? | Brand IDs? | Raw values? | Free text? | Cross-brand? | Aggregation? | Privacy gate? | Normal Agent visibility? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Brand | Yes (owned) | Yes (owned) | Bounded | Bounded Experience | No | No | Tenant auth | Future if permitted |
| Sector | No | Consumer No | Aggregates only | Avoid | Aggregate | Yes | Yes | Future if eligible |
| Skill | No | No | No | General only | N/A | No | Customer-free guard | Future if permitted |

### 249. Sector Re-identification Risk Matrix

| Dimension/output | Risk | Allowed P51? | P53 qualification required? | Example concern |
| --- | --- | --- | --- | --- |
| Exact spend | High | No content | Yes | Unique spend fingerprint |
| Exact leads | High | No | Yes | Same |
| Rare sector | High | Identity only | Yes | Small N |
| City | High | No | Yes | Tiny city |
| Week | Medium | No | Yes | Short window |
| Unique campaign name | High | No | Yes | Identifies Brand |
| URL / keyword | High | No | Yes | Identifies Brand |
| Free text | High | No | Yes | Verbatim leak |
| Aggregate % / band | Lower | Contract only | Yes | Still needs cohort rules |

### 250. Memory Access Matrix

| Layer | Skill contract? | Agent permission? | Tenant auth? | Brand match? | Sector match? | Privacy? | Validity? | Future retrieval? |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Brand | Yes | Yes | Yes | Exact | N/A | Tenant | Active | P54 |
| Sector | Yes | Yes | Yes | N/A | Explicit | Eligible | Active | P54 |
| Skill | Yes | Yes | N/A (general) | Forbidden IDs | N/A | Customer-free | Versioned | P54 |

### 251. Effective Memory Matrix

| Scenario | Skill | Agent | Brand scope | Sector ID | Privacy | Valid | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| No Skill contract | — | any | — | — | — | — | **NONE** |
| Agent allows Brand; Skill no | no | Brand | — | — | — | — | **NONE** |
| Skill Brand; Agent no | Brand | none | — | — | — | — | **Blocked** |
| Both Brand; wrong Brand | Brand | Brand | mismatch | — | — | — | **Blocked** |
| Both Sector; no identity | Sector | Sector | ok | missing | — | — | **Blocked** |
| Both Sector; not qualified | Sector | Sector | ok | present | blocked | — | **Blocked** |

### 252. Memory Provenance Matrix

| Layer | Source type | Source identity | Revision | Policy version | Effective time | Contributor IDs visible? | Consumer citation |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Brand | BrandExperience | Experience id | Yes | Writer policy | Yes | Brand-internal OK | Brand-internal |
| Sector | SectorAggregation | Artifact id | Agg/policy ver | Privacy policy ver | Yes | **NO** | Safe aggregate only |
| Skill | SkillDefinition/Playbook/Ref | Signature/rev | Yes | N/A or eval | Version | N/A | Skill/Playbook/ref |

### 253. Temporal Matrix

| Layer | created_at | occurred_at | effective_at | superseded | expired | historical read | current Agent eligibility |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Brand | Record time | Event time | Applicability | Explicit | Possible | Yes | Active only |
| Sector | Artifact time | Window | Window | Policy recompute⇒new | Possible | Yes | Active+Eligible |
| Skill | Def time | N/A | Version | Deprecate | — | Prior versions | Current Skill version |

### 254. Memory Write Authority Matrix

| Actor | Brand direct? | Sector direct? | Skill direct? | Allowed route | Reason |
| --- | --- | --- | --- | --- | --- |
| Agent / AI | **NO** | **NO** | **NO** | Candidate only | Untrusted |
| Operator | Via P52 action | No | Curator versions | Explicit | — |
| Brand service | **NO** | **NO** | **NO** | — | Boundary |
| Task/Activity listener | **NO** | **NO** | **NO** | — | No auto |
| Prompt 52 service | Future yes | No | No | Experience | Owner |
| Prompt 53 service | No | Future yes | No | Pipeline | Owner |
| Skill curator | No | No | Versioned yes | Skill/Playbook | Governance |

### 255. Memory Promotion Matrix

| Source → Target | Automatic? | Required gate | Owner | Reason |
| --- | --- | --- | --- | --- |
| Canonical → Brand Memory | No | Experience transform | P52 | Intentional |
| Brand Experience → Sector | No | Privacy+agg | P53 | — |
| Sector → Skill | No | Eval+governance | P55+curator | — |
| Agent Result → Brand | No | Candidate+validation | P52 | — |
| Brand → Skill | No | Governance | Curator | — |

### 256. Memory / Retrieval Matrix

| Capability | Prompt 51 | Prompt 54 |
| --- | --- | --- |
| Layer definition | REAL | Use |
| Access policy | REAL | Enforce |
| Artifact identity | Contracts | Resolve |
| Vector index | NO | Still no requirement to add |
| Embeddings | NO | Decide later if ever |
| Similarity search | NO | Forbidden for raw Brand |
| Retrieval ranking | NO | Own |
| Memory Pack | Empty stub | Own |
| Agent prompt injection | NO | Bounded only |
| Citations | Contract | Own |
| Retrieval evaluation | NO | With P55 |

### 257. Memory / Prompt Ownership Matrix

| Capability | Prompt |
| --- | --- |
| Memory architecture | 51 |
| Brand Experience Records | 52 |
| Sector aggregation/privacy | 53 |
| Retrieval | 54 |
| Evaluation | 55 |
| Business Outcomes | 57 |
| Intelligence Scheduling | 63 |

### 258. No-Giant-Brain Matrix

| Architecture | Allowed? | Reason |
| --- | --- | --- |
| One memories table | **NO** | Destroys boundaries |
| One global vector index | **NO** | Cross-tenant risk |
| One cross-customer embeddings namespace | **NO** | Same |
| Layer-specific typed stores | **YES** (future) | Required |
| Central policy gateway | **YES** | Implemented |
| Agent direct SQL | **NO** | Frozen P50 |
| Agent direct memory search | **NO** | Forbidden |
| Server-side retrieval | **YES** (P54) | Required |

---

## Code map

| Area | Path |
| --- | --- |
| Enums | `app/Enums/IntelligenceMemoryLayer.php` (+ quality/validity/source/privacy/denial) |
| DTOs | `app/Support/IntelligenceMemory/Dto/*` |
| Contracts | `app/Contracts/IntelligenceMemory/*` |
| Policy/Gateway | `app/Services/IntelligenceMemory/*` |
| Agent permissions | `app/Support/IntelligenceMemory/AgentMemoryPermissionCatalog.php` |
| Skill contracts | `app/Support/IntelligenceMemory/SkillMemoryContractResolver.php` |
| Skill privacy guard | `app/Support/IntelligenceMemory/SkillMemoryCustomerDataGuard.php` |
