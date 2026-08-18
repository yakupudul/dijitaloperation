# MOXDOP SKILL NORMALIZATION

## STATUS: PASS (definition contract + this doc)

**Prompt:** 49  
**Canonical path:** `docs/implementation/MOXDOP_SKILL_NORMALIZATION.md`  
**Satellite specs:** [`docs/skills/SKILL_DEFINITION_SPEC.md`](../skills/SKILL_DEFINITION_SPEC.md) · [`docs/skills/NORMALIZED_SKILL_CATALOG.md`](../skills/NORMALIZED_SKILL_CATALOG.md)  
**Depends on:** Prompt 48 research (`docs/research/MOXDOP_SKILL_*`, external skill satellites) · existing Agent/Skill V1 registries · Prompt 38 Evidence definitions  
**Base HEAD:** Prompt 48 `02be60c99e7c4d07f467f4c82a89277b163e456e`  
**Branch:** `cursor/moxdop-skill-normalization-ea01`

| Fact | Value |
| --- | --- |
| Canonical storage | Markdown `SKILL.md` under module `resources/skills/` + `SkillRegistry` / `SkillDefinition` / `BuiltInSkillLoader` |
| Skills DB table / SkillV2 | **NO** |
| Total shipped Skills | **21** |
| Write permissions | **all NO** |
| LLM / provider calls in Prompt 49 | **0** |
| AI Skill Execution | **NOT YET** (Prompt 50) |

---

## 1. Purpose

Prompt 49 normalizes the **Skill Definition contract** so every curated methodology is versioned, evidence-bound, abstention-safe, provenance-aware, and machine-validatable — without inventing Skills, without a Skill database, and without executing AI.

```text
Prompt 48 research candidates
  → Prompt 49 normalized SKILL.md + validator + eligibility codes
    → Prompt 50 execution / grounded runs (handoff only here)
```

## 2. Scope

In scope:

- Evolve definition classes: `SkillDefinition`, `BuiltInSkillLoader`, `SkillDefinitionValidator`, `SkillEligibilityEvaluator`, `SkillEvidenceRequirement`, `SkillEvidenceCatalog`, `SkillGlobalClaimPolicy`, `SkillDefinitionFingerprint`
- Normalize Prompt 48 `READY_FOR_NORMALIZATION` candidates C1, C2, C3, C7, C8, C11 onto Website Skills
- Upgrade existing canonical Google Ads / Meta Ads / GBP Skills to the 1.1.0 contract shape (not invent new ones)
- Human-readable contract + normalized catalog docs
- Deterministic definition validation and eligibility reason codes

Out of scope (enforced):

- Skill runtime / LLM calls / provider HTTP / MCP
- `skills` / `skill_versions` tables, SkillV2, operator-uploaded executable Skills
- Task / Finding / Recommendation / Notification auto-creation
- External write actions, crawlers, schedulers
- Inventing deferred candidates (C4–C6, C9–C10, C12) or platinum copy

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Product blueprints (`AGENT_SKILL_ARCHITECTURE`, AI Control Plane, Evidence) |
| 3 | Prompt 48 research (candidates, evidence levels, license posture) |
| 4 | Primary provider documentation cited in Skill `reference_sources` |
| 5 | External skill repos | methodology inspiration only — never copied prose, never runtime |

External repository claims never override primary sources. Popularity is not evidence.

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | Skill = curated methodology data, never executable code |
| R2 | Missing / unavailable / unobserved ≠ zero / false / healthy |
| R3 | No magic composite SEO / GEO / health / AI-visibility scores |
| R4 | No external writes; all Skill write permissions = NO |
| R5 | No Task / Finding / Recommendation / Opportunity auto-create from Skill definition |
| R6 | Vendor estimates ≠ first-party GSC / GA4 / Ads / Meta measurements |
| R7 | Stable identity `module.slug`; signature `module.slug@version`; fingerprint sha256 over material fields |
| R8 | Prompt 49 does not call LLMs or providers |
| R9 | Deferred Prompt 48 statuses stay deferred — do not invent Skills |
| R10 | Skill ≠ Playbook (Prompt 45) ≠ Agent Profile |

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Prompt 48 research HEAD | `02be60c99e7c4d07f467f4c82a89277b163e456e` |
| Working branch | `cursor/moxdop-skill-normalization-ea01` |
| Research artifacts | `docs/research/MOXDOP_SKILL_RESEARCH_MATRIX.md`, `MOXDOP_SKILL_CANDIDATES.md`, EXTERNAL_SKILL_* satellites |

