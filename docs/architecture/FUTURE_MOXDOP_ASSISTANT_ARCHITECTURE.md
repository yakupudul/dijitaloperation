# FUTURE MOXDOP ASSISTANT ARCHITECTURE

> Prompt 56 — bounded natural-language interface over canonical MoxDOP capabilities.  
> Implementation: `app/Services/Assistant/`, `app/Support/Assistant/`  
> Contracts: [`ASSISTANT_CAPABILITY_CONTRACT.md`](ASSISTANT_CAPABILITY_CONTRACT.md), [`ASSISTANT_ANSWER_CONTRACT.md`](ASSISTANT_ANSWER_CONTRACT.md), [`ASSISTANT_SOURCE_AUTHORITY.md`](ASSISTANT_SOURCE_AUTHORITY.md)  
> Tests: `tests/Feature/Assistant/FutureAssistantArchitectureTest.php`

**Critical product truth:** The Assistant is a **natural-language interface over bounded canonical MoxDOP capabilities** — **not** a generic database chatbot, not text-to-SQL, not arbitrary Eloquent access.

**Code entry:** `MoxdopAssistantService::ask()`

---

## 1. Purpose

Provide a **read-only**, **server-validated**, **source-grounded** question-answering layer that routes operator questions to existing MoxDOP services (provider pool reads, Finding/Opportunity/Work/Evidence reads, Brand Experience, Sector Memory, Skill guidance, Specialist analysis contracts) without inventing parallel data paths, UI surfaces, or write actions.

## 2. Scope

| In scope | Out of scope |
| --- | --- |
| Typed intent → validated query plan → capability execution | Chat UI, sidebar item, floating button |
| Deterministic provider metric lookup from persisted pool | Live provider API calls during Assistant reads |
| Canonical domain lookups (Finding, Opportunity, Work, Evidence) | Task / Recommendation / Goal / campaign writes |
| Same-Brand Brand Experience history | Cross-Customer search, “similar customer” |
| Prompt 53 released Sector artifacts only | Raw DB tools, schema exposure, embeddings |
| Specialist analysis route via Prompt 50 + 54 contracts | Fine-tuning, vector DB, Assistant V2 |
| Multi-turn `AssistantThreadState` carry-forward | Conversation persisted as Brand Memory |

## 3. Authority Model

| Layer | Authority |
| --- | --- |
| `MASTER_SPEC` + accepted ADRs | Product truth |
| `AssistantCapabilityRegistry` v1 | Allowed capabilities |
| `AssistantMetricRegistry` | Allowed provider metrics |
| `AssistantSourceAuthority` v1 | Semantic claim classes |
| Server validation (`AssistantIntentValidator`, `AssistantScopeResolver`, `AssistantQueryPlanner`) | **Mandatory before any read** |
| Model / `AssistantIntentInterpreter` | Produces `AssistantIntentCandidate` only — never executes reads |

## 4. Hard Product Rules

1. **Assistant ≠ generic DB chatbot** — no SQL, no schema, no arbitrary Eloquent.
2. **No Chat UI** in this milestone (no sidebar, no floating button).
3. **No** `DATABASE_QUERY`, `ALL_MEMORY_SEARCH`, `CROSS_CUSTOMER_SEARCH`, or forbidden capability IDs.
4. **No first-Customer / first-Brand / first-Asset fallback** — ambiguous scope → clarification.
5. Model interprets to typed `AssistantIntentCandidate` only; server validates before any read.
6. **Fact lookup** from persisted provider data via adapters (`GoogleAdsAssistantReadAdapter` → `GoogleAdsSpecialistBindingResolver` + `GoogleAdsPoolReadRepository`); **AI cost 0**; **no live provider calls**.
7. Findings / Opportunities / Work / Evidence / Brand Experience / Sector / Skill via **canonical services**.
8. **Sector** = Prompt 53 released artifacts only; “similar” ≠ nearest Customer.
9. **Brand Experience** = same-Brand only.
10. **Most important Opportunity:** no first-row, no magic score; clarify if no unique canonical priority.
11. Reuse **Prompt 50 Agents** + **Prompt 54 Retrieval** + **Prompt 55 Evaluation** hooks (`AssistantEvaluationHooks`).
12. **Read-only:** no Task / Recommendation / Goal / campaign / provider writes.
13. **Conversation ≠ Brand Memory / Evidence / Task**.
14. **No** fine-tuning, embeddings, vector DB.

## 5. Base HEAD and Branch

