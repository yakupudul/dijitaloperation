# FUTURE MOXDOP ASSISTANT ARCHITECTURE

> Prompt 56 — bounded natural-language interface over canonical MoxDOP capabilities.
> Implementation: `app/Services/Assistant/`, `app/Support/Assistant/`
> Contracts: [`ASSISTANT_CAPABILITY_CONTRACT.md`](ASSISTANT_CAPABILITY_CONTRACT.md), [`ASSISTANT_ANSWER_CONTRACT.md`](ASSISTANT_ANSWER_CONTRACT.md), [`ASSISTANT_SOURCE_AUTHORITY.md`](ASSISTANT_SOURCE_AUTHORITY.md)
> Tests: `tests/Feature/Assistant/FutureAssistantArchitectureTest.php`
> Branch: `cursor/future-assistant-architecture-ea01`
> Base Prompt 55 HEAD: `a3911eac05c52c486b7e6a68b498a506ecd016cf`

**Critical product truth:** The Assistant is a **natural-language interface over bounded canonical MoxDOP capabilities** — **not** a generic database chatbot, not text-to-SQL, not arbitrary Eloquent access.

**Code entry:** `App\Services\Assistant\MoxdopAssistantService::ask()`

**Registries:** `AssistantCapabilityRegistry`, `AssistantMetricRegistry`, `AssistantSourceAuthority`

**No Chat UI** in this milestone. `ContactRoleOptions` value `assistant` is a **LEGACY contact-role label** (CRM contact job title), **not** the MoxDOP Assistant product.

---

## 1. Purpose

Provide a **read-only**, **server-validated**, **source-grounded** question-answering layer that routes operator questions to existing MoxDOP services (provider pool reads, Finding/Opportunity/Work/Evidence reads, Brand Experience, Sector Memory, Skill guidance, Specialist analysis contracts) without inventing parallel data paths, UI surfaces, or write actions.

| In scope | Out of scope |
| --- | --- |
| Typed intent → validated query plan → capability execution | Chat UI, sidebar item, floating button |
| Deterministic provider metric lookup from persisted pool | Live provider API calls during Assistant reads |
| Canonical domain lookups (Finding, Opportunity, Work, Evidence) | Task / Recommendation / Goal / campaign writes |
| Same-Brand Brand Experience history | Cross-Customer search, “similar customer” |
| Prompt 53 released Sector artifacts only | Raw DB tools, schema exposure, embeddings |
| Specialist analysis route via Prompt 50 + 54 contracts | Fine-tuning, vector DB, Assistant V2 |
| Multi-turn `AssistantThreadState` carry-forward | Conversation persisted as Brand Memory |

**Authority model:**

| Layer | Authority |
| --- | --- |
| `MASTER_SPEC` + accepted ADRs | Product truth |
| `AssistantCapabilityRegistry` v1 | Allowed capabilities |
| `AssistantMetricRegistry` | Allowed provider metrics |
| `AssistantSourceAuthority` v1 | Semantic claim classes |
| Server validation (`AssistantIntentValidator`, `AssistantScopeResolver`, `AssistantQueryPlanner`) | **Mandatory before any read** |
| Model / `AssistantIntentInterpreter` | Produces `AssistantIntentCandidate` only — never executes reads |

---

## 2. Why MoxDOP Assistant Is Not a Generic Chatbot

MoxDOP already owns canonical domain services, provider pools, privacy gates, and agent contracts. A generic DB chatbot would bypass authorization, invent metrics, leak cross-brand data, and conflate memory with current facts. The Assistant routes **only** through registries and canonical read services.

**Hard product rules:**

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

Architecture lands on `main` via feature branch `cursor/future-assistant-architecture-ea01` (or successor `cursor/*` branches). No parallel `AssistantV2` / `ChatV2` / `MoxdopBrainChatService` classes (`AssistantBoundaryGuard` enforces).

---

## 3. Existing Assistant Primitive Audit

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

### Code map

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

---