## 6. Prompt 48 Input Audit

Prompt 48 delivered research only: repository audits, evidence/unsafe/license satellites, and twelve capability candidates. Production Skill implementation was explicitly **NOT YET**. Prompt 49 consumes:

- Schema proposals (agent-skills anatomy ∩ MoxDOP ontology)
- READY candidates for normalization
- Deferred / experimental / playbook dispositions
- Forbidden magic-score and missing≠zero posture

## 7. Existing Skill Primitive Audit

Pre-Prompt 49: Markdown Skills under module roots, `SkillRegistry` + `BuiltInSkillLoader`, Settings Skill Library read-only UI, Agent Profile skill assignment. Gaps: unstructured evidence lists, weak abstention/provenance/success-signal contracts, no global forbidden-claim merge, no definition fingerprint, incomplete Prompt 48 candidate coverage (C2/C3 missing as first-class Skills).

## 8. Canonical Storage Decision

| Option | Decision |
| --- | --- |
| Module `resources/skills/*/SKILL.md` + YAML front matter | **CANONICAL** |
| `SkillRegistry` / `SkillDefinition` / `BuiltInSkillLoader` | **CANONICAL** runtime load path |
| `skills` / `skill_versions` DB tables | **NO** |
| SkillV2 / ProductionSkill parallel entity | **NO** |
| Remote / operator-uploaded Skills | **NO** |

## 9. Evolved Support Classes

| Class | Role |
| --- | --- |
| `SkillDefinition` | Immutable contract DTO; `stableKey()`, `signature()`, `definitionFingerprint()`, `effectiveForbiddenClaims()` |
| `BuiltInSkillLoader` | Safe UTF-8 Markdown+YAML loader; rejects path traversal, oversized files, executable payloads |
| `SkillDefinitionValidator` | Machine validation (purpose, evidence, magic scores, abstention, provenance) — no AI |
| `SkillEligibilityEvaluator` | Deterministic eligibility + abstention reason codes (runtime enforcement Prompt 50) |
| `SkillEvidenceRequirement` | Structured required/optional Evidence entries |
| `SkillEvidenceCatalog` | Known Evidence Definition IDs + observational type keys |
| `SkillGlobalClaimPolicy` | Global forbidden claims inherited by every Skill |
| `SkillDefinitionFingerprint` | Deterministic sha256 over canonicalized material fields |
| `SkillRegistry` | Module-root registry (unchanged ownership model) |

## 10. Skill Definition Contract Overview

Every Skill must express: Purpose, Required Evidence, Optional Evidence, When to Use, Do Not Use When, Methodology, Allowed Conclusions, Forbidden Claims, Success Signals, References — plus identity/version/status/provenance/fingerprint/abstention/downstream_domains. Full field semantics: `docs/skills/SKILL_DEFINITION_SPEC.md`.

## 11. Identity, Version, Signature

| Concept | Form | Mutable? |
| --- | --- | --- |
| Stable key | `module.slug` | NO (rename = new Skill) |
| Signature | `module.slug@version` | version bumps on material change |
| Display name | human title | YES (presentation) |
| Fingerprint | sha256 hex (64) | derived |

Slug must not embed third-party branding (`claude`, `open-seo`, `platinum`, etc.).

## 12. Definition Fingerprint

`SkillDefinitionFingerprint::hash` canonicalizes material fields (sorted keys, nested arrays) then sha256. Presentation-only body noise that is not in the material set does not affect the fingerprint. Material set includes purpose, status, when/do-not-use, evidence requirements, methodology (+ steps), allowed/forbidden claims, abstention, success signals, references, research provenance, downstream domains, output contract, identity/version.

## 13. Definition Status Lifecycle

| Status | Meaning |
| --- | --- |
| `active` | Production-ready definition; stricter validator gates |
| `draft` | Incomplete; not production |
| `experimental` | Explicitly labelled; abstention-first |
| `needs_review` | Awaiting human methodology review |
| `deprecated` | Retained for provenance; do not assign to new Agents |