Architecture lands on `main` via feature branch `cursor/future-assistant-architecture-ea01` (or successor `cursor/*-32f2` branches). No parallel `AssistantV2` / `ChatV2` / `MoxdopBrainChatService` classes (`AssistantBoundaryGuard` enforces).

## 6. Prompt 50 / 54 / 55 Input Audit

| Prior prompt | Assistant reuse |
| --- | --- |
| P50 Agents | `SpecialistAnalysis` capability pins agent/skill signatures; retrieval-ready route |
| P54 Retrieval | `IntelligenceRetrievalService::retrieve()` in `executeSpecialistRoute()` — no parallel RAG stack |
| P55 Evaluation | `AssistantEvaluationHooks` maps answers to `intelligence_evaluation_v1` dimensions |

`MoxdopAssistantService::architectureSnapshot()` asserts `prompt_50_reuse`, `prompt_54_reuse`, `prompt_55_evaluation_hooks` = true.

## 7. Existing Assistant Primitive Audit

### Primitive audit matrix

| Primitive | Location | Role | Status |
| --- | --- | --- | --- |
| Orchestrator | `MoxdopAssistantService` | `ask()` entry | REAL |
| Intent candidate | `AssistantIntentCandidate` | Model output shape | REAL |
| Intent interpreter | `AssistantIntentInterpreter` | Deterministic NL stub (no LLM) | REAL |
| Intent validator | `AssistantIntentValidator` | Pre-read validation | REAL |
| Scope resolver | `AssistantScopeResolver` | Authorization + scope | REAL |
| Query planner | `AssistantQueryPlanner` | Typed `AssistantQueryPlan` | REAL |
| Capability executor | `AssistantCapabilityExecutor` | Bounded reads | REAL |
| Grounding validator | `AssistantAnswerGroundingValidator` | Claim/source enforcement | REAL |
| Boundary guard | `AssistantBoundaryGuard` | Forbidden patterns scan | REAL |
| Capability registry | `AssistantCapabilityRegistry` v1 | Allow-list | REAL |
| Metric registry | `AssistantMetricRegistry` | Provider metrics | REAL |
| Source authority | `AssistantSourceAuthority` v1 | Semantic classes | REAL |
| Date resolver | `AssistantDateRangeResolver` | Period tokens | REAL |
| Google Ads adapter | `GoogleAdsAssistantReadAdapter` | Pool read | REAL |
| Evaluation hooks | `AssistantEvaluationHooks` | P55 compat | REAL |
| Chat UI | — | — | NOT IMPLEMENTED |
| Text-to-SQL | — | — | FORBIDDEN |
| Vector / embeddings | — | — | FORBIDDEN |

## 8. Why Not Generic DB Chatbot

MoxDOP already owns canonical domain services, provider pools, privacy gates, and agent contracts. A generic DB chatbot would bypass authorization, invent metrics, leak cross-brand data, and conflate memory with current facts. The Assistant routes **only** through registries and canonical read services.

## 9. Canonical Assistant Architecture Decision

**Pipeline:** `ask()` → `AssistantBoundaryGuard` → `AssistantScopeResolver` → `AssistantIntentValidator` → `AssistantQueryPlanner` → `AssistantCapabilityExecutor` → `AssistantAnswerGroundingValidator` → `AssistantAnswer`.

No step allows the model to execute SQL, choose tables, or expand scope beyond caller-supplied authorization lists.

## 10. No Chat UI / Sidebar / Floating Button

`architectureSnapshot()` exposes `chat_ui`, `sidebar_item`, `floating_button` = **false**. This milestone is **service-layer architecture only** — no Filament panel item, no floating widget, no conversation persistence UI.

## 11. No Text-to-SQL / Raw DB / Schema Exposure

`AssistantBoundaryGuard` scans `app/Services/Assistant/` for `information_schema`, `SHOW TABLES`, `executeSql`, `queryDatabase`, `searchEverything`, embedding/fine-tune APIs. `AssistantIntentValidator` rejects `table`, `column`, `sql` in candidate parameters. Forbidden capability IDs are never registered.

## 12. AssistantCapabilityRegistry v1

Version: `assistant_capability_registry_v1`. Nine capabilities (see [`ASSISTANT_CAPABILITY_CONTRACT.md`](ASSISTANT_CAPABILITY_CONTRACT.md)). `forbiddenCapabilityIds()` lists `database_query`, `all_memory_search`, `cross_customer_search`, `run_sql`, `arbitrary_eloquent`, etc.