## 4. Existing Raw DB / Text-to-SQL Audit

**Assistant path:** `AssistantBoundaryGuard` scans `app/Services/Assistant/` for `information_schema`, `SHOW TABLES`, `executeSql`, `queryDatabase`, `searchEverything`, embedding/fine-tune APIs. `AssistantIntentValidator` rejects `table`, `column`, `sql` in candidate parameters. Forbidden capability IDs are never registered.

`architectureSnapshot()` exposes `text_to_sql: false`, `raw_db_tool: false`.

### Domain read infra vs Assistant AI tools

| Component | Location | Role | Assistant AI tool? |
| --- | --- | --- | --- |
| `GoogleAdsPoolReadRepository` | `app/Services/GoogleAds/GoogleAdsPoolReadRepository.php` | Persisted pool aggregation via `DB::table()`, `selectRaw()`, `DB::raw()` on `google_ads_*` tables | **No** — domain read infrastructure |
| `GoogleAdsAssistantReadAdapter` | `app/Services/Assistant/Adapters/` | Bounded metric lookup calling pool repository | Assistant adapter (registry-bound) |
| Text-to-SQL / schema exposure | — | — | **FORBIDDEN** |

`GoogleAdsPoolReadRepository` uses `selectRaw` / `DB::` for **server-owned pool aggregation** behind `GoogleAdsSpecialistBindingResolver` + binding resolution. This is **not** an AI tool, **not** model-invoked SQL, and **not** exposed to the intent interpreter. The Assistant never routes operator questions to arbitrary queries — only to registry metrics through the adapter.

**Forbidden capabilities (never register):** `database_query`, `all_memory_search`, `cross_customer_search`, `run_sql`, `query_database`, `search_everything`, `search_all_customers`, `search_all_memory`, `arbitrary_eloquent`. `AssistantBoundaryGuard` throws if any appear in registry.

---

## 5. Canonical Assistant Architecture

**Pipeline:** `ask()` → `AssistantBoundaryGuard` → `AssistantScopeResolver` → `AssistantIntentValidator` → `AssistantQueryPlanner` → `AssistantCapabilityExecutor` → `AssistantAnswerGroundingValidator` → `AssistantAnswer`.

No step allows the model to execute SQL, choose tables, or expand scope beyond caller-supplied authorization lists.

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

`MoxdopAssistantService::architectureSnapshot()` asserts `prompt_50_reuse`, `prompt_54_reuse`, `prompt_55_evaluation_hooks` = true.

---

## 6. Assistant Session Scope

`AssistantScopeResolver` builds `AssistantSessionScope` or returns `AssistantClarificationReason`. Validates Customer / Brand / DigitalAsset against authorized ID lists and ownership chain.

Serialized scope pins explicit false flags: `first_customer_fallback: false`, `first_brand_fallback: false`, `first_asset_fallback: false`.

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

---

## 7. Customer / Brand Resolution

`requireBrandIfAmbiguous()` **never** auto-picks the only Brand. `requireDigitalAssetIfAmbiguous()` **never** auto-picks the only Digital Asset even when exactly one matches.

Customer → Brand → DigitalAsset ownership chain enforced in `AssistantScopeResolver`. Unauthorized scope → clarification, not silent bind.

Named-entity resolution (future NL) must map to explicit IDs only after server validation — model-provided `customer_id`, `brand_id`, `digital_asset_id` in candidate parameters are rejected by `AssistantIntentValidator`.

---

## 8. Natural Language Interpretation

`AssistantIntentInterpreter` is an optional helper producing `AssistantIntentCandidate` from NL (`interpretDeterministic()` for tests). **Never** invents `customer_id`, `brand_id`, `table`, or `sql`. Write verbs → `UnsupportedWriteAction`. When a live LLM is wired upstream, it must emit the same candidate shape.

Production path: external LLM may propose `AssistantIntentCandidate`; server always validates before any read. Interpreter output is **never** executed directly.

---

## 9. Assistant Intent Registry

