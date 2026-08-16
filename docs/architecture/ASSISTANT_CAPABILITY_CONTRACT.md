# Assistant Capability Contract

> Prompt 56 — per-capability contract for `AssistantCapabilityRegistry` v1 (`assistant_capability_registry_v1`).  
> Architecture: [`FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md`](FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md)  
> Implementation: `app/Support/Assistant/AssistantCapabilityRegistry.php`, `app/Services/Assistant/AssistantCapabilityExecutor.php`

Each capability is **read-only**, **registry-bound**, and executed only after server validation. Capabilities never accept raw SQL, table names, or model-invented entity IDs.

---

## Contract fields (all capabilities)

| Field | Meaning |
| --- | --- |
| **Identity** | `AssistantCapabilityId` enum value |
| **Intent compatibility** | `AssistantIntentType`(s) that route to this capability |
| **Input schema** | Required scope + candidate fields + plan parameters |
| **Authorization** | Caller `authorized*Ids` + explicit scope IDs |
| **Scope** | Customer / Brand / DigitalAsset requirements per registry |
| **Source service** | Canonical PHP service or adapter (never arbitrary Eloquent) |
| **Result schema** | `AssistantAnswer` blocks + `AssistantClaim` list |
| **Freshness** | `AssistantFreshnessState` when applicable |
| **Date semantics** | `AssistantDateRange` when `supports_period` |
| **Source authority** | `AssistantSourceClass` for claims |
| **AI needed?** | From registry `ai_required` |
| **Read-only** | Always `true` |
| **Evaluation** | Mapped via `AssistantEvaluationHooks` golden keys |

---

## PROVIDER_METRIC_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `provider_metric_lookup` |
| **Intent compatibility** | `fact_lookup` |
| **Input schema** | `metricId` ∈ `AssistantMetricRegistry`; `periodToken` ∈ `AssistantDateRangeResolver::SUPPORTED_TOKENS`; scope `customerId`, `brandId`, `digitalAssetId` (all explicit) |
| **Authorization** | Customer, Brand, DigitalAsset must be in respective authorized lists |
| **Scope** | `requires_customer`, `requires_brand`, `requires_digital_asset` = true |
| **Source service** | Provider adapters — **implemented:** `GoogleAdsAssistantReadAdapter` (`GoogleAdsSpecialistBindingResolver` + `GoogleAdsPoolReadRepository`). Pool repository `selectRaw` / `DB::` usage is **domain read infrastructure**, not an Assistant AI tool or text-to-SQL surface. |
| **Result schema** | `strategy: deterministic_fact`; block `type: fact`; claim `required_source_class: provider_data`; `AssistantProviderMetricResult` in block payload |
| **Freshness** | `fresh` / `stale` / `unknown` from latest pool row age |
| **Date semantics** | `requested_period` + `covered_period`; partial coverage when row count < requested days |
| **Source authority** | `provider_data` — only class that may satisfy provider metric claims |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_PROVIDER_FACT_GOOGLE_ADS_SPEND`, `ASSISTANT_MISSING_DATA`, `ASSISTANT_STALE_DATA` |

**Metrics (registry):**

| Metric ID | Provider | Grain | Unit |
| --- | --- | --- | --- |
| `google_ads.spend` | google_ads | account_daily | currency |
| `google_ads.impressions` | google_ads | account_daily | count |
| `google_ads.clicks` | google_ads | account_daily | count |
| `meta_ads.spend` | meta_ads | account_daily | currency |
| `ga4.sessions` | ga4 | property_daily | count |
| `gsc.clicks` | gsc | property_daily | count |

Non-Google metric IDs are registered; executor returns `unsupported_metric` until provider-specific adapters exist.

---

## EVIDENCE_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `evidence_lookup` |
| **Intent compatibility** | `domain_lookup` (`domainFilter` contains `evidence`) |
| **Input schema** | scope `customerId`, `brandId`; optional `digitalAssetId` narrows query |
| **Authorization** | Brand must be authorized |
| **Scope** | customer + brand required; digital asset optional |
| **Source service** | Canonical `Evidence` query (`is_canonical = true`), scoped to brand's digital assets |
| **Result schema** | `strategy: canonical_domain_summary`; block `domain_record`; claims per evidence row with `opaque_ref: evidence:{id}` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none (`supports_period: false`) |
| **Source authority** | `evidence` |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | (domain golden cases via architecture tests) |

`max_cardinality`: 50

---

## FINDING_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `finding_lookup` |
| **Intent compatibility** | `domain_lookup` (`domainFilter` contains `finding`) |
| **Input schema** | scope `customerId`, `brandId` |
| **Authorization** | Brand authorized |
| **Scope** | customer + brand |
| **Source service** | `FindingReadService::query(['customer_id', 'brand_id'], limit 50)` |
| **Result schema** | `canonical_domain_summary`; claims `required_source_class: finding`; `opaque_ref: finding:{id}` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none |
| **Source authority** | `finding` — current condition, not provider metric |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_FINDING_LOOKUP` |