All 21 shipped Skills in Prompt 49 are `active`.

## 14. Purpose Field Rules

Purpose is one analytical capability statement. Validator rejects business-outcome promises (rankings, leads, revenue). Purpose must not invent scores.

## 15. When to Use / Do Not Use When

Positive triggers and negative scope are mandatory prose sections (YAML-adjacent body). They prevent over-firing across sibling Skills (e.g. technical vs indexability vs metadata).

## 16. Required Evidence Contract

Structured list of `SkillEvidenceRequirement` with `missing_behavior: ABSTAIN` for required entries. Active Skills generally require ≥1 required Evidence (exception: bounded framing Skills such as `recommendation-framing` that operate on already-selected Finding/Evidence IDs in context — still abstention-bound).

## 17. Optional Evidence Contract

Optional entries use `missing_behavior: CONTINUE` and may set `expands_conclusions: true`. Absence must never be treated as a passing check.

## 18. Evidence Catalog and Roles

Allowed keys come from Prompt 38 Evidence Definition IDs and observational types (`page_html`, `search_console_performance`, `ga4_events`, provider-native Ads/Meta/GBP keys, aliases like `gsc_any` / `technical_any`). Roles: `PRIMARY_FACT`, `COMPARISON_BASELINE`, `SCOPE_CONTEXT`, `MEASUREMENT_CONTEXT`, `MARKET_CONTEXT`, `OPTIONAL_ENRICHMENT`.

## 19. Abstention and Eligibility Reason Codes

| Code | Meaning |
| --- | --- |
| `missing_required_evidence` | Required Evidence absent |
| `missing_required_context` | Required context flag absent |
| `required_evidence_stale` | Required Evidence stale |
| `integrity_blocked` | Integrity gate failed |
| `coverage_insufficient` | Coverage insufficient |
| `provider_limited` | Provider limitation |
| `methodology_not_applicable` | Wrong Skill for the question |
| `unsupported_question` | Question outside contract |
| `eligible` | May proceed (Prompt 50) |

Prompt 49 defines codes and evaluator behaviour for definition-time tests; Prompt 50 owns live run enforcement.

## 20. Methodology and Methodology Steps

Methodology prose is mandatory. Optional `methodology_steps[]` use bounded types: `CHECK`, `COMPARE`, `CLASSIFY`, `SYNTHESIZE`, `SUMMARIZE`, `PRIORITIZE_WITHOUT_SCORE`, `VALIDATE`, `ABSTAIN_GATE`. No PHP/shell/eval in methodology.

## 21. Allowed Conclusions

Explicit list of conclusion classes the Skill may emit. Anything outside the list is forbidden by omission.

## 22. Forbidden Claims and Global Policy

Skill-specific `forbidden_claims` **merge with** `SkillGlobalClaimPolicy` (skills may only add restrictions). Global policy covers fabrication, missing≠zero, vendor≠first-party, outcome guarantees, magic scores, autonomous domain writes, unsupported causality, provider metric conflation.

## 23. Success, Failure, and Watch Signals

`success_signals` mandatory; `failure_signals` / `watch_metrics` optional but preferred. Success is operator-actionability and honesty about gaps — never a numeric Skill score.

## 24. References and Primary Sources

`reference_sources` cite primary docs (Google Search Central, GSC Help, GA4 Help, Ads/Meta API fields, MoxDOP product docs). Dated `verified_at` preferred. External skill repos are not primary authority.

## 25. Research Provenance

`research_provenance` records Prompt 48 candidate IDs and/or `existing-canonical-pre-prompt-48` for pre-existing Skills. READY normalizations must mention C1/C2/C3/C7/C8/C11 as applicable. Methodology is re-expressed; third-party prompts are not copied.

## 26. Downstream Domains

Bounded labels such as `ANALYSIS_ONLY`, `FINDING_CANDIDATE`, `RECOMMENDATION_CANDIDATE`. Labels do **not** create domain rows. Creation remains human/service-owned in later prompts.

## 27. Output Contract

Human-readable output shape (observation → why → action candidate → dependencies → signals). No composite score field.

## 28. Write Permissions

| Permission class | Prompt 49 |
| --- | --- |
| Provider / platform writes | **NO** (all Skills) |
| CMS / Ads / GBP mutate | **NO** |
| Auto Task / Finding / Recommendation / Notification | **NO** |
| Skill self-modification | **NO** |
| Credential access in Skill body | **NO** |