`AssistantIntentValidator` validates candidate before reads: rejects write requests, unknown capabilities, forbidden capabilities, unknown metrics, model-provided IDs in parameters. Returns clarification reasons for missing period on fact lookup.

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

---

## 10. Assistant Capability Registry

Version: `assistant_capability_registry_v1`. Nine capabilities (see [`ASSISTANT_CAPABILITY_CONTRACT.md`](ASSISTANT_CAPABILITY_CONTRACT.md)). `forbiddenCapabilityIds()` lists `database_query`, `all_memory_search`, `cross_customer_search`, `run_sql`, `arbitrary_eloquent`, etc.

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

Global invariants: `live_provider_calls: false` (all capabilities), `domain_writes: false`, fine-tuning / embeddings / vector DB: false.

---

## 11. Assistant Query Plan

`AssistantQueryPlanner` maps validated intent + scope → `AssistantQueryPlan` with `validated: true`, capabilities, answer strategy, date range, agent/skill signatures.

Unsupported period token → `date_range_required`. Fact lookup without explicit digital asset → clarification even when only one asset matches.

`UnsupportedWriteAction` or `requestsWrite` → plan with `write_blocked: true`, no capability execution.

Planner merges metric/period from `AssistantThreadState` when scope fields are null; thread cannot override explicit scope or expand authorization.

`llm_direct_execution: false` on all plans.

---

## 12. Canonical Source Routing

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

`AssistantCapabilityExecutor` executes exactly one primary capability per plan via `match`. Never arbitrary Eloquent. All domain answers pass through `AssistantAnswerGroundingValidator`.

---

## 13. Source Authority

Version: `assistant_source_authority_v1`. Semantic matrix per `AssistantSourceClass` — **no numeric authority score**. Detail: [`ASSISTANT_SOURCE_AUTHORITY.md`](ASSISTANT_SOURCE_AUTHORITY.md).

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

Current measured fact (Provider Data, Evidence) wins over historical (Brand Experience) and sector cohort observations. Brand fact wins over Sector. No cross-class impersonation.

---

## 14. Fact Lookup

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

---

## 15. Provider Metric Lookup

`AssistantMetricRegistry` — bounded provider metrics: `google_ads.spend`, `google_ads.impressions`, `google_ads.clicks`, `meta_ads.spend`, `ga4.sessions`, `gsc.clicks`. No display-label lookup; no model-invented metrics. Fact lookup requires registry membership.

Non-Google metric IDs are registered; executor returns `unsupported_metric` until provider-specific adapters exist.

### GoogleAdsAssistantReadAdapter

Wraps `GoogleAdsSpecialistBindingResolver` + `GoogleAdsPoolReadRepository`. Reads `google_ads_account_daily` only. Computes coverage (complete / partial / missing), freshness (fresh ≤2 days else stale), currency from pool. Missing rows → unavailable (not zero).

### Google Ads example matrix

| Input | Persisted data | Output |
| --- | --- | --- |
| `google_ads.spend`, `last_30_days`, scoped asset | 30 daily rows @ €1 | `value: 30.0`, `EUR`, `coverage: complete`, `ai_used: false` |
| Same, 10 rows in range | partial pool | `value: 10.0`, `coverage: partial`, limitation `partial_coverage` |
| Same, 0 rows | empty pool | `abstained`, `missing_as_zero: false`, `strategy: unavailable` |
| Binding not ready | — | `unavailable`, `binding_not_ready` |

---

## 16. Date Range Semantics

`AssistantDateRangeResolver` tokens: `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`. Server computes inclusive date bounds in scope timezone (default UTC). Model proposes `periodToken` only.

### Date matrix

| Token | Semantics (example: 2026-08-16 UTC) |
| --- | --- |
| `last_30_days` | 2026-07-18 … 2026-08-16 inclusive |
| `last_7_days` | 6 days back + today |
| `last_month` | prior calendar month |
| Unsupported token | `date_range_required` clarification |
| Model timestamp math | **FORBIDDEN** |

