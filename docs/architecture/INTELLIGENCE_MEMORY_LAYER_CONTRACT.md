# Intelligence Memory Layer Contract

> Prompt 51 — exact contracts for the three primary Intelligence Memory layers.  
> Implementation: `docs/implementation/INTELLIGENCE_MEMORY_ARCHITECTURE.md`  
> Privacy: `docs/architecture/INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md`

Enum: `App\Enums\IntelligenceMemoryLayer` — `brand` | `sector` | `skill`.

Shared interfaces/DTOs are allowed. **Shared unrestricted writable storage is forbidden.**

---

## BRAND (`IntelligenceMemoryLayer::Brand`)

| Dimension | Contract |
| --- | --- |
| Owner | Canonical **Customer** + **Brand** (stable Brand ID) |
| Privacy class | `tenant_confidential` |
| Canonical sources (future) | Brand Experience Records (Prompt 52); references to canonical domain events/decisions |
| Forbidden sources | Raw provider rows; normalized pool dumps; Evidence copies; every Finding/Task/Activity; every Agent response; other Brands’ Memory |
| Identity | `BrandMemoryScope(customerId, brandId)` — never Brand name alone |
| Lifecycle | Historical/supporting; supersession without destructive overwrite; customer lifecycle retention (future) |
| Provenance | `MemoryProvenance` with Brand-internal source identity allowed for consumers in-tenant |
| Current-truth authority | **Cannot override** current Evidence, Goals, Offerings, Service Scope, BIC |
| Future writer | Prompt 52 Experience services (not Agent direct) |
| Future reader | Prompt 54 server-side retrieval within exact Brand scope |
| Future retrieval policy | Skill∩Agent∩tenant∩Brand match∩validity∩retrieval selection |
| Content in Prompt 51 | **NONE** |

---

## SECTOR (`IntelligenceMemoryLayer::Sector`)

| Dimension | Contract |
| --- | --- |
| Owner | Explicit sector/group identity (operator catalog today; SectorDefinition may be added in Prompt 53 if required) |
| Privacy class | `privacy_qualified_aggregate` |
| Canonical sources (future) | Privacy-eligible Brand Experience Records only |
| Forbidden sources | Raw provider/Evidence; customer notes; campaign/keyword/URL identifiers; single-Brand “learning”; AI-inferred sector assignment |
| Identity | `SectorIdentityRef` from `OperatorConfirmedSectorIdentityResolver` — **not** AI/keyword guessed |
| Lifecycle | Aggregation/policy versioned artifacts; obsolete cohorts superseded by new artifacts |
| Provenance | Aggregation method/version, time range, privacy policy version; contributor IDs **not** in consumer contract (restricted internal lineage optional later) |
| Current-truth authority | Cohort observation only; **cannot override** Brand current context; **not** automatic industry standard |
| Future writer | Prompt 53 pipeline **only** after privacy gate PASS |
| Future reader | Prompt 54 — only when Skill+Agent allow, sector identity present, privacy Eligible, validity Active |
| Future retrieval policy | Same intersection + privacy qualification |
| Content in Prompt 51 | **NONE** (gate stub blocks usable output) |
| Cohort threshold | **NOT hardcoded** — Prompt 53 owns versioned policy |

Privacy gate: `SectorLearningPrivacyGate` / `DeferredSectorLearningPrivacyGate`.

---

## SKILL / KNOWLEDGE (`IntelligenceMemoryLayer::Skill`)

| Dimension | Contract |
| --- | --- |
| Owner | Skill/Playbook/reference stewards (versioned definitions) |
| Privacy class | `general_non_customer` |
| Canonical sources | Skill Definition versions; Playbook revisions; primary references; Prompt 48 research provenance; future validated intelligence under governance |
| Forbidden sources | Customer/Brand IDs; Brand performance values; verbatim customer examples; Sector learning auto-promotion; Agent outputs |
| Identity | Skill signature / Playbook revision / reference identity |
| Lifecycle | Follows Skill/Playbook versioning; superseded methodology not mixed silently |
| Provenance | Version/revision + research provenance; no customer linkage |
| Current-truth authority | Methodology guidance only — cannot redefine measured Brand facts |
| Future writer | Explicit curation / new Skill Definition version (not production Agent auto-learn) |
| Future reader | Prompt 54 within Skill compatibility; customer-free guard enforced |
| Future retrieval policy | Skill∩Agent∩customer-free∩version validity∩retrieval selection |
| Content store in Prompt 51 | **No duplicate corpus** — `CanonicalSkillKnowledgeContextProvider` references Skill signatures only |

Guard: `SkillMemoryCustomerDataGuard`.

---

## Common contracts (not common storage)

| Contract | Role |
| --- | --- |
| `MemoryProvenance` / `MemoryValidity` | Provenance + temporal envelope |
| `MemoryAccessDecision` / `EffectiveMemoryAccess` | Policy decisions |
| `MemoryContextRequest` / `MemoryContextManifest` / `MemoryContextPack` | Future retrieval shapes (Pack empty in P51) |
| `AgentMemoryPermission` / `SkillMemoryContract` | Upper bound ∩ request |
| `IntelligenceMemoryAccessPolicy` / `IntelligenceMemoryGateway` | Central **policy** boundary |

## Default access

- Skill with **no** Memory Contract → **no Memory**
- Agent with **no** allowed layers → **no Memory**
- Prompt 51 does **not** inject Memory into Prompt 50 Agent execution