## 13. AssistantMetricRegistry

Bounded provider metrics: `google_ads.spend`, `google_ads.impressions`, `google_ads.clicks`, `meta_ads.spend`, `ga4.sessions`, `gsc.clicks`. No display-label lookup; no model-invented metrics. Fact lookup requires registry membership.

## 14. AssistantSourceAuthority v1

Version: `assistant_source_authority_v1`. Semantic matrix per `AssistantSourceClass` — **no numeric authority score**. See [`ASSISTANT_SOURCE_AUTHORITY.md`](ASSISTANT_SOURCE_AUTHORITY.md).

## 15. MoxdopAssistantService Entry Point

```php
MoxdopAssistantService::ask(
    int $userId,
    AssistantIntentCandidate $candidate,
    array $authorizedCustomerIds,
    array $authorizedBrandIds,
    array $authorizedDigitalAssetIds,
    ?int $customerId = null,
    ?int $brandId = null,
    ?int $digitalAssetId = null,
    ?string $timezone = null,
    ?AssistantThreadState $threadState = null,
): AssistantAnswer
```

Caller supplies authorization lists and explicit scope IDs. Thread state may carry forward refs but **cannot** grant access.

## 16. AssistantIntentInterpreter

Optional helper producing `AssistantIntentCandidate` from NL (`interpretDeterministic()` for tests). **Never** invents `customer_id`, `brand_id`, `table`, or `sql`. Write verbs → `UnsupportedWriteAction`. When a live LLM is wired upstream, it must emit the same candidate shape.

## 17. AssistantIntentValidator

Validates candidate before reads: rejects write requests, unknown capabilities, forbidden capabilities, unknown metrics, model-provided IDs in parameters. Returns clarification reasons for missing period on fact lookup.

### Intent matrix

| Intent | Capability | Requires |
| --- | --- | --- |
| `fact_lookup` | `provider_metric_lookup` | metric + period + brand + digital asset |
| `domain_lookup` | finding / opportunity / evidence / work | brand (most domain types) |
| `work_status` | `work_lookup` | customer |
| `historical_context` | `brand_experience_lookup` | brand |
| `sector_context` | `sector_pattern_lookup` | brand |
| `methodology_guidance` | `skill_guidance` | — |
| `intelligence_analysis` | `specialist_analysis` | brand |
| `clarification_required` | — | — |
| `unsupported` / `unsupported_write_action` | — | — |

## 18. AssistantScopeResolver

Builds `AssistantSessionScope` or returns `AssistantClarificationReason`. Validates Customer / Brand / DigitalAsset against authorized ID lists and ownership chain. `requireBrandIfAmbiguous()` **never** auto-picks the only Brand.

### Scope matrix

| Condition | Result |
| --- | --- |
| `customerId` null | `customer_scope_required` |
| Customer not in `authorizedCustomerIds` | `customer_scope_required` |
| Brand not authorized or wrong Customer | `brand_scope_required` |
| Digital asset not authorized or wrong Brand | `digital_asset_scope_required` |
| Multiple Brands, `brandId` null | `brand_scope_required` (no first-Brand) |
| Exactly one matching asset but `digitalAssetId` null | `digital_asset_scope_required` (no first-Asset) |
| Thread state IDs without authorization | clarification (revalidated) |

## 19. AssistantQueryPlanner

Maps validated intent + scope → `AssistantQueryPlan` with `validated: true`, capabilities, answer strategy, date range, agent/skill signatures. Unsupported period token → `date_range_required`. Fact lookup without explicit digital asset → clarification even when only one asset matches.

## 20. AssistantCapabilityExecutor

Executes exactly one primary capability per plan via `match`. Never arbitrary Eloquent. All domain answers pass through `AssistantAnswerGroundingValidator`.

## 21. AssistantAnswerGroundingValidator

Rejects: claims without source refs, unknown refs, source-class impersonation (e.g. Sector as ProviderData), numeric claims without fact sources, cross-scope Brand Experience refs. Full rejection → abstention `unsupported_factual_claim`.

### Claim grounding matrix

| Claim class | Allowed source classes | Forbidden substitution |
| --- | --- | --- |
| `provider_data` | `provider_data` only | sector, skill, brand_experience |
| `evidence` | `evidence` | sector, skill, brand_experience |
| `finding` | `finding` | — |
| `opportunity` | `opportunity` | — |
| `work` | `work` | — |
| `brand_experience` | `brand_experience` (same brand) | cross-brand |
| `sector_pattern` | `sector_pattern` | as provider metric |
| `skill_knowledge` | `skill_knowledge` | as customer fact |
| Numeric value | `provider_data` or `evidence` | memory classes |