`requested_period` = operator/model token resolved server-side. `covered_period` = min/max reporting dates actually present in pool.

---

## 17. Currency / Timezone

Currency comes from persisted pool rows (e.g. `MAX(currency)` in `GoogleAdsPoolReadRepository`); adapter surfaces unit on claims (`EUR`, etc.). No model-invented currency.

Timezone: `ask()` accepts optional `timezone`; `AssistantDateRangeResolver` uses scope timezone with UTC default. Model does not compute calendar boundaries.

---

## 18. Freshness / Coverage

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

---

## 19. Evidence Questions

| Capability | Source service | Scope filter |
| --- | --- | --- |
| `evidence_lookup` | canonical `Evidence` query (`is_canonical`) | brand assets or digital asset |

`max_cardinality`: 50. Strategy: `canonical_domain_summary`. Claims use `required_source_class: evidence`; `opaque_ref: evidence:{id}`.

---

## 20. Finding Questions

| Capability | Source service | Scope filter |
| --- | --- | --- |
| `finding_lookup` | `FindingReadService::query()` | customer + brand |

`max_cardinality`: 50. Evaluation golden key: `ASSISTANT_FINDING_LOOKUP`.

---

## 21. Opportunity Questions

| Capability | Source service | Scope filter |
| --- | --- | --- |
| `opportunity_lookup` | `OpportunityReadService::query()` | customer + brand |

Optional `parameters.most_important: bool` — see §37. `max_cardinality`: 50. Evaluation golden key: `ASSISTANT_OPPORTUNITY_LOOKUP`.

---

## 22. Work Questions

| Capability | Source service | Scope filter |
| --- | --- | --- |
| `work_lookup` | `WorkReadService::workItems()` | customer (+ brand optional) |

### Work matrix

| Work field | Assistant semantics |
| --- | --- |
| `status: done` | does **not** imply QA passed |
| `qa_status` | surfaced; limitation `task_done_does_not_mean_qa_passed` when applicable |
| `current_approval.status` | surfaced; limitation `task_done_does_not_mean_approved` when applicable |
| `task_done_equals_qa_passed` | always **false** in blocks |
| `task_done_equals_approved` | always **false** in blocks |

`max_cardinality`: 100. Evaluation golden key: `ASSISTANT_WORK_LOOKUP`.

---

## 23. Brand Experience Questions

`BrandExperience` query: `customer_id` + `brand_id` match scope. Max 10 rows. Claims use `historical_context` block type with limitations: `historical_context`, `causality_not_established`, `not_current_metric_source`. Payload flags `same_brand_only: true`, `cross_brand: false`.

### Brand history matrix

| Rule | Enforcement |
| --- | --- |
| Same Brand only | query filter + grounding cross-scope check |
| Not provider metric source | claim class `brand_experience` |
| Not Evidence substitute | grounding rejects memory-as-evidence |
| Current fact wins | see source authority |

Evaluation golden keys: `ASSISTANT_BRAND_HISTORY`, `ASSISTANT_CROSS_BRAND_PRIVACY`.

---

## 24. Sector Memory Questions

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

`max_cardinality`: 5. Evaluation golden keys: `ASSISTANT_SECTOR_CONTEXT`, `ASSISTANT_CROSS_BRAND_PRIVACY`.

---

## 25. Skill / Methodology Questions

`skill_guidance` capability returns methodology block from `skill_knowledge` source. Limitations: `methodology_only`, `not_customer_fact`, `not_provider_fact`. No customer scope required.

Foundation path: static methodology claim (`skill:methodology:general`); full Skill retrieval is via `specialist_analysis`. Evaluation golden key: `ASSISTANT_METHODOLOGY`.

---

## 26. Specialist Analysis