## 29. Magic Score Rejection

Validator rejects SEO/GEO/health/AI-visibility score language in purpose/methodology. Composite scores are not Skill outputs. Prioritization without score is allowed via `PRIORITIZE_WITHOUT_SCORE`.

## 30. Provider Semantic Claim Boundaries

Skills must keep provider-native semantics distinct, including:

| Domain | Forbidden conflation (examples) |
| --- | --- |
| GSC | average position ≠ exact SERP rank; impressions ≠ search volume / market volume |
| GA4 | key events ≠ guaranteed business outcomes |
| Google Ads | conversions ≠ qualified leads without explicit mapping |
| Meta | typed `action_type` ≠ collapsed generic “Result” |

## 31. Prompt 48 READY Normalization Map

| Candidate | Status in P48 | Normalized Skill | Version |
| --- | --- | --- | --- |
| C1 Website Technical Audit | READY | `website.technical-seo-analysis` | 1.1.0 |
| C2 Indexability Analysis | READY | `website.indexability-analysis` | 1.0.0 |
| C3 Metadata Consistency | READY | `website.metadata-consistency` | 1.0.0 |
| C7 Search Demand Analysis | READY | `website.gsc-search-demand-review` | 1.1.0 |
| C8 Query Opportunity Analysis | READY | `website.keyword-opportunity-analysis` | 1.1.0 |
| C11 Measurement Audit | READY | `website.ga4-measurement-quality` | 1.1.0 |

## 32. Deferred Candidates (Not Invented)

| Candidate | Disposition | Prompt 49 action |
| --- | --- | --- |
| C4 Structured Data Audit | `NEEDS_PRIMARY_SOURCE_WORK` | Document only — no Skill |
| C5 Internal Linking Analysis | `NEEDS_DATA` | Document only — no Skill |
| C6 Content Quality Review | `PLAYBOOK_NOT_SKILL` | Route to Playbooks (Prompt 45) — no Skill |
| C9 Local Profile Completeness | `NEEDS_DATA` | Document only — no Skill |
| C10 Local Review Intelligence | `NEEDS_DATA` | Document only — no Skill |
| C12 GEO Observation Analysis | `EXPERIMENTAL` | Not shipped as Skill |
| Platinum / license-blocked copy | copyleft / do-not-copy | Re-express methodology only if useful; **0 lines** of platinum prose |

Rejected invented slugs (examples): `structured-data-audit`, `internal-linking-analysis`, `content-quality-review`, `local-profile-completeness`, `local-review-intelligence`, `geo-observation-analysis`, `claude-seo-technical-audit`, `open-seo-technical-audit`.

## 33. Existing Canonical Ads / Meta / GBP Upgrade

Pre-existing Google Ads (5), Meta Ads (5), and GBP (2) Skills upgraded to **1.1.0** contract fields (structured evidence, abstention, provenance `existing-canonical-pre-prompt-48`, success/failure signals, global claim merge). No new Ads/Meta/GBP Skills invented in Prompt 49.

## 34. Website Normalized Inventory

Nine Website Skills: `technical-seo-analysis`, `indexability-analysis`, `metadata-consistency`, `search-console-analysis`, `gsc-search-demand-review`, `keyword-opportunity-analysis`, `ga4-measurement-quality`, `recommendation-framing`, `brand-context-discovery`. See catalog for field summaries.

## 35. Total Shipped Skill Inventory

| Module | Count |
| --- | --- |
| website | 9 |
| google-ads | 5 |
| meta-ads | 5 |
| google-business-profile | 2 |
| **Total** | **21** |

Authoritative listing: `docs/skills/NORMALIZED_SKILL_CATALOG.md`.

## 36. Loader, Registry, Validator

Loader parses YAML front matter + Markdown body; registry indexes by module+slug; validator runs without AI and is suitable for PHPUnit gates (`SkillNormalizationPrompt49Test` and library tests).

## 37. Eligibility Evaluator

Deterministic evaluation against available Evidence types, context flags, and Evidence states. Optional Evidence absence keeps Skill eligible. Required missing/stale/blocked → abstain with reason code. Capability lists remain **metadata only** (Capability Router absent).

