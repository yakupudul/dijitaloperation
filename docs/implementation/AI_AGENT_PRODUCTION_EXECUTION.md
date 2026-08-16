# AI AGENT PRODUCTION EXECUTION

## STATUS: SPEC / IMPLEMENTING (Prompt 50)

**Prompt:** 50  
**Canonical path:** `docs/implementation/AI_AGENT_PRODUCTION_EXECUTION.md`  
**Product:** [`docs/product/AGENT_SKILL_ARCHITECTURE.md`](../product/AGENT_SKILL_ARCHITECTURE.md) · [`docs/product/AI_CONTROL_PLANE.md`](../product/AI_CONTROL_PLANE.md)  
**Depends on:** Prompt 49 Skill Normalization · AI Control Plane V1 · Agent Profiles + Skill Library V1 · existing `*AiGuidanceService` / `WebsiteAiRecommendationService`  
**Base HEAD:** Prompt 49 `aca1e7d`  
**Branch:** `cursor/ai-agent-production-execution-ea01`

| Fact | Value |
| --- | --- |
| AI Providers | openai / anthropic / gemini via CoreIntegration credentials + `AiProviderCatalog` + `AiProviderRuntimeConfig` — **REAL** (no `AiProviderV2`) |
| AI Routes | `AiRouteRegistry` + `AiRouteResolver` + `ai_route_steps` — versioned via route signature — **REAL** |
| Agents | `AgentProfileRegistry` code-defined; **7** specialists (4 operational with LLM; 3 designed) |
| Skills | Prompt 49 normalized Markdown; **21** Skills — definitions **REAL** |
| Existing execution | `*AiGuidanceService` / `WebsiteAiRecommendationService` use `laravel/ai` structured agents; `Run` + Evidence(`ai_insight`) persistence |
| Prompt 50 adds | `EvidencePack`, `AgentExecutionPlanner`, `AgentExecutionRun` / `SkillExecutionRun` / `AiProviderAttempt`, `StructuredAgentOutputValidator`, `AgentContextGateway`, `getForModule` enforcement, pre-inference abstention, raw-DB boundary tests |
| Allowed Evidence V1 | Union of Eligible Skills’ evidence requirements (Agent cannot expand Skills) |
| Autonomous domain writes | **NO** |
| Provider writes / public web / MCP / shell | **NO** |
| Chain-of-thought / magic scores | **NO** |
| Fake AI | Tests only via `::fake()` |
| Demo UI shells | Real registries |

---

## 1. Purpose

Prompt 50 productionizes **grounded AI Agent / Skill execution**: assemble eligible Skills + Evidence as untrusted data, abstain before inference when Evidence is insufficient, route only through the AI Control Plane, validate structured output, and persist provenance — without autonomous domain writes, provider writes, raw-DB Agent access, MCP/shell, chain-of-thought, or magic scores.

```text
Prompt 49 Skill definitions + eligibility codes
  → Prompt 50 grounded Agent / Skill execution
    → Prompt 51 memory / retrieval (NOT YET)
```

## 2. Scope

In scope:

- Formalize Evidence Pack + deterministic Agent Execution Planner
- Enforce Prompt 49 eligibility / abstention at live run time (pre-inference)
- Bounded `AgentContextGateway` packing; module-scoped `SkillRegistry::getForModule`
- Persist `AgentExecutionRun` / `SkillExecutionRun` / `AiProviderAttempt` alongside existing `Run` + Evidence(`ai_insight`)
- Structured output validation + grounding against the Evidence Pack
- Wire operational specialists (Website SEO, Brand Discovery, Google Ads, Meta Ads) through the planner/pack/validator path
- Raw-DB Agent boundary tests; Demo Settings shells remain registry-backed

Out of scope (enforced):