`specialist_analysis` builds `SkillMemoryContract` + `SkillRetrievalContract`, calls `IntelligenceRetrievalService::retrieve()` with agent `website-seo-analyst@1.0.0` / skill `website.technical-seo-analysis@1.1.0` (planner defaults). Returns `SpecialistStructuredAnalysis` with retrieval fingerprint — **no live AI required** for architecture validation (`ai_used: false`, `prompt_50_reuse`, `prompt_54_reuse`).

Strategy: `specialist_structured_analysis`. Block `analysis` with `labelled_as: analytical_prioritization`, `persisted_canonical_rank: false`.

---

## 27. Prompt 50 Agent Reuse

`SpecialistAnalysis` capability pins agent/skill definition signatures; retrieval-ready route. No duplicate agent framework. `reuses_prompt_50: true` on capability registry entry.

Default planner signatures: agent `website-seo-analyst@1.0.0`, skill `website.technical-seo-analysis@1.1.0`.

---

## 28. Prompt 54 Retrieval Reuse

`IntelligenceRetrievalService::retrieve()` in `executeSpecialistRoute()` — no parallel RAG stack. Same `SkillRetrievalContract` / `SkillMemoryContract` intersection. `retrieval_manifest_fingerprint` pinned on answer.

Memory sections in retrieval packs **cannot** substitute Evidence or Provider Data in Assistant metric answers.

---

## 29. Prompt 55 Evaluation Reuse

`AssistantEvaluationHooks` maps answers to `intelligence_evaluation_v1` dimensions. Policy: `intelligence_evaluation_v1`. `auto_tune: false`.

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

Evaluation observes Assistant answers; Assistant does not auto-tune from eval results.

---

## 30. Answer Strategies

| Strategy | When used | Claims expected |
| --- | --- | --- |
| `deterministic_fact` | Provider metric lookup succeeded | ≥1 provider_data claim with numeric value |
| `canonical_domain_summary` | Finding / Opportunity / Work / Evidence / Brand / Sector lists | ≥0 domain claims |
| `specialist_structured_analysis` | Specialist retrieval route | analytical claim with retrieval ref |
| `methodology_guidance` | Skill guidance | methodology claim |
| `clarification` | Scope or intent incomplete | usually empty |
| `unavailable` | Missing/stale data (abstention) | empty |
| `unsupported` | Write or unknown capability | empty |

---

## 31. Typed Assistant Answer

Typed answer — never Markdown-only truth (`markdown_only_truth: false`). See [`ASSISTANT_ANSWER_CONTRACT.md`](ASSISTANT_ANSWER_CONTRACT.md).

Envelope fields: `strategy`, `intent`, `scope`, `claims`, `blocks`, `source_manifest`, `requested_period`, `covered_period`, `freshness`, `coverage`, `limitations`, `clarification_reason`, `abstained`, `abstention_reason`, `runtime_provenance`, `answered_at`. `chain_of_thought` is always `null`.

---

## 32. Claim Grounding

`AssistantAnswerGroundingValidator` rejects: claims without source refs, unknown refs, source-class impersonation (e.g. Sector as ProviderData), numeric claims without fact sources, cross-scope Brand Experience refs. Full rejection → abstention `unsupported_factual_claim`.

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

`AssistantSourceAuthority::canSatisfy()` returns true only when claim class equals ref class exactly.

---

## 33. Source Manifest

`AssistantAnswerSourceManifest` fields:

| Field | Purpose |
| --- | --- |
| `source_refs` | Union of all pinned refs |
| `pins` | metric_id, periods, `live_provider_calls: 0`, authority matrix slice |
| `retrieval_manifest_fingerprint` | Prompt 54 pack fingerprint when applicable |
| `agent_skill_run_ref` | Future agent run linkage |
| `sector_contributor_identities` | **always `null`** — privacy |

Domain answers include authority slice in manifest pins documenting semantic categories the answer may support — not a ranking score.

---

## 34. Citations / References

`AssistantSourceRef` fields: `source_class`, `opaque_ref` (e.g. `google_ads:account_daily:…`, `finding:123`), optional `fingerprint`, bounded `metadata`.