## 22. AssistantBoundaryGuard

Called at start of every `ask()`. Fails if forbidden duplicate architecture classes exist or forbidden capabilities are registered. Static scan blocks raw DB / training APIs in Assistant services.

## 23. Fact Lookup Path

1. Intent `fact_lookup` + registry metric + resolved `AssistantDateRange`
2. Scope must include authorized `digitalAssetId`
3. `ProviderMetricLookup` → provider-specific adapter
4. **Google Ads:** `GoogleAdsAssistantReadAdapter::lookupSpend()` (also impressions/clicks via metric id)
5. Build `AssistantClaim` + `AssistantAnswer` with `DeterministicFact` strategy
6. Grounding validation

### Fact lookup matrix

| Step | Server-owned | Model-owned |
| --- | --- | --- |
| Metric ID | `AssistantMetricRegistry` | proposes token only |
| Period | `AssistantDateRangeResolver` | proposes `periodToken` only |
| Scope IDs | caller + resolver | **never** invents IDs |
| Aggregation | adapter / pool repository | **never** |
| Arithmetic | PHP sum in adapter | **never** (`llm_arithmetic: false`) |
| Provider calls | 0 | 0 |

## 24. GoogleAdsAssistantReadAdapter

Wraps `GoogleAdsSpecialistBindingResolver` + `GoogleAdsPoolReadRepository`. Reads `google_ads_account_daily` only. Computes coverage (complete / partial / missing), freshness (fresh ≤2 days else stale), currency from pool. Missing rows → unavailable (not zero).

### Google Ads example matrix

| Input | Persisted data | Output |
| --- | --- | --- |
| `google_ads.spend`, `last_30_days`, scoped asset | 30 daily rows @ €1 | `value: 30.0`, `EUR`, `coverage: complete`, `ai_used: false` |
| Same, 10 rows in range | partial pool | `value: 10.0`, `coverage: partial`, limitation `partial_coverage` |
| Same, 0 rows | empty pool | `abstained`, `missing_as_zero: false`, `strategy: unavailable` |
| Binding not ready | — | `unavailable`, `binding_not_ready` |

## 25. Finding / Opportunity / Evidence / Work Lookups

| Capability | Source service | Scope filter |
| --- | --- | --- |
| `finding_lookup` | `FindingReadService::query()` | customer + brand |
| `opportunity_lookup` | `OpportunityReadService::query()` | customer + brand |
| `evidence_lookup` | canonical `Evidence` query (`is_canonical`) | brand assets or digital asset |
| `work_lookup` | `WorkReadService::workItems()` | customer (+ brand optional) |

### Work matrix

| Work field | Assistant semantics |
| --- | --- |
| `status: done` | does **not** imply QA passed |
| `qa_status` | surfaced; limitation `task_done_does_not_mean_qa_passed` when applicable |
| `current_approval.status` | surfaced; limitation `task_done_does_not_mean_approved` when applicable |
| `task_done_equals_qa_passed` | always **false** in blocks |
| `task_done_equals_approved` | always **false** in blocks |

## 26. Opportunity Ranking / Most Important

When `parameters.most_important` = true:

1. Load open opportunities for scope
2. Group by `qualitative_priority` (`critical` > `high` > `medium` > `low`)
3. If exactly one at top priority → return it (`canonical_order_used: true`, `magic_score: null`, `first_row_fallback: false`)
4. If tie at top → `Clarification` + `canonical_order_unavailable` — **no first row**, **no magic score**

### Opportunity ranking matrix

| Scenario | Behavior |
| --- | --- |
| 0 opportunities | `unavailable` / `no_opportunities` |
| 1 opportunity | return it as most important |
| Multiple, unique top priority | return winner |
| Multiple, tied top priority | clarification with candidates |
| Missing priority field | treated as `medium` |
| AI-invented score | **FORBIDDEN** (`magic_score: null`) |

## 27. Brand Experience Lookup

`BrandExperience` query: `customer_id` + `brand_id` match scope. Max 10 rows. Claims use `historical_context` block type with limitations: `historical_context`, `causality_not_established`, `not_current_metric_source`. Payload flags `same_brand_only: true`, `cross_brand: false`.

### Brand history matrix