- GBP / GA4 / GSC live LLM execution (profiles remain **designed**)
- Memory / retrieval / RAG / embeddings (Prompt 51)
- Skill DB / SkillV2 / Agent DB / AiProviderV2
- Capability Router; Playbook runtime orchestration of Agents
- Autonomous Finding / Opportunity / Recommendation / Task writes
- External platform writes; public web crawl; MCP; shell/PHP Skill execution

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Product blueprints (`AGENT_SKILL_ARCHITECTURE`, `AI_CONTROL_PLANE`, module AI Insights) |
| 3 | Prompt 49 Skill contracts + eligibility reason codes |
| 4 | This implementation spec |
| 5 | Existing module `*AiGuidanceService` conventions |

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | AI is advisory; humans gate Recommendations / Tasks |
| R2 | Missing / unavailable Evidence → abstain (never invent zeros / health) |
| R3 | No magic composite SEO / GEO / health / AI-visibility / confidence scores |
| R4 | No external writes; no provider mutation APIs |
| R5 | No Task / Finding / Opportunity / Recommendation auto-create from Agent runs |
| R6 | Evidence is untrusted data (prompt-injection defense) |
| R7 | Agent Allowed Evidence V1 = union of **eligible** Skills’ required+optional keys — Agent cannot expand Skills |
| R8 | LLM calls only via AI Control Plane routes + `laravel/ai` |
| R9 | Fake providers only in tests (`::fake()`); never ship fake providers in production |
| R10 | Designed Agents (GBP/GA4/GSC) must not be claimed as live execution |
| R11 | No chain-of-thought / scratchpad / internal_reasoning in structured output |
| R12 | No raw-DB table/model-name access from Agent context (typed gateway only) |

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Prompt 49 HEAD | `aca1e7d` |
| Working branch | `cursor/ai-agent-production-execution-ea01` |
| Prior docs | `docs/implementation/MOXDOP_SKILL_NORMALIZATION.md`, Skill satellites |

## 6. Prompt 49 Input Audit

Prompt 49 delivered normalized Markdown Skill definitions (21 Skills), `SkillDefinitionValidator`, `SkillEligibilityEvaluator` reason codes, definition fingerprints, and Settings catalog read-only UI. It explicitly deferred **execution**. Prompt 50 consumes definitions + codes as-is and owns runtime enforcement.

## 7. Existing AI Provider Audit

| Provider | Identity | Credentials | Runtime |
| --- | --- | --- | --- |
| OpenAI | `AiProviderCatalog::OPENAI` | CoreIntegration + resolver | `AiProviderRuntimeConfig` |
| Anthropic | `AiProviderCatalog::ANTHROPIC` | CoreIntegration + resolver | same |
| Gemini | `AiProviderCatalog::GEMINI` | CoreIntegration + resolver | same |

**REAL.** No aggregator providers. No `AiProviderV2`.

## 8. Existing AI Route Audit

| Route key | Module | Typical Agent |
| --- | --- | --- |
| `website.ai_guidance` | website | Website SEO Analyst |
| `website.discovery_context` | website | Brand Discovery Analyst |
| `google_ads.ai_guidance` | google-ads | Google Ads Analyst |
| `meta_ads.ai_guidance` | meta-ads | Meta Ads Analyst |
| `gbp.ai_guidance` | google-business-profile | GBP (designed) |
| `ga4.ai_guidance` | website | GA4 (designed) |
| `gsc.ai_guidance` | website | GSC (designed) |

`AiRouteResolver` builds eligible provider/model chains from `ai_route_steps` (persisted) or registry defaults; emits sanitized **route signature** for fingerprints. **REAL.**

## 9. Existing Agent Profile Audit

| Slug | Status | Module | Route | LLM pipeline today |
| --- | --- | --- | --- | --- |
| `website.seo_analyst` | operational | website | `website.ai_guidance` | YES — Website AI Guidance |
| `website.brand_discovery_analyst` | operational | website | `website.discovery_context` | YES — Discovery inference |
| `google_ads.analyst` | operational | google-ads | `google_ads.ai_guidance` | YES |
| `meta_ads.analyst` | operational | meta-ads | `meta_ads.ai_guidance` | YES |
| `gbp.local_presence_analyst` | designed | google-business-profile | `gbp.ai_guidance` | NO live execution claimed |
| `ga4.measurement_analyst` | designed | website | `ga4.ai_guidance` | NO live execution claimed |
| `gsc.organic_search_analyst` | designed | website | `gsc.ai_guidance` | NO live execution claimed |

Registry: code-defined `AgentProfileRegistry` — **no** `agent_profiles` table.

## 10. Existing Skill Definition Audit

21 Prompt 49 Skills under module `resources/skills/` via `SkillRegistry` / `BuiltInSkillLoader`. Write permissions all NO. Eligibility reason codes defined; live enforcement is Prompt 50.

## 11. Existing Execution Path Audit

| Path | Service | Persistence |
| --- | --- | --- |
| Website SEO | `WebsiteAiRecommendationService` | `Run` + Evidence `ai_insight` |
| Brand Discovery | `DiscoveryInferenceService` | Discovery-owned Evidence / candidates |
| Google Ads | `GoogleAdsAiGuidanceService` | `Run` + Evidence `ai_insight` |
| Meta Ads | `MetaAdsAiGuidanceService` | `Run` + Evidence `ai_insight` |