Refs are **opaque** — full domain payloads are not embedded. Every factual claim must reference at least one ref present in `source_manifest`.

---

## 35. No Hallucinated DB Answer

Claims require `AssistantSourceRef` in manifest. Unknown refs and class impersonation rejected. No numeric facts without provider/evidence backing. Abstain rather than guess. `hallucinated_db_answer: false` in runtime provenance (or P55 dimension failure if true).

### No-hallucination matrix

| Trap | Defense |
| --- | --- |
| Sector as spend | grounding `NON_PROVIDER_AS_METRIC` |
| Skill as customer fact | claim limitations + grounding |
| Missing data as zero | `missing_as_zero: false` |
| Model-provided customer_id | intent validator rejection |
| Invented SQL | forbidden parameters + boundary guard |

---

## 36. Missing / Partial / Stale Data

**Missing ≠ zero.** Unavailable facts set `abstained: true`, `strategy: unavailable`, block `missing_as_zero: false`. Grounding failure → `unsupported_factual_claim`. No model guess (`model_guess: false`).

### Missing data matrix

| Situation | Answer |
| --- | --- |
| No pool rows | abstain, not 0 |
| Binding not ready | abstain |
| Unknown metric | clarification |
| Sector artifact missing | unavailable |
| Stale + `abstain_if_stale` | abstain |
| Partial pool rows | value with `partial_coverage` limitation |

Evaluation golden keys: `ASSISTANT_MISSING_DATA`, `ASSISTANT_STALE_DATA`.

---

## 37. Most Important Opportunity Semantics

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

---

## 38. Sector Similarity Semantics

“Similar” in sector context means `privacy_safe_sector_cohort` — observational patterns from Prompt 53 **released** artifacts for the resolved sector identity. **Not** nearest-neighbor Customer lookup. **Not** industry benchmark (`industry_benchmark_claim: false`). `raw_similar_customer: false` in serialized answers.

Sector identity from `SectorIdentityResolver` only — no AI-inferred sector.

---

## 39. No Similar-Customer Raw Retrieval

`similar_customer: false` in `architectureSnapshot()`. Forbidden capabilities include `cross_customer_search`, `search_all_customers`. Sector “similar” means privacy-safe cohort patterns from released artifacts — not nearest-neighbor Customer lookup.

Cross-brand experience text must not appear in sector responses. `AssistantBoundaryGuard` enforces forbidden capability IDs.

---

## 40. Current vs Historical Truth

When answering “what is X **now**” (metrics, canonical evidence):

| Priority | Source classes |
| --- | --- |
| 1 (highest) | `provider_data`, `evidence` |
| 2 | `finding` (condition, not metric) |
| 3 | `work` (execution) |
| 4 (lowest for current truth) | `brand_experience`, `sector_pattern`, `skill_knowledge` |

Brand Experience and Sector **must not** be used to infer current provider metrics. P55 `current_truth` dimension checks no `memory_as_current_metric` limitation on metric answers.

For brand-scoped questions: same-brand Provider Data / Evidence / Finding / Opportunity / Work beat same-brand Brand Experience; Sector pattern is lowest for brand-specific truth.

---

## 41. Conversation State

`AssistantThreadState` carries `customerId`, `brandId`, `digitalAssetId`, `metricId`, `periodToken`, entity refs. Flags: `is_brand_memory: false`, `is_evidence: false`, `is_authorization: false`.

Ephemeral structured carry-forward for multi-turn — not persisted to database as Assistant conversation entity in v1.

---

## 42. Conversation vs Brand Memory

`AssistantThreadState` is ephemeral structured carry-forward — not written to Brand Memory, not Evidence, not Work/Task entities. No `auto_long_term_learning`. Conversation text does not mutate scope without server revalidation.

Brand Memory, Evidence, and Task entities remain separate canonical stores with their own authority classes and read services.

---

## 43. Multi-Turn References

### Multi-turn matrix