## 38. Settings Catalog Surface

`/app/settings/ai/skills` remains read-only catalog over the code registry. No generic CRUD. No Demo Skill fallback invented.

## 39. License and Copy Posture

| Posture | Application |
| --- | --- |
| `REEXPRESS_FROM_PRIMARY_SOURCES` | Default for READY normalizations |
| `RESEARCH_ONLY` | HEAD vocabulary / open-seo concepts — no verbatim copy |
| License-blocked platinum | Do not copy; methodology concepts only if re-expressed |
| Agent-skills anatomy | Schema inspiration (MIT) — MoxDOP fields authored in-repo |

## 40. Tests

Definition contract covered by feature tests asserting: 21 Skills validate; READY provenance; deferred slugs absent; global claim merge; missing Evidence abstains; optional Evidence absence eligible; stale/integrity abstains; provider semantic forbidden claims; fingerprint determinism; no domain object creation on load; no external branding in stable keys.

## 41. Explicit Non-Goals

- Skill execution / grounded AI runs (Prompt 50)
- Eval runner / fixture harness beyond definition validation
- Capability Router
- Playbook↔Skill runtime orchestration
- SaaS / client portal Skills
- Second Evidence / Collection / DataForSEO stack

## 42. Files and Ownership

| Area | Owner |
| --- | --- |
| Core support classes under `app/Support/Skills/` | Core |
| Module `resources/skills/*/SKILL.md` | Owning module |
| Settings Skill Library pages | Core UI (read-only) |
| This doc + `docs/skills/*` | Product/implementation docs |

Core must not absorb Website/Ads/GBP methodology prose.

## 43. Demo / Runtime Boundary

Prompt 49 does not introduce Demo Skills. Catalog reads registry. No LLM invocation, no provider credentials required for boot/PHPUnit of definitions.

## 44. Reality Matrix

| Capability | Prompt 49 expected state | Notes |
| --- | --- | --- |
| Skill Definition contract (Markdown + classes) | **CONVERGED / REAL** | Structured YAML + validator |
| Skill Library catalog UI | **REAL** (read-only registry) | `/app/settings/ai/skills` |
| Skills DB / SkillV2 | **NO** | Not created |
| Prompt 48 READY (C1,C2,C3,C7,C8,C11) | **NORMALIZED** | Versions per §31 |
| Deferred P48 candidates | **DEFERRED** | Not invented as Skills |
| Existing Ads/Meta/GBP Skills | **UPGRADED 1.1.0** | Contract only |
| Total shipped Skills | **21** | Catalog authoritative |
| Definition fingerprint | **REAL** | sha256 material hash |
| Global forbidden claims | **REAL** | Merge policy |
| Eligibility reason codes | **REAL** (definition-time) | Runtime Prompt 50 |
| Write permissions | **ALL NO** | |
| LLM / provider calls (this prompt) | **0** | |
| AI Skill Execution / grounded runs | **NOT YET** | **Prompt 50** |
| Magic scores as Skill output | **NO** | Validator rejects |
| Playbook conflation | **NO** | C6 remains Playbook |

Milestone 5 Skills row: definitions **CONVERGED / REAL**; AI Skill Execution remains **NOT YET / Prompt 50**.

## 45. Prompt 50 Handoff

Prompt 50 owns **execution only**:

1. Grounded Agent runs that assemble eligible Skills + Evidence as untrusted data
2. Enforce eligibility/abstention reason codes at run time
3. Route via AI Control Plane; log provenance (`signature` + fingerprint)
4. Structured output validation / grounding — still no provider writes, no Task auto-create
5. Do not reopen Skill schema unless a material contract bug is found
6. Do not normalize deferred candidates unless Architect reopens Prompt 48 statuses

## 46. Definition of Done

| Gate | Status |
| --- | --- |
| Base Prompt 48 HEAD recorded | YES — `02be60c99e7c4d07f467f4c82a89277b163e456e` |
| Branch `cursor/moxdop-skill-normalization-ea01` | YES |
| Canonical storage Markdown + registry (no Skill DB / SkillV2) | YES |
| Evolved support classes present | YES |
| C1/C2/C3/C7/C8/C11 normalized with provenance | YES |
| Deferred candidates not invented | YES |
| Ads/Meta/GBP upgraded to 1.1.0 contract | YES |
| 21 Skills shipped and documented in catalog | YES |
| Stable key / signature / fingerprint rules documented | YES |
| Write permissions all NO; 0 LLM/provider calls in P49 | YES |
| Sections 1–46 + matrices 215–237 present | YES |
| Reality matrix (§44) matches expected states | YES |
| Prompt 50 handoff = execution only | YES |
| Spec satellites written | YES — `SKILL_DEFINITION_SPEC.md`, `NORMALIZED_SKILL_CATALOG.md` |