Stack: module ContextBuilders → Skill assemblers → `AiRouteResolver` → `laravel/ai` structured agents → module grounding validators. Prompt 50 inserts planner / EvidencePack / gateway / validator / execution-run tables into this path without inventing new providers.

## 12. Canonical Execution Architecture Decision

```text
AgentProfile
  → AgentExecutionPlanner (deterministic; NO LLM)
      → eligible Skills + Allowed Evidence union + pre-inference status
  → AiRouteResolver (Control Plane)
  → AgentContextGateway → EvidencePack
  → laravel/ai structured agent (only if READY)
  → StructuredAgentOutputValidator + module grounding
  → AgentExecutionRecorder → AgentExecutionRun / SkillExecutionRun / AiProviderAttempt
  → existing Run + Evidence(ai_insight)
```

## 13. No AiProviderV2 / No SkillV2 Decision

Keep CoreIntegration credentials + catalog + runtime config. Keep Markdown Skills + registries. Do **not** create parallel provider/Skill persistence domains.

## 14. EvidencePack

Immutable value object (`App\Support\Ai\EvidencePack`) packing: customer/brand/asset IDs, subject type, agent slug/version, eligible skill signatures, route key/signature, evidence item stubs (id/type/revision/fingerprint/definition/period/integrity/freshness), context + input fingerprints, packed_at. Manifest is audit-safe (no secrets).

## 15. Allowed Evidence V1 Policy

**Agent Allowed Evidence** = union of all **eligible** Skills’ `requiredEvidence` ∪ `optionalEvidence` keys from `SkillDefinition`. Effective required/optional lists are intersections with that union (identity when the union is the only source). Agents must not invent additional Evidence keys beyond Skill contracts.

## 16. Agent Cannot Expand Skills

Agents consume assigned Skill slugs from `AgentProfileDefinition`. Planner resolves each via `getForModule`. No runtime Skill upload, no self-modifying Skill/Agent, no Skill execution of PHP/shell.

## 17. AgentExecutionPlanner

`App\Services\Ai\AgentExecutionPlanner` — deterministic, **no LLM**. Evaluates each assigned Skill with `SkillEligibilityEvaluator`, builds `AgentExecutionPlan` with evaluations, eligible signatures, blocked skills + reason codes, effective evidence lists, and `preInferenceStatus`.

## 18. Pre-Inference Abstention

| Status | Meaning | LLM call? |
| --- | --- | --- |
| `READY` | ≥1 eligible Skill | Allowed (if route eligible) |
| `ABSTAINED_PRE_INFERENCE` | all Skills ineligible | **NO** |
| `BLOCKED_PRE_INFERENCE` | empty profile / hard block | **NO** |

`AgentExecutionPlan::shouldCallInference()` gates provider calls.

## 19. Skill Eligibility Runtime Enforcement

Prompt 49 reason codes (`missing_required_evidence`, integrity/completeness blocks, missing context, etc.) are enforced on live plans. Missing Evidence never becomes zero/false/healthy claims.

## 20. AgentContextGateway

Contract `App\Contracts\Ai\AgentContextGateway` + service implementation. Typed methods only — **must not** accept table/model names. Packs already-redacted module ContextBuilder payloads into `EvidencePack`. Eloquent allowed only inside the gateway as the packing boundary.

## 21. getForModule Module-Scoped Resolution

Skill slugs may repeat across modules. All Agent/Skill assembly and Settings catalog resolution must use `SkillRegistry::getForModule($module, $slug)` — never ambiguous cross-module `get($slug)` for execution.

## 22. Raw DB Boundary

Agents must not query arbitrary tables or accept raw SQL/table names. Allowed reads flow: module ContextBuilder (redacted) → gateway → EvidencePack. Unit tests assert this boundary (`AiAgentRawDatabaseBoundaryTest`).

## 23. Prompt / Context Assembly

Module ContextBuilders remain authoritative for Brand Intelligence snapshots, Finding caps, Evidence redaction, and deterministic Recommendation baselines. Prompt 50 does not invent Brand facts.

## 24. Untrusted Evidence Data Posture

Evidence content is data, not instructions. Skills/methodology are trusted curated text; Evidence payloads are untrusted. Output must cite only Evidence IDs present in the pack.

## 25. AI Route Resolution