| Rule | Enforcement |
| --- | --- |
| Same Brand only | query filter + grounding cross-scope check |
| Not provider metric source | claim class `brand_experience` |
| Not Evidence substitute | grounding rejects memory-as-evidence |
| Current fact wins | see source authority |

## 28. Sector Pattern Lookup

Resolves sector via `SectorIdentityResolver` (no AI-inferred sector). `SectorMemoryReadService::listReleasedForSector()` — **Prompt 53 released artifacts only**. Blocks contributor lineage in serialized answer. `similar_means: privacy_safe_sector_cohort` — **not** nearest Customer.

### Sector matrix

| Rule | Enforcement |
| --- | --- |
| Released artifacts only | `listReleasedForSector` |
| No contributor identities | JSON guard + `sector_contributor_identities: null` |
| Observational only | limitations on claims |
| Not industry benchmark | `industry_benchmark_claim: false` |
| Cross-brand experience text | must not appear in answer |
| `raw_similar_customer` | **false** |

## 29. Skill Guidance

`skill_guidance` capability returns methodology block from `skill_knowledge` source. Limitations: `methodology_only`, `not_customer_fact`, `not_provider_fact`. No customer scope required.

## 30. Specialist Analysis (Prompt 50 + 54 Reuse)

`specialist_analysis` builds `SkillMemoryContract` + `SkillRetrievalContract`, calls `IntelligenceRetrievalService::retrieve()` with agent `website-seo-analyst@1.0.0` / skill `website.technical-seo-analysis@1.1.0` (planner defaults). Returns `SpecialistStructuredAnalysis` with retrieval fingerprint — **no live AI required** for architecture validation (`ai_used: false`, `prompt_50_reuse`, `prompt_54_reuse`).

## 31. Date Range Resolution

`AssistantDateRangeResolver` tokens: `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`. Server computes inclusive date bounds in scope timezone (default UTC). Model proposes `periodToken` only.

### Date matrix

| Token | Semantics (example: 2026-08-16 UTC) |
| --- | --- |
| `last_30_days` | 2026-07-18 … 2026-08-16 inclusive |
| `last_7_days` | 6 days back + today |
| `last_month` | prior calendar month |
| Unsupported token | `date_range_required` clarification |
| Model timestamp math | **FORBIDDEN** |

## 32. Coverage and Freshness Semantics

| `AssistantCoverageState` | Meaning |
| --- | --- |
| `complete` | all requested days have rows |
| `partial` | some days missing → limitation `partial_coverage` |
| `missing` | no rows → abstention |
| `not_applicable` | non-metric answers |

| `AssistantFreshnessState` | Meaning (Google Ads) |
| --- | --- |
| `fresh` | latest reporting_date ≤ 2 days old |
| `stale` | older |
| `unknown` | no rows to judge |

Optional `abstain_if_stale` plan parameter triggers abstention on stale provider data.

## 33. Missing Data / Abstention

**Missing ≠ zero.** Unavailable facts set `abstained: true`, `strategy: unavailable`, block `missing_as_zero: false`. Grounding failure → `unsupported_factual_claim`. No model guess (`model_guess: false`).

### Missing data matrix

| Situation | Answer |
| --- | --- |
| No pool rows | abstain, not 0 |
| Binding not ready | abstain |
| Unknown metric | clarification |
| Sector artifact missing | unavailable |
| Stale + `abstain_if_stale` | abstain |

## 34. AssistantAnswer DTO

Typed answer — never Markdown-only truth (`markdown_only_truth: false`). See [`ASSISTANT_ANSWER_CONTRACT.md`](ASSISTANT_ANSWER_CONTRACT.md).

## 35. AssistantThreadState (Multi-turn)

Carries `customerId`, `brandId`, `digitalAssetId`, `metricId`, `periodToken`, entity refs. Flags: `is_brand_memory: false`, `is_evidence: false`, `is_authorization: false`. Thread refs applied only when still authorized.

### Multi-turn matrix

| Behavior | Rule |
| --- | --- |
| Carry metric/period | planner merges from thread |
| Carry scope IDs | scope resolver revalidates authorization |
| Expand access | **FORBIDDEN** |
| Persist as memory | **FORBIDDEN** |
| Override explicit scope | thread fills nulls only |

## 36. Authorization and Tenancy

Authorization lists are caller-supplied (typically from Filament / policy layer). Assistant never escalates privileges. Customer → Brand → DigitalAsset ownership chain enforced in `AssistantScopeResolver`.

## 37. Read-only Write Boundary

