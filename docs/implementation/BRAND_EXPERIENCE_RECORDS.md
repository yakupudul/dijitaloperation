# BRAND EXPERIENCE RECORDS

## STATUS: REAL (Prompt 52)

**Prompt:** 52  
**Canonical path:** `docs/implementation/BRAND_EXPERIENCE_RECORDS.md`  
**Contract:** [`docs/architecture/BRAND_EXPERIENCE_CONTRACT.md`](../architecture/BRAND_EXPERIENCE_CONTRACT.md)  
**Depends on:** Prompt 51 Intelligence Memory Architecture (`1a1003b`)  
**Branch:** `cursor/brand-experience-records-ea01`  
**Base HEAD:** Prompt 51 `1a1003b2c194505eec04f76ec291546939972808`

| Fact | Value |
| --- | --- |
| Brand Experience Records | **REAL** |
| Brand Memory content provider | **REAL** (`ExperienceBrandMemoryContextProvider`) |
| Evidence Quality | **REAL** (categorical; no numeric score) |
| Causality inference | **NOT IMPLEMENTED** (`causality_not_established` only) |
| Sector Learning / aggregation | **NOT YET** / Prompt 53 |
| Retrieval / Memory Pack | **NOT YET** / Prompt 54 |
| Business Outcome domain | **NOT YET** / Prompt 57 |
| Vector / embeddings | **NOT IMPLEMENTED** |
| Provider / AI calls | **0** |
| New navigation / Brand tab | **NO** |

---

## 1. Purpose

Make Brand Memory content real through structured, provenance-rich, Brand-specific Brand Experience Records — without inventing causality, Sector aggregation, vectors, AI memory writes, or Business Outcomes.

## 2. Existing Experience Primitive Audit

| Primitive | Path | Decision |
| --- | --- | --- |
| BrandExperience model/table | none prior | **NONE → created** |
| Lesson/Learning/WhatWorked | none | NONE |
| MemoryCandidate | DTO only | Untrusted candidate — not Experience |
| NullBrandMemoryContextProvider | P51 stub | Replaced by Experience provider |
| Activity / AgentRun / Recommendation / Task | existing | **NOT EXPERIENCE** |
| Demo insights | fixtures | **DEMO_ONLY** — not migrated |

## 3. Existing Brand Memory Content Audit

Prompt 51 contracts only. No prior Brand Memory content store. Prompt 52 owns content.

## 4. Frozen Product Surface Audit

No Filament/Livewire Experience / Brand Memory / Lessons / What Worked surface. Backend persistence + read services are production-ready **without** new top-level navigation or Brand tabs.

## 5. Canonical Brand Experience Decision

One domain: `brand_experiences` + immutable `brand_experience_revisions` (+ goals, offerings, evidence links). **No BrandExperienceV2.**

## 6–11. Distinctions

| Concept | Relationship |
| --- | --- |
| Evidence | Factual support; pinned by id + fingerprint |
| Finding / Opportunity | Optional situation provenance |
| Recommendation | Proposed action; acceptance ≠ execution |
| Task | Completed Task may prove Action execution; ≠ strategy success |
| Activity | Timeline; not Experience; no spam |
| Business Outcome | Prompt 57; Observed Later Outcome ≠ BusinessOutcome |

## 12. Stable Experience Identity

Bigint `brand_experiences.id`. Not deduped by summary text.

## 13. Experience Lifecycle

`draft` → `confirmed` → (`superseded` | `invalidated`).  
No SUCCESS/FAILED lifecycle. Confirmation ≠ success ≠ causality.

## 14. Experience Revision / Supersession

Stable experience + immutable revisions; material correction via `supersede()` creating a new confirmed Experience that points at the prior.

## 15. Customer / Brand Ownership

Server resolves Customer from Brand. Cross-Brand FKs rejected.

## 16–21. Context / Goal / Market / Offering / Channel / Asset

- Context: `BrandExperienceContextSnapshot` v1 (bounded, validated keys)
- Goal/Offering: Prompt 37 stable IDs + event-time label snapshots
- Market: ISO country via `CountryOptions` (optional)
- Channel: closed enum = DigitalAsset type keys (not free text; ≠ Provider)
- DigitalAsset: optional; must belong Brand

## 22–25. Situation / Action

Situation: bounded summary + optional Evidence/Finding/Opportunity + periods.  
Action: `task_completed` or `external_operator_confirmed` only. Recommendation acceptance alone forbidden. Open Task forbidden. `action_occurred_at` ≠ `created_at`.

## 26–28. Observed Later Outcome