Operational runs resolve `profile->aiRouteKey` via `AiRouteResolver`. Empty eligible provider chain → fail closed with operator-facing configuration guidance (Integrations + AI Control Plane). Designed Agents keep registered routes for catalog honesty but do not claim live pipelines.

## 26. Route Signature / Versioning

Route signature is sanitized (provider/model order; never secrets). Combined with prompt/schema versions, agent signature, skill signatures, and input fingerprint for reuse / provenance.

## 27. Provider Attempt Recording (`AiProviderAttempt`)

Each provider/model try (primary/fallback) records safe provenance: provider, model, role, success/failure, latency/token usage when available, error class (no secrets). Supports Control Plane failover audits.

## 28. laravel/ai Structured Agents

Continue module-owned structured agents (e.g. `WebsiteRecommendationAgent`, Google/Meta equivalents). Prompt 50 does not replace `laravel/ai` with a custom HTTP client.

## 29. Fake AI Testing (`::fake()`)

PHPUnit uses `SomeAgent::fake([...])` / `preventStrayPrompts()`. Production code paths must not ship fake providers or Demo LLM responses as real guidance.

## 30. StructuredAgentOutputValidator

`StructuredAgentOutputValidator` rejects:

- reasoning keys: `chain_of_thought`, `internal_reasoning`, `scratchpad`
- magic score keys: `confidence_score`, `ai_score`, `seo_score`
- `evidence_id` / `evidence_ids` outside the Evidence Pack
- conclusion types outside allowed Skill conclusion space (when supplied)

Does **not** write Findings / Opportunities / Recommendations / Tasks.

## 31. Grounding Validation

Module grounding validators remain (Finding IDs / Evidence IDs must exist in supplied context). Prompt 50 validator is the shared EvidencePack subset gate; modules may add domain rules.

## 32. No Chain-of-Thought

Structured schemas must not request or persist CoT. Validator fails closed if forbidden reasoning keys appear.

## 33. No Magic Scores

No composite health / SEO / GEO / AI-visibility / confidence numeric scores as product truth. Qualitative priority labels allowed only when schema-defined and non-numeric-score.

## 34. AgentExecutionRun

Persists one Agent execution attempt: agent slug/version, route key/signature, pre-inference status, eligible/blocked skill signatures, EvidencePack manifest reference, link to `Run` when inference occurred, status/outcome, timestamps. Not a second Result entity.

## 35. SkillExecutionRun

Child rows per Skill evaluation/execution within an Agent run: skill signature, eligibility status, reason codes, optional contribution flags. Definitions remain Markdown — these rows are **run provenance**, not SkillV2.

## 36. Run + Evidence(`ai_insight`) Persistence

Successful operational inference continues to write Core `Run` + Evidence type `ai_insight` (`derived` / AI provenance). Previous `ai_insight` Evidence stays excluded from grounding inputs by default (no recursive AI-as-fact).

## 37. Provenance Fields

Safe fields: `ai_route_key`, route signature, provider/model chain, successful provider/model, `fallback_occurred`, agent profile slug/version, skill signatures + definition fingerprints, prompt/schema versions, EvidencePack fingerprints, token usage when available. **Never credentials.**

## 38. AgentExecutionRecorder

Recorder service coordinates persistence of Agent/Skill/Attempt rows and attaches provenance onto existing Run metadata without creating Tasks or Recommendations.

## 39. Operational Specialist — Website SEO

`website.seo_analyst` @ `1.0.0` — Skills include technical-seo-analysis, indexability-analysis, metadata-consistency, search-console-analysis, keyword-opportunity-analysis, recommendation-framing. Route `website.ai_guidance`. Manual trigger. **Agent execution REAL** under Prompt 50.

## 40. Operational Specialist — Brand Discovery

`website.brand_discovery_analyst` — Skill `brand-context-discovery`; route `website.discovery_context`. Public discovery inferences; candidates remain human-reviewed. **Agent execution REAL.**

## 41. Operational Specialist — Google Ads

`google_ads.analyst` — five Google Ads Skills; route `google_ads.ai_guidance`. **Agent execution REAL.**

## 42. Operational Specialist — Meta Ads

`meta_ads.analyst` — five Meta Ads Skills; route `meta_ads.ai_guidance`. **Agent execution REAL.**

## 43. Designed Specialist — GBP

`gbp.local_presence_analyst` status **designed**. Profile + route + Skills registered for catalog honesty. **No live GBP AI execution claimed** in Prompt 50.