`requestsWrite` or write verbs → `UnsupportedWriteAction`. Runtime provenance: `domain_writes: 0`, `provider_writes: 0`. Block `write_allowed: false`.

### Write boundary matrix

| Action | Assistant |
| --- | --- |
| Pause campaign | REJECTED |
| Create task | REJECTED |
| Accept recommendation | REJECTED |
| Change budget | REJECTED |
| Read metric | ALLOWED |
| Read findings | ALLOWED |

## 38. Source Authority Rules

Current measured fact (Provider Data, Evidence) wins over historical (Brand Experience) and sector cohort observations. Brand fact wins over Sector. No cross-class impersonation. Detail: [`ASSISTANT_SOURCE_AUTHORITY.md`](ASSISTANT_SOURCE_AUTHORITY.md).

### Source authority matrix

| Source class | Satisfies |
| --- | --- |
| `provider_data` | provider metrics |
| `evidence` | evidence-backed facts |
| `finding` | current conditions |
| `opportunity` | potential |
| `work` | execution status |
| `brand_experience` | historical context |
| `sector_pattern` | sector cohort context |
| `skill_knowledge` | methodology |
| `recommendation` | registered; not Assistant executor path |

## 39. Question → Source Routing

### Question → source matrix

| Question pattern | Intent | Source class |
| --- | --- | --- |
| “Google Ads spend last 30 days” | `fact_lookup` | `provider_data` |
| “What findings…” | `domain_lookup` | `finding` |
| “Most important opportunity” | `domain_lookup` | `opportunity` |
| “What are we working on” | `work_status` | `work` |
| “Similar brands in sector” | `sector_context` | `sector_pattern` (not similar customer) |
| “How should we investigate…” | `methodology_guidance` | `skill_knowledge` |
| “Analyze SEO issues” | `intelligence_analysis` | `evidence` (retrieval pack) |
| “Pause campaign” | `unsupported_write_action` | — |

## 40. Capability Contract Summary

Full per-capability contract: [`ASSISTANT_CAPABILITY_CONTRACT.md`](ASSISTANT_CAPABILITY_CONTRACT.md).

### Capability matrix

| ID | AI | Live provider | Read-only |
| --- | --- | --- | --- |
| `provider_metric_lookup` | No | No | Yes |
| `evidence_lookup` | No | No | Yes |
| `finding_lookup` | No | No | Yes |
| `opportunity_lookup` | No | No | Yes |
| `work_lookup` | No | No | Yes |
| `brand_experience_lookup` | No | No | Yes |
| `sector_pattern_lookup` | No | No | Yes |
| `skill_guidance` | No | No | Yes |
| `specialist_analysis` | Optional | No | Yes |

## 41. No Fine-tuning / Embeddings / Vector DB

`snapshot()` and `architectureSnapshot()` set `fine_tuning`, `embeddings`, `vector_db` = false. `AssistantBoundaryGuard` blocks `createEmbedding`, `fineTune`, `pgvector_` in Assistant services.

## 42. No Similar-Customer / Cross-Customer Search

`similar_customer: false`. Sector “similar” means privacy-safe cohort patterns from released artifacts — not nearest-neighbor Customer lookup. Forbidden capabilities include `cross_customer_search`, `search_all_customers`.

## 43. AI Use Policy

| Path | AI |
| --- | --- |
| Provider metric lookup | **No** (`ai_used: false`) |
| Domain lookups | **No** |
| Skill guidance (foundation) | **No** |
| Specialist analysis route | Optional; foundation path uses retrieval only |
| Intent interpretation (production) | External LLM may propose candidate; server always validates |
| Arithmetic / SQL | **Never** |

### AI use matrix

| Concern | Policy |
| --- | --- |
| LLM arithmetic | FORBIDDEN |
| LLM direct execution | FORBIDDEN (`llm_direct_execution: false` on plan) |
| Parallel assistant AI stack | FORBIDDEN |
| Auto-tune from evaluation | FORBIDDEN |

## 44. No Hallucination Policy

Claims require `AssistantSourceRef` in manifest. Unknown refs and class impersonation rejected. No numeric facts without provider/evidence backing. Abstain rather than guess.

### No-hallucination matrix

| Trap | Defense |
| --- | --- |
| Sector as spend | grounding `NON_PROVIDER_AS_METRIC` |
| Skill as customer fact | claim limitations + grounding |
| Missing data as zero | `missing_as_zero: false` |
| Model-provided customer_id | intent validator rejection |
| Invented SQL | forbidden parameters + boundary guard |