Must be after Action. Missing follow-up ≠ no_change. May be favorable/unfavorable/mixed/unclear/factual_state. Favorable/Unfavorable require Goal or explicit desired-direction declaration.

## 29–31. Temporal / Evidence Links

Ordering validated. Evidence links pin `evidence_id` + `evidence_fingerprint` with closed roles. Cross-Brand Evidence forbidden. No raw payload copy.

## 32–33. Evidence Quality

`BrandExperienceEvidenceQualityEvaluator` — deterministic, versioned (`brand_experience_quality_v1`), reason codes, multi-dimensional states. **No numeric score.** Default causality: not established.

## 34–35. Causality / Attribution

Temporal sequence only. No `action_caused_outcome`. Provider attribution ≠ strategic causality.

## 36. Current Context vs Historical Experience

Historical market/goal/offering snapshots remain; current BIC/Goals remain authoritative for current state.

## 37–41. Provenance / Creation / Confirmation / Correction

Origin: operator_captured | system_assisted_capture.  
Service: `BrandExperienceService`. No auto listeners. AI cannot write/confirm. Idempotency keys. Supersede/invalidate preserve history.

## 42–43. Read / Brand Memory Provider

`BrandExperienceReadService` — Brand-scoped, paginated, deterministic order.  
`ExperienceBrandMemoryContextProvider` lists confirmed Experience refs only. No semantic retrieval. No LLM tool.

## 44. AI Boundary

No AI calls required. `assertAiCannotWrite()`. No AI quality/causality.

## 45. Prompt 53 Contribution Boundary

`SectorLearningContributionCandidate` via `BrandExperienceSectorContributionBuilder`.  
`structurally_eligible_for_consideration` ≠ privacy qualified ≠ sector usable. Contributor IDs internal-only; stripped in consumer-safe view. No aggregation in P52.

## 46. Future Business Outcome Relation

Observed Later Outcome may later link to Prompt 57 Business Outcomes without rewriting Experience semantics. No BusinessOutcome table now.

## 47. Demo Retirement

No Demo Experience migration. No fake positive case studies.

## 48–51. Authorization / Tenancy / Security / Privacy

Customer-confidential Brand Memory. Mass-assignment of status/quality/foreign FKs rejected via service validation. No credentials/tokens/raw payloads/CoT.

## 52. Performance

Indexes on customer/brand/status, action/outcome times, channel/market, evidence links. Paginated lists. Eager-load revision relations.

## 53. Tests

`tests/Feature/BrandExperiences/BrandExperienceRecordsTest.php`

## 54. Reality Matrix

See `MILESTONE_5_PANEL_FREEZE.md` updates.

## 55. Prompt 53 Handoff

Consume confirmed Experiences with quality/context/action/outcome; apply privacy gate, cohort, contribution bounding, aggregation. Never raw provider rows. Never expose contributor IDs to normal Sector consumers.

## 56. Definition of Done

Prompt 52 §344 criteria satisfied.

---

## Mandatory matrices (selected)

### Experience field matrix

| Field | Required confirmed? | Structured? | Canonical source |
| --- | --- | --- | --- |
| Context | Yes | Snapshot VO | BrandExperienceContextSnapshot |
| Goal | Optional | FK | BrandGoal |
| Market | Optional | Country code | CountryOptions |
| Offering | Optional | FK | BrandOffering |
| Channel | Optional | Enum | DigitalAsset type keys |
| Observed Situation | Yes | Summary+provenance | Evidence/Finding/Opportunity/operator |
| Action | Yes | Kind+time+provenance | Task completed / external confirmed |
| Observed Later Outcome | Yes | Summary+time | Evidence/operator observation |
| Evidence Quality | Yes | Assessment DTO | Evaluator v1 |

### Causality matrix

| Scenario | Allowed | Forbidden |
| --- | --- | --- |
| Task completed → CPL falls | “CPL decreased after…” | “Task caused CPL fall” |
| Landing page changed → CVR rises | “CVR higher in follow-up…” | “Change caused CVR rise” |
| Recommendation accepted → leads rise | Not an Experience Action alone | Acceptance = success |
| QA passed → revenue rises | Not auto Experience | QA = business success |

### Provider semantic guards

GA4 key event ≠ Business Outcome; Ads conversion ≠ qualified lead; GSC position ≠ exact rank; DataForSEO ETV ≠ GA4 traffic; WordPress configured ≠ observed.

### Prompt 53 contribution matrix

| State | Structurally eligible? | Sector usable now? |
| --- | --- | --- |
| Confirmed + sufficient/partial | Maybe | **NO** |
| Draft / Invalidated / Insufficient | No | **NO** |