## 44. Designed Specialist — GA4

`ga4.measurement_analyst` status **designed**. Skill `ga4-measurement-quality` may be consumed by Website SEO where assigned/eligible; dedicated GA4 live pipeline **NOT YET**.

## 45. Designed Specialist — GSC

`gsc.organic_search_analyst` status **designed**. Skill `gsc-search-demand-review` may appear on Website where assigned; dedicated GSC live pipeline **NOT YET**.

## 46. Demo UI Shells / Real Registries

Settings / Demo AI Agents, Skills, and Control Plane pages read `AgentProfileRegistry`, `SkillRegistry`, `AiRouteRegistry` — not Demo-invented provider lists. Demo shells must not fabricate fake AI providers.

## 47. No Autonomous Domain Writes

Agent execution may draft guidance inside `ai_insight` Evidence. It must not auto-create Findings, Opportunities, Recommendations, Tasks, Approvals, QA, Notifications, or Domain Events beyond existing explicit product wiring.

## 48. No Provider Writes

Read-only Integrations posture unchanged. Agents cannot mutate Google Ads / Meta Ads / GSC / GA4 / GBP / Website platforms.

## 49. No Public Web / MCP / Shell

No MCP tools, unbounded web search, shell, PHP eval, or remote Skill loading in Agent execution.

## 50. Failover Semantics

AI Router native failover across eligible OpenAI → Anthropic → Gemini steps when configured. Each attempt recorded. Exhausted chain → fail closed; no silent Demo fallback guidance.

## 51. Idempotency / Fingerprints

Reuse successful insights when input fingerprint matches (prompt/schema + route signature + agent/skills + Findings/Evidence/Brand state). Identical successful insight → no second model call.

## 52. Concurrency

Manual triggers only for V1 operational guidance. Concurrent duplicate triggers should reuse fingerprint or serialize per asset+route; no swarm loops.

## 53. Authorization

Existing `web` guard + Filament `/app` permissions. Agents inherit operator authorization; no Agent principal that bypasses tenancy.

## 54. Tenancy / Isolation

EvidencePack carries customer/brand/asset IDs. ContextBuilders must not include unrelated Customers/Brands/Assets. Forbidden operations include cross-tenant reads.

## 55. Privacy / Secrets

No API keys, OAuth tokens, Application Passwords, or raw HTML bodies in EvidencePack / prompts. Redaction remains ContextBuilder responsibility.

## 56. Performance / Cost Guards

Fingerprint reuse; bounded Finding/Evidence caps; no automatic post-collect AI; provider attempts logged for cost diagnosis. Aggregator providers remain out of V1.

## 57. Files and Ownership

| Area | Owner |
| --- | --- |
| EvidencePack, AgentExecutionPlan, Planner, Gateway, Validator, Recorder, models/migrations | Core |
| ContextBuilders, structured agents, module grounding, profile/Skill Markdown | Modules |
| AI routes / provider catalog | Core + module route registration |
| Settings Demo shells | App Livewire/Filament (registry-backed) |

Core must not contain Website/Ads methodology copy.

## 58. Migrations / Tables

Prompt 50 introduces persistence for `agent_execution_runs`, `skill_execution_runs`, and `ai_provider_attempts` (names may match models). These are execution provenance tables — **not** Skill/Agent definition stores. Existing `ai_route_steps` and `runs` / `evidences` remain.

## 59. Tests

Required coverage:

- Planner eligibility / pre-inference abstain / READY paths
- Allowed Evidence union policy (Agent cannot expand)
- EvidencePack packing + validator (forbidden CoT/score keys; Evidence ID subset)
- `getForModule` ambiguity safety
- Raw-DB boundary unit tests
- Operational specialist happy paths with `::fake()`
- Designed Agents not claiming live execution
- No domain/provider writes from validator/recorder

## 60. Explicit Non-Goals

- Memory / retrieval / vector RAG (Prompt 51)
- Capability Router
- Playbook-orchestrated multi-Agent runtime
- Operator-uploaded executable Skills
- Recommendation Reviewer second AI call
- GBP/GA4/GSC live execution promotion without explicit follow-on prompt
- Fake production providers

## 61. Memory Boundary (Prompt 51)

Expert/Skill “memory” remains curated Markdown Skills (Prompt 49). Learned memory, retrieval, embeddings: **NOT YET — Prompt 51**. Prompt 50 must not invent a vector store.

## 62. Milestone 5 Capability Reality