## 45. Conversation ≠ Brand Memory / Evidence / Task

`AssistantThreadState` is ephemeral structured carry-forward — not written to Brand Memory, not Evidence, not Work/Task entities. No `auto_long_term_learning`.

## 46. AssistantEvaluationHooks (Prompt 55)

`AssistantEvaluationHooks::assertCompatible()` checks privacy, grounding, abstention, specificity, current_truth, hallucinated_db_answer. Policy: `intelligence_evaluation_v1`. `auto_tune: false`.

Golden case keys: `ASSISTANT_PROVIDER_FACT_GOOGLE_ADS_SPEND`, `ASSISTANT_FINDING_LOOKUP`, `ASSISTANT_OPPORTUNITY_LOOKUP`, `ASSISTANT_WORK_LOOKUP`, `ASSISTANT_BRAND_HISTORY`, `ASSISTANT_SECTOR_CONTEXT`, `ASSISTANT_METHODOLOGY`, `ASSISTANT_AMBIGUOUS_SCOPE`, `ASSISTANT_MISSING_DATA`, `ASSISTANT_STALE_DATA`, `ASSISTANT_CROSS_BRAND_PRIVACY`, `ASSISTANT_HALLUCINATION_TRAP`.

### Evaluation matrix

| Dimension | Assistant signal |
| --- | --- |
| privacy | no contributor_id leakage |
| grounding | deterministic facts have claims |
| abstention | reason present when abstained |
| specificity | analysis reuses P54 |
| current_truth | no `memory_as_current_metric` limitation |
| auto_tune | always false |

## 47. Privacy

Sector answers exclude contributor identities and lineage. Brand Experience scoped to session Brand. Cross-brand canary strings must not appear in sector responses. `AssistantAnswerSourceManifest.sector_contributor_identities` always null.

### Privacy matrix

| Risk | Mitigation |
| --- | --- |
| Sector re-identification | released artifacts + JSON guard |
| Cross-brand experience leak | same-brand filter + test canary |
| Contributor lineage | stripped / blocked |
| Unauthorized scope | clarification, not silent bind |

## 48. Security

Scope resolver enforces authorization lists. Intent validator rejects model-invented IDs. Boundary guard prevents duplicate/forbidden architectures. No shell/MCP/public write surface in Assistant path.

## 49. Performance and Cost

Provider metric path: **0** live provider calls, **0** AI cost. Pool aggregation in PHP via existing repositories. Domain lookups capped by registry `max_cardinality` (5–100 per capability).

## 50. Tests

`tests/Feature/Assistant/FutureAssistantArchitectureTest.php` — architecture forbids, scope clarification, Google Ads deterministic fact, missing≠zero, opportunity tie clarification, sector privacy, write rejection, grounding impersonation, multi-turn revalidation, date resolver, source authority snapshot, partial coverage, intent interpreter safety, P55 hooks.

## 51. Code Map

| Component | Path |
| --- | --- |
| Orchestrator | `app/Services/Assistant/MoxdopAssistantService.php` |
| Executor | `app/Services/Assistant/AssistantCapabilityExecutor.php` |
| Planner | `app/Services/Assistant/AssistantQueryPlanner.php` |
| Scope | `app/Services/Assistant/AssistantScopeResolver.php` |
| Intent | `app/Services/Assistant/AssistantIntentValidator.php`, `AssistantIntentInterpreter.php` |
| Grounding | `app/Services/Assistant/AssistantAnswerGroundingValidator.php` |
| Guard | `app/Services/Assistant/AssistantBoundaryGuard.php` |
| Dates | `app/Services/Assistant/AssistantDateRangeResolver.php` |
| Google Ads | `app/Services/Assistant/Adapters/GoogleAdsAssistantReadAdapter.php` |
| Registries | `app/Support/Assistant/AssistantCapabilityRegistry.php`, `AssistantMetricRegistry.php`, `AssistantSourceAuthority.php` |
| Evaluation | `app/Support/Assistant/AssistantEvaluationHooks.php` |
| DTOs | `app/Support/Assistant/Dto/*` |
| Enums | `app/Enums/Assistant*.php` |

## 52. Explicit Non-Goals

- Chat UI, sidebar, floating assistant button
- Text-to-SQL, raw DB tools, schema exposure
- `DATABASE_QUERY`, `ALL_MEMORY_SEARCH`, cross-Customer search
- First-entity fallbacks for Customer / Brand / Asset
- Fine-tuning, embeddings, vector DB
- Similar-customer retrieval
- Provider or domain writes from Assistant
- `AssistantV2` parallel stack
- Conversation persistence as Brand Memory