---

## MANDATORY MATRICES (215–237)

## 215. Existing Skill Primitive Matrix

| Primitive | Location | Semantic | Decision |
| --- | --- | --- | --- |
| `SKILL.md` | module `resources/skills/` | CANONICAL methodology | KEEP / EVOLVE contract |
| `SkillRegistry` | Core | CANONICAL index | KEEP |
| `BuiltInSkillLoader` | Core | Safe parse | EVOLVE |
| `SkillDefinition` | Core | Contract DTO | EVOLVE |
| Settings Skill Library | Filament/Livewire | Read-only catalog | KEEP |
| `skills` table | — | NONE | DO NOT CREATE |
| SkillV2 | — | NONE | DO NOT CREATE |

## 216. Prompt 48 Candidate Disposition Matrix

| # | Candidate | P48 status | P49 disposition |
| --- | --- | --- | --- |
| C1 | Website Technical Audit | READY | NORMALIZED → technical-seo-analysis@1.1.0 |
| C2 | Indexability Analysis | READY | NORMALIZED → indexability-analysis@1.0.0 |
| C3 | Metadata Consistency | READY | NORMALIZED → metadata-consistency@1.0.0 |
| C4 | Structured Data Audit | NEEDS_PRIMARY_SOURCE_WORK | DEFERRED |
| C5 | Internal Linking Analysis | NEEDS_DATA | DEFERRED |
| C6 | Content Quality Review | PLAYBOOK_NOT_SKILL | DEFERRED (Playbook) |
| C7 | Search Demand Analysis | READY | NORMALIZED → gsc-search-demand-review@1.1.0 |
| C8 | Query Opportunity Analysis | READY | NORMALIZED → keyword-opportunity-analysis@1.1.0 |
| C9 | Local Profile Completeness | NEEDS_DATA | DEFERRED |
| C10 | Local Review Intelligence | NEEDS_DATA | DEFERRED |
| C11 | Measurement Audit | READY | NORMALIZED → ga4-measurement-quality@1.1.0 |
| C12 | GEO Observation Analysis | EXPERIMENTAL | DEFERRED |

## 217. Normalized Skill Inventory Matrix

| Stable key | Version | Module | Status | P48 link |
| --- | --- | --- | --- | --- |
| website.technical-seo-analysis | 1.1.0 | website | active | C1 |
| website.indexability-analysis | 1.0.0 | website | active | C2 |
| website.metadata-consistency | 1.0.0 | website | active | C3 |
| website.gsc-search-demand-review | 1.1.0 | website | active | C7 |
| website.keyword-opportunity-analysis | 1.1.0 | website | active | C8 |
| website.ga4-measurement-quality | 1.1.0 | website | active | C11 |
| website.search-console-analysis | 1.1.0 | website | active | pre-P48 |
| website.recommendation-framing | 1.1.0 | website | active | pre-P48 |
| website.brand-context-discovery | 1.1.0 | website | active | pre-P48 |
| google-ads.* (5 Skills) | 1.1.0 | google-ads | active | pre-P48 upgrade |
| meta-ads.* (5 Skills) | 1.1.0 | meta-ads | active | pre-P48 upgrade |
| google-business-profile.* (2 Skills) | 1.1.0 | gbp | active | pre-P48 upgrade |

## 218. Skill Identity Matrix

| Component | Canonical? | Form | Notes |
| --- | --- | --- | --- |
| Stable key | YES | `module.slug` | Cross-module slug reuse allowed with module scope |
| Signature | YES | `module.slug@version` | Provenance / Run handoff |
| Fingerprint | YES | sha256 | Material fields only |
| Display name | NO | free text | Not identity |
| External brand in key | FORBIDDEN | — | Validator/tests |

## 219. Definition Field Completeness Matrix