Update `MILESTONE_5_PANEL_FREEZE.md`:

- Skills: definitions CONVERGED / REAL; **Agent execution REAL for operational specialists**
- Raw DB Agent access: **FORBIDDEN**
- Memory / retrieval: **NOT YET (Prompt 51)**

## 63. Product Doc Sync

Short Prompt 50 notes in `AGENT_SKILL_ARCHITECTURE.md` and `AI_CONTROL_PLANE.md`. Do not rewrite MASTER_SPEC.

## 64. Reality Matrix

| Capability | State |
| --- | --- |
| AI Providers openai/anthropic/gemini | REAL |
| AI Routes + signatures | REAL |
| AgentProfileRegistry (7) | REAL |
| Skill definitions (21) | REAL (Prompt 49) |
| Operational Agent LLM paths | REAL |
| Designed GBP/GA4/GSC live execution | NOT YET (designed only) |
| EvidencePack + Planner + pre-inference | Prompt 50 (required) |
| StructuredAgentOutputValidator | Prompt 50 (required) |
| Agent/Skill/Attempt run tables | Prompt 50 (required) |
| Raw DB Agent access | FORBIDDEN |
| Memory / retrieval | NOT YET (Prompt 51) |
| Provider / domain autonomous writes | NO |

## 65. Prompt 51 Handoff

Prompt 51 may add memory/retrieval only when knowledge volume justifies it. It must not reopen Skill schema or invent providers. Execution provenance from Prompt 50 remains the audit trail.

## 66. Rollback / Compatibility

Planner/gateway/validator are additive. Module services keep working if recorder tables empty. Do not break existing `ai_insight` Evidence readers. Avoid Skill signature renames without version bumps.

## 67. Implementation Status Snapshot

| Component | Role |
| --- | --- |
| `EvidencePack` | Immutable pack DTO |
| `AgentExecutionPlan` | Deterministic plan + pre-inference status |
| `AgentExecutionPlanner` | Eligibility + Allowed Evidence union |
| `AgentContextGateway` | Typed pack assembler |
| `StructuredAgentOutputValidator` | Shared output gate |
| `AgentExecutionRun` / `SkillExecutionRun` / `AiProviderAttempt` | Provenance models |
| `AgentExecutionRecorder` | Persistence coordinator |
| Module `*AiGuidanceService` | Operational inference owners |

## 68. Definition of Done

| Gate | Required |
| --- | --- |
| Base Prompt 49 HEAD `aca1e7d` recorded | YES |
| Branch `cursor/ai-agent-production-execution-ea01` | YES |
| Providers REAL; no AiProviderV2 / fake production providers | YES |
| Routes REAL with signatures | YES |
| 7 Agents documented (4 operational / 3 designed) | YES |
| 21 Skills consumed; no SkillV2 | YES |
| EvidencePack + Planner + pre-inference abstention | YES |
| Allowed Evidence = eligible Skills union; Agent cannot expand | YES |
| Gateway typed; raw-DB Agent FORBIDDEN + tests | YES |
| `getForModule` used for module-scoped resolution | YES |
| Structured validator blocks CoT + magic scores | YES |
| Agent/Skill/Attempt persistence + Run/`ai_insight` | YES |
| GBP/GA4/GSC not claimed live | YES |
| Demo UI uses real registries | YES |
| No autonomous domain/provider writes; no MCP/shell/web | YES |
| Fake AI only via `::fake()` in tests | YES |
| Milestone 5 + product Prompt 50 notes updated | YES |
| Sections 1–68 + matrices 369–393 present | YES |
| Memory remains NOT YET Prompt 51 | YES |

---

## MANDATORY MATRICES (369–393)

## 369. Existing AI Provider Matrix

| Provider | Catalog | Credentials | Aggregator? | Decision |
| --- | --- | --- | --- | --- |
| OpenAI | REAL | CoreIntegration | NO | KEEP |
| Anthropic | REAL | CoreIntegration | NO | KEEP |
| Gemini | REAL | CoreIntegration | NO | KEEP |
| OpenRouter / Groq / etc. | — | — | YES | DO NOT ADD |
| AiProviderV2 | — | — | — | DO NOT CREATE |

## 370. Existing AI Route Matrix