## 53. Relationship to Prompt 50

`SpecialistAnalysis` reuses agent/skill definition signatures and retrieval assembly. No duplicate agent framework. Analysis answers label `analytical_prioritization` with `persisted_canonical_rank: false`.

## 54. Relationship to Prompt 54

`IntelligenceRetrievalService::retrieve()` supplies context pack for specialist route. Same `SkillRetrievalContract` / `SkillMemoryContract` intersection. `retrieval_manifest_fingerprint` pinned on answer.

## 55. Relationship to Prompt 55

`AssistantEvaluationHooks` provides golden-case identities and compatibility assertion without mutating production policy. Evaluation observes Assistant answers; Assistant does not auto-tune from eval results.

## 56. Mandatory Matrices Index

Matrices in this document: §7 primitive audit, §17 intent, §18 scope, §21 claim grounding, §23 fact lookup, §24 Google Ads, §25 work, §26 opportunity ranking, §27 brand history, §28 sector, §39 question→source, §38 source authority, §40 capability, §31 date, §33 missing data, §35 multi-turn, §37 write boundary, §43 AI use, §44 no-hallucination, §46 evaluation, §47 privacy, §57 reality matrix.

## 57. Reality Matrix

| Capability / feature | Status |
| --- | --- |
| `MoxdopAssistantService::ask()` | REAL |
| `AssistantCapabilityRegistry` v1 | REAL |
| `AssistantMetricRegistry` | REAL |
| `AssistantSourceAuthority` v1 | REAL |
| Google Ads spend/impressions/clicks fact lookup | REAL |
| Meta / GA4 / GSC metric registry entries | REAL (adapter execution: NOT IMPLEMENTED for non-Google paths) |
| Finding / Opportunity / Work / Evidence lookup | REAL |
| Brand Experience same-brand lookup | REAL |
| Sector released-artifact lookup | REAL |
| Skill methodology guidance | REAL |
| Specialist analysis retrieval route | REAL |
| Live LLM in intent interpreter | NOT IMPLEMENTED |
| Live LLM in specialist answer | NOT IMPLEMENTED (retrieval-only foundation) |
| Chat UI / sidebar / floating button | NOT IMPLEMENTED |
| Text-to-SQL / raw DB tools | FORBIDDEN |
| `DATABASE_QUERY` / `ALL_MEMORY_SEARCH` | FORBIDDEN |
| Cross-customer / similar-customer search | FORBIDDEN |
| First-Customer / Brand / Asset fallback | FORBIDDEN |
| Provider / domain writes | FORBIDDEN |
| Fine-tuning / embeddings / vector DB | FORBIDDEN |
| `AssistantV2` / `ChatV2` | FORBIDDEN |
| Magic opportunity score | FORBIDDEN |
| Missing data as zero | FORBIDDEN |

## 58. Forbidden Capabilities

Never register: `database_query`, `all_memory_search`, `cross_customer_search`, `run_sql`, `query_database`, `search_everything`, `search_all_customers`, `search_all_memory`, `arbitrary_eloquent`. `AssistantBoundaryGuard` throws if any appear in registry.

## 59. Assistant V2 / Future UI Surface

A future UI may call `ask()` with NL → `AssistantIntentCandidate` from an LLM, but must not bypass server validation. No `AssistantV2` service class. UI work is a separate milestone.

## 60. Definition of Done

- [x] `MoxdopAssistantService::ask()` orchestrates validated read-only pipeline
- [x] Nine bounded capabilities in `AssistantCapabilityRegistry` v1
- [x] Forbidden capabilities absent; `AssistantBoundaryGuard` passes
- [x] No Chat UI flags in architecture snapshot
- [x] Google Ads deterministic fact from persisted pool (0 AI, 0 provider calls)
- [x] Missing data abstains (not zero)
- [x] Opportunity “most important” clarifies on tie (no first-row, no magic score)
- [x] Sector uses released artifacts only; no contributor leakage
- [x] Brand Experience same-brand only
- [x] Write actions rejected
- [x] Grounding rejects source-class impersonation
- [x] Multi-turn thread revalidates authorization
- [x] `AssistantEvaluationHooks` compatible with `intelligence_evaluation_v1`
- [x] `FutureAssistantArchitectureTest` green
- [x] Architecture docs: this file + three contracts