`max_cardinality`: 50

---

## OPPORTUNITY_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `opportunity_lookup` |
| **Intent compatibility** | `domain_lookup` (`domainFilter` contains `opportunit`) |
| **Input schema** | scope `customerId`, `brandId`; optional `parameters.most_important: bool` |
| **Authorization** | Brand authorized |
| **Scope** | customer + brand |
| **Source service** | `OpportunityReadService::query(['customer_id', 'brand_id'], limit 50)` |
| **Result schema** | List claims or single winner; `most_important` tie → clarification block with `magic_score: null`, `first_row_fallback: false` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none |
| **Source authority** | `opportunity` — potential, not execution fact |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_OPPORTUNITY_LOOKUP` |

**Most-important ranking:** qualitative priority order only (`critical` > `high` > `medium` > `low`). Unique top wins; tie → `canonical_order_unavailable`.

`max_cardinality`: 50

---

## WORK_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `work_lookup` |
| **Intent compatibility** | `work_status`, `domain_lookup` (`work` / `task`) |
| **Input schema** | scope `customerId`; optional `brandId` filter |
| **Authorization** | Customer authorized |
| **Scope** | `requires_customer: true`; brand optional |
| **Source service** | `WorkReadService::workItems()` filtered by customer (+ brand) |
| **Result schema** | Work items with `status`, `qa_status`, `approval_status`; flags `task_done_equals_qa_passed: false`, `task_done_equals_approved: false` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none |
| **Source authority** | `work` — execution status |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_WORK_LOOKUP` |

`max_cardinality`: 100

---

## BRAND_EXPERIENCE_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `brand_experience_lookup` |
| **Intent compatibility** | `historical_context` |
| **Input schema** | scope `customerId`, `brandId` |
| **Authorization** | Brand authorized; must match experience rows |
| **Scope** | customer + brand (same-brand only) |
| **Source service** | `BrandExperience` + `currentRevision`, filtered `customer_id` + `brand_id`, limit 10 |
| **Result schema** | claims `block_type: historical_context`; limitations include `causality_not_established`, `not_current_metric_source`; `same_brand_only: true` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none (action/outcome timestamps on revision, not Assistant period) |
| **Source authority** | `brand_experience` — historical, not current fact |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_BRAND_HISTORY`, `ASSISTANT_CROSS_BRAND_PRIVACY` |

`max_cardinality`: 10

---

## SECTOR_PATTERN_LOOKUP

| Field | Value |
| --- | --- |
| **Identity** | `sector_pattern_lookup` |
| **Intent compatibility** | `sector_context` |
| **Input schema** | scope `customerId`, `brandId` |
| **Authorization** | Brand authorized |
| **Scope** | customer + brand (sector resolved from brand via `SectorIdentityResolver`) |
| **Source service** | `SectorMemoryReadService::listReleasedForSector()` — **Prompt 53 released artifacts only** |
| **Result schema** | claims `block_type: sector_context`; `similar_means: privacy_safe_sector_cohort`; `raw_similar_customer: false`; no contributor identities |
| **Freshness** | `not_applicable` |
| **Date semantics** | none (artifact time scope internal to sector revision) |
| **Source authority** | `sector_pattern` — observational cohort, not customer fact |
| **AI needed?** | **No** |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_SECTOR_CONTEXT`, `ASSISTANT_CROSS_BRAND_PRIVACY` |