| Field | Required (active) | Notes |
| --- | --- | --- |
| purpose | YES | No business-outcome promise |
| when_to_use / do_not_use_when | YES | Body sections |
| required_evidence (structured) | YES* | *framing Skill may be empty with abstention |
| optional_evidence | OPTIONAL | CONTINUE only |
| methodology | YES | Non-executable |
| allowed_conclusions | YES | Non-empty |
| forbidden_claims | YES | Merged with global |
| abstention_rules | YES | Non-empty for active |
| success_signals | YES | Non-empty |
| reference_sources | YES | Non-empty |
| research_provenance | YES for READY / preferred | |
| downstream_domains | OPTIONAL | Labels only |
| output_contract | YES for active | |

## 220. Evidence Requirement Matrix

| Behavior | Required Evidence | Optional Evidence |
| --- | --- | --- |
| Missing | ABSTAIN | CONTINUE |
| Stale / integrity-blocked | ABSTAIN | Does not force abstain alone |
| Expands conclusions | N/A | Allowed when present |
| Same key in both lists | INVALID | INVALID |

## 221. Evidence Catalog Matrix

| Kind | Examples | Allowed |
| --- | --- | --- |
| evidence_definition | `gsc.property.period_comparison`, `ga4.property.period_comparison` | YES if registry-known |
| evidence_type | `page_html`, `search_console_performance`, `ga4_events`, Ads/Meta/GBP keys | YES |
| alias / prefix | `gsc_any`, `technical_any`, `dataforseo_any`, `gsc_*` | YES |
| unknown key | — | Validator error |

## 222. Abstention Reason Code Matrix

| Reason code | Triggers abstain? | Missing ≠ zero? |
| --- | --- | --- |
| missing_required_evidence | YES | Preserved |
| required_evidence_stale | YES | Preserved |
| integrity_blocked | YES | Preserved |
| coverage_insufficient | YES | Preserved |
| provider_limited | YES | Preserved |
| missing_required_context | YES | Preserved |
| methodology_not_applicable | YES (declared) | N/A |
| unsupported_question | YES (declared) | N/A |
| eligible | NO | N/A |

## 223. Global Forbidden Claim Matrix

| Theme | Enforced |
| --- | --- |
| Fabrication | YES |
| Missing as zero | YES |
| Vendor as first-party | YES |
| Outcome guarantees | YES |
| Ranking internals as fact | YES |
| Provider metric conflation | YES |
| Invented Brand/Goal/Scope context | YES |
| Magic composite scores | YES |
| Single-sample GEO universality | YES |
| WP configured ≡ rendered without dual proof | YES |
| Autonomous domain writes | YES |
| Unsupported causal SEO/GEO | YES |

## 224. Provider Semantic Boundary Matrix

| Skill area | Must not claim |
| --- | --- |
| GSC demand / search-console / keyword | exact SERP rank from average position; impressions as market volume |
| GA4 measurement quality | key event = business outcome |
| Google Ads measurement-quality-review | conversion = qualified lead without mapping |
| Meta measurement-result-review | collapse action types into generic Result |

## 225. Write Permission Matrix

| Action | Allowed in P49 Skills |
| --- | --- |
| Read supplied Evidence | YES (analytical) |
| Call LLM | NO |
| Call external provider APIs | NO |
| Mutate Ads/GBP/CMS | NO |
| Create Finding/Task/Recommendation | NO |
| Send notifications | NO |
| Modify Skill files at runtime | NO |

## 226. Methodology Step Type Matrix

| Type | Allowed |
| --- | --- |
| ABSTAIN_GATE / CHECK / COMPARE / CLASSIFY / VALIDATE | YES |
| SYNTHESIZE / SUMMARIZE / PRIORITIZE_WITHOUT_SCORE | YES |
| SCORE / GRADE / HEALTH_INDEX | NO |
| EXEC / SHELL / PHP | NO |

## 227. Downstream Domain Matrix

| Label | Creates domain row? | Meaning |
| --- | --- | --- |
| ANALYSIS_ONLY | NO | Advisory analysis |
| FINDING_CANDIDATE | NO | May suggest Finding candidates for humans/rules |
| RECOMMENDATION_CANDIDATE | NO | May draft Recommendation guidance |
| TASK_AUTO | — | NOT A LABEL — forbidden |