| Behavior | Rule |
| --- | --- |
| Carry metric/period | planner merges from thread |
| Carry scope IDs | scope resolver revalidates authorization |
| Expand access | **FORBIDDEN** |
| Persist as memory | **FORBIDDEN** |
| Override explicit scope | thread fills nulls only |

Thread refs applied only when still authorized. Evaluation golden key: `ASSISTANT_AMBIGUOUS_SCOPE`.

---

## 44. Clarification

`strategy: clarification` with `clarification_reason`:

| Reason | Trigger |
| --- | --- |
| `customer_scope_required` | Missing/unauthorized customer |
| `brand_scope_required` | Missing/ambiguous brand |
| `digital_asset_scope_required` | Missing/ambiguous asset |
| `date_range_required` | Missing/unsupported period |
| `metric_required` | Unknown metric |
| `ambiguous_entity` | Named entity not unique |
| `ambiguous_intent` | Unknown capability/metric/parameters |
| `canonical_order_unavailable` | Tied opportunity priority |
| `goal_selection_required` | Reserved |

Clarification blocks: `type: clarification` with `reason` and optional `candidates`. Opportunity tie: `magic_score: null`, `first_row_fallback: false`.

---

## 45. Abstention

Set `abstained: true` + `abstention_reason` when: no provider rows (`no_data`, `dataset_unavailable`), grounding rejects all claims (`unsupported_factual_claim`), stale with `abstain_if_stale`, sector privacy violation blocked.

Abstention blocks must include `missing_as_zero: false` for metric paths. **Never** invent numeric values on abstention.

---

## 46. Prompt Injection

Operator NL and future LLM intent output are **untrusted input**. Defenses:

- Model output limited to `AssistantIntentCandidate` shape — no executable SQL, IDs, or capability bypass fields honored without validator
- `AssistantIntentValidator` rejects `table`, `column`, `sql`, model-provided entity IDs, forbidden capabilities
- `AssistantBoundaryGuard` blocks forbidden architecture classes and raw-DB patterns in Assistant services
- Scope and authorization revalidated every `ask()` — thread state cannot expand privileges
- Write verbs routed to `UnsupportedWriteAction` without execution
- No shell/MCP/public write surface in Assistant path

Instruction-like text in questions does not override server validation or registries.

---

## 47. Read-Only Boundary

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

---

## 48. Future Command Boundary

A future UI or command layer may call `ask()` with NL → `AssistantIntentCandidate` from an LLM, but must **not** bypass server validation. No `AssistantV2` service class. No write-action command routing in v1.

Future “do this” commands (pause campaign, create task) require a **separate** write-capable workflow — not the Assistant read path. Assistant remains read-only even when UI exists.

---

## 49. Authorization

Authorization lists are caller-supplied (typically from Filament / policy layer). Assistant never escalates privileges. Customer → Brand → DigitalAsset ownership chain enforced in `AssistantScopeResolver`.

`ask()` requires `userId` plus `authorizedCustomerIds`, `authorizedBrandIds`, `authorizedDigitalAssetIds`. Unauthorized scope → clarification, not silent bind.

---

## 50. Privacy

Sector answers exclude contributor identities and lineage. Brand Experience scoped to session Brand. Cross-brand canary strings must not appear in sector responses. `AssistantAnswerSourceManifest.sector_contributor_identities` always null.

### Privacy matrix

| Risk | Mitigation |
| --- | --- |
| Sector re-identification | released artifacts + JSON guard |
| Cross-brand experience leak | same-brand filter + test canary |
| Contributor lineage | stripped / blocked |
| Unauthorized scope | clarification, not silent bind |

Post-serialize guard blocks `contributor_id`, `contributor_ids`, `lineage_entries` keys in sector answers.

---

## 51. Security

Scope resolver enforces authorization lists. Intent validator rejects model-invented IDs. Boundary guard prevents duplicate/forbidden architectures. No shell/MCP/public write surface in Assistant path.