Rejects AI-inferred sector identity. Post-serialize guard blocks `contributor_id`, `contributor_ids`, `lineage_entries` keys.

`max_cardinality`: 5

---

## SKILL_GUIDANCE

| Field | Value |
| --- | --- |
| **Identity** | `skill_guidance` |
| **Intent compatibility** | `methodology_guidance` |
| **Input schema** | none required (no customer scope) |
| **Authorization** | user id only |
| **Scope** | no customer/brand/asset requirement |
| **Source service** | Foundation path: static methodology claim (`skill:methodology:general`) — full Skill retrieval is via `specialist_analysis` |
| **Result schema** | `strategy: methodology_guidance`; block `methodology`; limitations `methodology_only`, `not_customer_fact`, `not_provider_fact` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none |
| **Source authority** | `skill_knowledge` |
| **AI needed?** | **No** (foundation) |
| **Read-only** | Yes |
| **Evaluation** | `ASSISTANT_METHODOLOGY` |

`max_cardinality`: 10

---

## SPECIALIST_ANALYSIS

| Field | Value |
| --- | --- |
| **Identity** | `specialist_analysis` |
| **Intent compatibility** | `intelligence_analysis` |
| **Input schema** | scope `customerId`, `brandId`; planner pins `agentDefinitionSignature`, `skillDefinitionSignature` |
| **Authorization** | Brand authorized |
| **Scope** | customer + brand |
| **Source service** | `IntelligenceRetrievalService::retrieve()` with `SkillRetrievalContract` + `SkillMemoryContract` (Prompt 54); agent contracts from Prompt 50 |
| **Result schema** | `strategy: specialist_structured_analysis`; block `analysis` with `retrieval_fingerprint`; `labelled_as: analytical_prioritization`; `persisted_canonical_rank: false` |
| **Freshness** | `not_applicable` |
| **Date semantics** | none |
| **Source authority** | claims require `evidence` class (retrieval pack provenance) |
| **AI needed?** | **Yes** (`ai_required: true`) — optional at runtime; foundation path uses retrieval only (`ai_used: false`) |
| **Read-only** | Yes |
| **Evaluation** | specificity dimension via `prompt_54_reuse` |

Default planner signatures: agent `website-seo-analyst@1.0.0`, skill `website.technical-seo-analysis@1.1.0`.

`max_cardinality`: 1  
`reuses_prompt_50`: true  
`reuses_prompt_54`: true  
`live_provider_calls`: false

---

## Forbidden capabilities

Must **never** be registered or executed:

| Forbidden ID | Reason |
| --- | --- |
| `database_query` | Raw DB access |
| `all_memory_search` | Unbounded memory scan |
| `cross_customer_search` | Cross-tenant violation |
| `run_sql` / `query_database` | Text-to-SQL |
| `search_everything` / `search_all_memory` | Unbounded search |
| `search_all_customers` | Similar-customer / cross-customer |
| `arbitrary_eloquent` | Bypass canonical services |

`AssistantCapabilityRegistry::forbiddenCapabilityIds()` is the canonical list. `AssistantBoundaryGuard` throws if any forbidden ID is registered.

---

## Global capability invariants

| Invariant | Value |
| --- | --- |
| `live_provider_calls` | false (all capabilities) |
| `domain_writes` | false (all capabilities) |
| Registry version | `assistant_capability_registry_v1` |
| Deterministic (except specialist) | true for lookups; specialist `deterministic: false` |
| Fine-tuning / embeddings / vector DB | false |