| Route | Module | Persisted steps | Signature | Live Agent |
| --- | --- | --- | --- | --- |
| `website.ai_guidance` | website | `ai_route_steps` | YES | SEO Analyst |
| `website.discovery_context` | website | `ai_route_steps` | YES | Brand Discovery |
| `google_ads.ai_guidance` | google-ads | `ai_route_steps` | YES | Google Ads Analyst |
| `meta_ads.ai_guidance` | meta-ads | `ai_route_steps` | YES | Meta Ads Analyst |
| `gbp.ai_guidance` | gbp | registered | YES | designed only |
| `ga4.ai_guidance` | website | registered | YES | designed only |
| `gsc.ai_guidance` | website | registered | YES | designed only |

## 371. Agent Profile Status Matrix

| Agent | Status | LLM execution (P50) |
| --- | --- | --- |
| Website SEO Analyst | operational | REAL |
| Brand Discovery Analyst | operational | REAL |
| Google Ads Analyst | operational | REAL |
| Meta Ads Analyst | operational | REAL |
| GBP Local Presence Analyst | designed | NOT YET |
| GA4 Measurement Analyst | designed | NOT YET |
| GSC Organic Search Analyst | designed | NOT YET |

## 372. Skill Inventory Matrix

| Module | Count | Storage | Execution owner |
| --- | --- | --- | --- |
| website | 9 | Markdown | Prompt 50 runtime |
| google-ads | 5 | Markdown | Prompt 50 runtime |
| meta-ads | 5 | Markdown | Prompt 50 runtime |
| google-business-profile | 2 | Markdown | definitions only (designed Agent) |
| Skills DB / SkillV2 | 0 | — | FORBIDDEN |

## 373. Existing Execution Path Matrix

| Service | Structured agent | Evidence type | Planner integration |
| --- | --- | --- | --- |
| WebsiteAiRecommendationService | YES | `ai_insight` | REQUIRED |
| DiscoveryInferenceService | YES | discovery Evidence | REQUIRED |
| GoogleAdsAiGuidanceService | YES | `ai_insight` | REQUIRED |
| MetaAdsAiGuidanceService | YES | `ai_insight` | REQUIRED |
| GBP/GA4/GSC dedicated | NO | — | NOT CLAIMED |

## 374. EvidencePack Field Matrix

| Field | In pack? | Secrets? |
| --- | --- | --- |
| customer/brand/asset IDs | YES | NO |
| agent slug/version | YES | NO |
| skill signatures | YES | NO |
| route key/signature | YES | NO |
| evidence id/type/fingerprint | YES | NO |
| credentials / raw HTML | NO | — |

## 375. Allowed Evidence Policy Matrix

| Source | Expands Agent Allowed Evidence? |
| --- | --- |
| Eligible Skill required keys | YES (union) |
| Eligible Skill optional keys | YES (union) |
| Ineligible Skill keys | NO |
| Agent inventing new keys | NO |
| Capability metadata alone | NO (metadata only) |

## 376. Pre-Inference Status Matrix

| Status | Eligible Skills | Call LLM? | Persist attempt? |
| --- | --- | --- | --- |
| READY | ≥1 | YES if route OK | YES |
| ABSTAINED_PRE_INFERENCE | 0 | NO | YES (abstain record) |
| BLOCKED_PRE_INFERENCE | n/a | NO | YES (block record) |

## 377. Abstention Reason Code Runtime Matrix

| Code class | Origin | P50 action |
| --- | --- | --- |
| missing_required_evidence | Prompt 49 | Abstain Skill / possibly all |
| integrity / completeness blocked | Prompt 49 | Abstain Skill |
| missing_context | Prompt 49 | Abstain Skill |
| empty_agent_skill_profile | Prompt 50 | BLOCKED_PRE_INFERENCE |
| all_skills_ineligible | Prompt 50 | ABSTAINED_PRE_INFERENCE |

## 378. AgentContextGateway Boundary Matrix

| Input | Allowed? |
| --- | --- |
| Typed `DigitalAsset` + profile + plan | YES |
| Redacted context array from ContextBuilder | YES |
| Explicit evidence/finding ID lists | YES |
| Table name / model class string | NO |
| Arbitrary DB query DSL | NO |

## 379. Raw DB Access Boundary Matrix

| Actor | Arbitrary Eloquent/SQL? | Decision |
| --- | --- | --- |
| Agent prompt / Skill | NO | FORBIDDEN |
| AgentContextGateway (pack only) | Limited loadMissing | ALLOWED boundary |
| Module ContextBuilder | Scoped reads + redact | ALLOWED |
| Raw-DB boundary tests | Assert forbid | REQUIRED |

## 380. getForModule Resolution Matrix