`AssistantBoundaryGuard` called at start of every `ask()`. Fails if forbidden duplicate architecture classes exist or forbidden capabilities are registered.

---

## 52. No Fine-Tuning

`snapshot()` and `architectureSnapshot()` set `fine_tuning` = false. `AssistantBoundaryGuard` blocks `fineTune` APIs in Assistant services. P55 `auto_tune: false`. Assistant does not auto-tune from evaluation results.

---

## 53. No Vector DB V1

`snapshot()` and `architectureSnapshot()` set `embeddings`, `vector_db` = false. `AssistantBoundaryGuard` blocks `createEmbedding`, `pgvector_` in Assistant services. No parallel embedding/RAG stack — specialist route uses Prompt 54 retrieval only.

---

## 54. No Live Provider Fetch

All capabilities: `live_provider_calls: false`. Provider metric path reads persisted pool only via adapters (`GoogleAdsPoolReadRepository`). `runtime_provenance.provider_calls: 0`. `source_manifest.pins.live_provider_calls: 0`.

No provider API calls during Assistant reads — collection engine owns freshness; Assistant reports pool freshness/coverage states.

---

## 55. Performance

Provider metric path: pool aggregation in PHP via existing repositories. Domain lookups capped by registry `max_cardinality` (5–100 per capability). Single primary capability per plan — no fan-out to unbounded searches.

Deterministic lookups avoid LLM latency. Specialist foundation path uses retrieval assembly without live AI.

---

## 56. Cost Architecture

| Path | AI cost | Provider API cost |
| --- | --- | --- |
| Provider metric lookup | **0** (`ai_used: false`) | **0** |
| Domain lookups | **0** | **0** |
| Skill guidance (foundation) | **0** | **0** |
| Specialist analysis route | Optional; foundation `ai_used: false` | **0** |
| Intent interpretation (production) | External LLM may propose candidate; server validates | **0** |
| Arithmetic / SQL | **Never** | **0** |

### AI use matrix

| Concern | Policy |
| --- | --- |
| LLM arithmetic | FORBIDDEN |
| LLM direct execution | FORBIDDEN (`llm_direct_execution: false` on plan) |
| Parallel assistant AI stack | FORBIDDEN |
| Auto-tune from evaluation | FORBIDDEN |

---

## 57. UI Handoff

`architectureSnapshot()` exposes `chat_ui`, `sidebar_item`, `floating_button` = **false**. This milestone is **service-layer architecture only** — no Filament panel item, no floating widget, no conversation persistence UI.

Future UI handoff contract:

1. Resolve operator authorization lists from policy layer
2. Optional NL → `AssistantIntentInterpreter` or external LLM → `AssistantIntentCandidate`
3. Call `MoxdopAssistantService::ask()` with explicit scope IDs
4. Render `AssistantAnswer.blocks` — structured truth, not Markdown-only
5. Never bypass validation, registries, or grounding

`ContactRoleOptions` `assistant` label is CRM contact role metadata only — not Assistant product surface.

---

## 58. Tests

`tests/Feature/Assistant/FutureAssistantArchitectureTest.php` — architecture forbids, scope clarification, Google Ads deterministic fact, missing≠zero, opportunity tie clarification, sector privacy, write rejection, grounding impersonation, multi-turn revalidation, date resolver, source authority snapshot, partial coverage, intent interpreter safety, P55 hooks.

Key assertions: `test_architecture_forbids_chat_ui_and_raw_db_tools`, Google Ads spend deterministic fact, opportunity tie clarification, sector privacy canary, grounding rejects sector as provider fact, `AssistantEvaluationHooks::assertCompatible()`.

---

## 59. Reality Matrix

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
| `GoogleAdsPoolReadRepository` selectRaw/DB:: | REAL (domain infra, not AI tool) |

---

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
- [x] `GoogleAdsPoolReadRepository` documented as domain read infra, not Assistant AI tool
- [x] `ContactRoleOptions` `assistant` documented as LEGACY contact label, not Assistant product