## 228. License / Provenance Matrix

| Source class | Copy posture | P49 outcome |
| --- | --- | --- |
| Primary Google/Meta docs | Cite | References listed |
| agent-skills anatomy | Adapt concept | Schema fields |
| claude-seo / open-seo / HEAD | RESEARCH_ONLY / re-express | Provenance lines; no verbatim prompts |
| platinum-seo-engine | License-blocked | No copy |
| Pre-P48 MoxDOP Skills | existing-canonical | Upgraded contract |

## 229. Skill vs Playbook vs Agent Matrix

| Concept | Persistence | Executable? | P49 |
| --- | --- | --- | --- |
| Skill | Markdown + registry | Methodology data only | NORMALIZED |
| Agent Profile | Code registry | Persona/workflow bound | Unchanged ownership |
| Playbook | DB revisions (P45) | SOP knowledge | C6 stays here |
| Skill execution run | — | Prompt 50 | NOT YET |

## 230. Magic Score Rejection Matrix

| Pattern | Disposition |
| --- | --- |
| SEO score / GEO score / health score / AI visibility score | REJECT |
| E-E-A-T numeric grade as canonical metric | REJECT |
| Prioritize without numeric score | ALLOW |
| Qualitative priority labels | ALLOW if non-scored |

## 231. Storage / No-DB Matrix

| Store | Decision |
| --- | --- |
| Module filesystem Skills | CANONICAL |
| `skills` table | NO |
| `skill_versions` table | NO |
| SkillV2 entity | NO |
| Demo Skill fixtures as production truth | NO |

## 232. Module Ownership Matrix

| Module | Skills owned | Core may edit methodology? |
| --- | --- | --- |
| website | 9 | NO |
| google-ads | 5 | NO |
| meta-ads | 5 | NO |
| google-business-profile | 2 | NO |
| Core `app/Support/Skills` | contracts only | YES (mechanics) |

## 233. Deferred / Rejected Slug Matrix

| Slug | Why absent |
| --- | --- |
| structured-data-audit | C4 NEEDS_PRIMARY_SOURCE_WORK |
| internal-linking-analysis | C5 NEEDS_DATA |
| content-quality-review | C6 PLAYBOOK_NOT_SKILL |
| local-profile-completeness | C9 NEEDS_DATA |
| local-review-intelligence | C10 NEEDS_DATA |
| geo-observation-analysis | C12 EXPERIMENTAL |
| claude-seo-technical-audit / open-seo-technical-audit | External branding / not MoxDOP identity |

## 234. Settings / UI Matrix

| Surface | Before P49 | After P49 |
| --- | --- | --- |
| Skill Library list/detail | Registry read-only | Same + richer contract fields displayed |
| Skill CRUD | none | none |
| Demo Skill fallback | none | none |
| Execution UI | Demo/partial guidance | Still NOT YET (P50) |

## 235. Test Coverage Matrix

| Concern | Covered |
| --- | --- |
| 21 Skills validate | YES |
| READY provenance | YES |
| Deferred not invented | YES |
| Global claim merge | YES |
| Abstain on missing/stale/blocked | YES |
| Optional absence eligible | YES |
| Provider semantic bans | YES |
| Fingerprint determinism | YES |
| No Finding/Task on load | YES |
| No external brand keys | YES |

## 236. Domain Boundary Matrix

| Domain action from Skill definition | Allowed? |
| --- | --- |
| Emit analysis methodology | YES |
| Auto-create Finding/Opportunity/Recommendation/Task | NO |
| Emit Domain Event / Notification | NO |
| External write | NO |
| Replace Evidence / Data contracts | NO |
| Replace Playbooks | NO |

## 237. Prompt 49 → 50 Reality Handoff Matrix

| Capability | After Prompt 49 | Prompt 50 expectation |
| --- | --- | --- |
| Definitions | CONVERGED / REAL | Consume as-is |
| Eligibility codes | Defined | Enforce on runs |
| Fingerprint / signature | REAL | Record in Run provenance |
| AI execution | NOT YET | Implement grounded execution |
| Provider writes | NO | Remains NO |
| Deferred Skills | DEFERRED | Do not invent unless Architect reopens |
| LLM calls | 0 in P49 | Allowed only via Control Plane routes |