| Call site | API | Ambiguous slug risk |
| --- | --- | --- |
| Planner | `getForModule` | Mitigated |
| Skill assemblers | `getForModule` | Mitigated |
| Settings / Demo catalog | `getForModule` | Mitigated |
| Bare `get($slug)` for execution | — | FORBIDDEN for execution |

## 381. Structured Output Validation Matrix

| Check | On fail |
| --- | --- |
| Evidence IDs ⊆ pack | reject |
| Allowed conclusions (when set) | reject |
| Module grounding Finding IDs | reject (module) |
| Writes Finding/Rec/Task | — | never |

## 382. Forbidden Output Key Matrix

| Key | Class | Action |
| --- | --- | --- |
| chain_of_thought | reasoning | REJECT |
| internal_reasoning | reasoning | REJECT |
| scratchpad | reasoning | REJECT |
| confidence_score | magic score | REJECT |
| ai_score | magic score | REJECT |
| seo_score | magic score | REJECT |

## 383. AgentExecutionRun / SkillExecutionRun Matrix

| Table/model | Stores definitions? | Purpose |
| --- | --- | --- |
| AgentExecutionRun | NO | Agent run provenance |
| SkillExecutionRun | NO | Per-Skill eligibility/run provenance |
| Skill Markdown | YES (definitions) | Unchanged |
| agent_profiles table | — | DO NOT CREATE |

## 384. AiProviderAttempt Matrix

| Field | Record? |
| --- | --- |
| provider / model / role | YES |
| success / error class | YES |
| tokens / latency | WHEN AVAILABLE |
| API key / raw request secrets | NO |

## 385. Operational vs Designed Specialist Matrix

| Specialist | Catalog visible | Live P50 execution |
| --- | --- | --- |
| Website SEO / Brand Discovery / Google Ads / Meta Ads | YES | YES |
| GBP / GA4 / GSC | YES | NO |

## 386. Domain Write Boundary Matrix

| Write | From Agent run? |
| --- | --- |
| Evidence `ai_insight` + Run | YES (advisory) |
| Finding / Opportunity | NO |
| Recommendation / Task | NO (human) |
| Approval / QA / Notification spam | NO |

## 387. Provider Write Boundary Matrix

| Action | Allowed? |
| --- | --- |
| Read normalized Evidence | YES |
| Call AI provider chat/structured | YES (Control Plane) |
| Mutate Ads/Meta/GSC/GA4/GBP/Website | NO |

## 388. Demo UI / Registry Matrix

| UI | Data source | Fake providers? |
| --- | --- | --- |
| Settings AI Agents | AgentProfileRegistry | NO |
| Settings Skill Library | SkillRegistry | NO |
| Settings AI Control Plane | AiRouteRegistry + steps | NO |
| Demo Settings shells | same registries | NO |

## 389. Fake AI Test Matrix

| Environment | Mechanism | Allowed? |
| --- | --- | --- |
| PHPUnit | `::fake()` / preventStrayPrompts | YES |
| Production / UAT | fake provider responses as truth | NO |
| Demo inventory | invented LLM vendors | NO |

## 390. Provenance Matrix

| Item | In Run / execution metadata |
| --- | --- |
| route key + signature | YES |
| agent slug/version | YES |
| skill signatures + fingerprints | YES |
| provider chain + winner | YES |
| EvidencePack fingerprints | YES |
| credentials | NEVER |

## 391. Domain Boundary Matrix

| Concern | Owner |
| --- | --- |
| Skill methodology | Module Markdown |
| Agent persona | Module profile class |
| Execution primitives | Core |
| Control Plane routing | Core |
| Memory/retrieval | Prompt 51 — not P50 |

## 392. Memory / Retrieval Boundary Matrix

| Layer | State |
| --- | --- |
| Curated Skill Markdown | REAL (Prompt 49) |
| Agent execution provenance | Prompt 50 |
| Learned memory / embeddings / RAG | NOT YET (Prompt 51) |

## 393. Prompt 50 Reality / DoD Handoff Matrix

| Capability | After Prompt 50 | Next |
| --- | --- | --- |
| Operational Agent execution | REAL | Maintain |
| Designed GBP/GA4/GSC live exec | NOT YET | Explicit follow-on |
| Raw DB Agent | FORBIDDEN | Keep forbidden |
| Memory / retrieval | NOT YET | Prompt 51 |
| Provider / domain writes | NO | Remains NO |
| Fake AI outside tests | NO | Remains NO |
