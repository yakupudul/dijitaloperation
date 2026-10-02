# Assistant Answer Contract

> Prompt 56 — typed `AssistantAnswer` structure and grounding rules.  
> Implementation: `app/Support/Assistant/Dto/AssistantAnswer.php`, `AssistantClaim.php`, `AssistantAnswerSourceManifest.php`  
> Validator: `app/Services/Assistant/AssistantAnswerGroundingValidator.php`  
> Architecture: [`FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md`](FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md)

**Principle:** Answers are **structured truth** — never Markdown-only. Every factual claim pins source references. Missing data **abstains**; it is never reported as zero.

---

## Answer envelope

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `strategy` | `AssistantAnswerStrategy` | yes | How the answer was produced |
| `intent` | `AssistantIntentType` | yes | Resolved intent |
| `scope` | `AssistantSessionScope` | yes | Authorized scope snapshot |
| `claims` | `list<AssistantClaim>` | yes | Grounded statements (may be empty on clarification/abstention) |
| `blocks` | `list<array>` | yes | UI-agnostic presentation blocks |
| `source_manifest` | `AssistantAnswerSourceManifest` | yes | Pinned source refs + metadata pins |
| `requested_period` | `AssistantDateRange?` | metric answers | Operator-requested range |
| `covered_period` | `AssistantDateRange?` | metric answers | Actual data coverage |
| `freshness` | `AssistantFreshnessState` | default `not_applicable` | Pool freshness |
| `coverage` | `AssistantCoverageState` | default `not_applicable` | Period coverage |
| `limitations` | `list<string>` | no | Explicit caveats |
| `clarification_reason` | `AssistantClarificationReason?` | clarifications | Why scope/intent incomplete |
| `abstained` | `bool` | default false | True when refusing to assert facts |
| `abstention_reason` | `string?` | when abstained | Machine reason code |
| `runtime_provenance` | `array` | yes | AI calls, writes, flags |
| `answered_at` | ISO8601 string | yes | Server timestamp |
| `markdown_only_truth` | `false` | always | Serialized guard |
| `chain_of_thought` | `null` | always | Never exposed |

---

## Answer strategy (`AssistantAnswerStrategy`)

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

## Scope (`AssistantSessionScope`)

Serialized with explicit false flags:

- `first_customer_fallback: false`
- `first_brand_fallback: false`
- `first_asset_fallback: false`

Conversation text does not mutate scope. Thread state may fill null scope fields but authorization is revalidated.

---

## Claims (`AssistantClaim`)

| Field | Type | Rules |
| --- | --- | --- |
| `claim_id` | string | Stable per entity |
| `block_type` | `AssistantAnswerBlockType` | Aligns with block semantics |
| `statement` | string | Human-readable; not sole truth |
| `required_source_class` | `AssistantSourceClass` | Minimum authority class |
| `source_refs` | `list<AssistantSourceRef>` | **Required** for factual claims |
| `limitations` | `list<string>` | Per-claim caveats |
| `numeric_value` | `float?` | Only with `provider_data` or `evidence` class |
| `unit` | `string?` | Currency code or unit |
| `is_analytical` | `bool` | true for specialist interpretation |

### Claim grounding rules

1. Every claim must reference at least one `source_ref` present in `source_manifest`.
2. `source_ref.source_class` must satisfy `required_source_class` via `AssistantSourceAuthority::canSatisfy()` (exact class match only).
3. **Provider metrics:** `required_source_class` = `provider_data`; ref must be `provider_data` — Sector/Skill/BrandExperience **cannot** satisfy.
4. **Evidence-backed facts:** `evidence` class; Sector/Skill/BrandExperience **cannot** impersonate Evidence.
5. **Numeric values:** only allowed when `required_source_class` is `provider_data` or `evidence`.
6. **Brand Experience:** cross-brand metadata → `CROSS_SCOPE_SOURCE_REF` rejection.
7. If all claims rejected → abstain `unsupported_factual_claim`; partial rejection → `some_claims_rejected` limitation.

---

## Source references (`AssistantSourceRef`)

| Field | Purpose |
| --- | --- |
| `source_class` | `AssistantSourceClass` |
| `opaque_ref` | Stable server id (e.g. `google_ads:account_daily:…`, `finding:123`) |
| `fingerprint` | Optional content hash (evidence, retrieval) |
| `metadata` | Bounded non-PII hints (metric_id, qualitative_priority) |

Refs are **opaque** — full domain payloads are not embedded.

---

## Source manifest (`AssistantAnswerSourceManifest`)

| Field | Purpose |
| --- | --- |
| `source_refs` | Union of all pinned refs |
| `pins` | Extra provenance (metric_id, periods, `live_provider_calls: 0`, authority matrix slice) |
| `retrieval_manifest_fingerprint` | Prompt 54 pack fingerprint when applicable |
| `agent_skill_run_ref` | Future agent run linkage |
| `sector_contributor_identities` | **always `null`** — privacy |

---

## Period semantics

| Field | Meaning |
| --- | --- |
| `requested_period` | From `AssistantDateRangeResolver` using operator/model `periodToken` |
| `covered_period` | Min/max reporting dates actually present in pool |
| `coverage` | `complete` / `partial` / `missing` / `not_applicable` |
| `freshness` | `fresh` (≤2d) / `stale` / `unknown` / `not_applicable` |

Partial coverage: answer may include summed value with limitation `partial_coverage` — never silently imply full period.

---

## Coverage states

| State | Operator-facing meaning |
| --- | --- |
| `complete` | All days in requested range have data |
| `partial` | Some days missing — value is partial sum |
| `missing` | No data — abstain |
| `provider_limited` | Reserved for provider-specific gaps |
| `not_applicable` | Non-metric answers |

---

## Limitations

Common limitation codes:

| Code | Meaning |
| --- | --- |
| `partial_coverage` | Incomplete date range in pool |
| `stale_data` | Latest row older than fresh threshold |
| `no_rows_in_range` | Abstention path |
| `binding_not_ready` | Google Ads binding unresolved |
| `historical_context` | Brand Experience — not current truth |
| `causality_not_established` | Brand/Sector observational |
| `not_current_metric_source` | Brand Experience cannot answer metrics |
| `observational_only` | Sector cohort |
| `not_industry_benchmark` | Sector |
| `methodology_only` | Skill guidance |
| `no_canonical_most_important` | Opportunity tie |
| `read_only` | Write rejected |
| `grounding_failed` | All claims rejected |

---

## Abstention

Set `abstained: true` + `abstention_reason` when:

- No provider rows (`no_data`, `dataset_unavailable`)
- Grounding rejects all claims (`unsupported_factual_claim`)
- Stale with `abstain_if_stale`
- Sector privacy violation blocked

Abstention blocks must include `missing_as_zero: false` for metric paths.

**Never** invent numeric values on abstention.

---

## Clarification

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

Clarification blocks: `type: clarification` with `reason` and optional `candidates` (e.g. tied opportunities).

---

## Runtime provenance

Standard keys:

| Key | Expected (read paths) |
| --- | --- |
| `ai_used` | `false` for deterministic lookups |
| `llm_arithmetic` | `false` |
| `provider_calls` | `0` |
| `domain_writes` | `0` |
| `provider_writes` | `0` |
| `missing_as_zero` | `false` on unavailable metrics |
| `model_guess` | `false` |
| `prompt_50_reuse` | `true` on specialist route |
| `prompt_54_reuse` | `true` on specialist route |
| `parallel_assistant_ai` | `false` |
| `hallucinated_db_answer` | `false` (or dimension failure if true) |
| `grounding_rejected` | list of rejection reasons when partial |

---

## Block types (`AssistantAnswerBlockType`)

| Block type | Purpose | Typical payload keys |
| --- | --- | --- |
| `fact` | Provider metric | `metric` → `AssistantProviderMetricResult` array |
| `domain_record` | Canonical entity list | `source_class`, `payload` (findings, opportunities, work, evidence) |
| `analysis` | Specialist route | `retrieval_fingerprint`, `labelled_as`, `persisted_canonical_rank` |
| `historical_context` | Brand Experience claims | via claims + domain payload `experiences` |
| `sector_context` | Sector patterns | `sector_patterns`, `similar_means`, `raw_similar_customer` |
| `methodology` | Skill guidance | `methodology`, `customer_facts: false` |
| `limitation` | Abstention / unsupported | `message`, `reason`, `missing_as_zero`, `write_allowed` |
| `clarification` | Scope/intent gap | `reason`, `candidates`, `magic_score`, `first_row_fallback` |

### Block invariants

- Opportunity clarification: `magic_score: null`, `first_row_fallback: false`
- Write rejection: `write_allowed: false`
- Unavailable metric: `missing_as_zero: false`
- Specialist analysis: `persisted_canonical_rank: false`

---

## Strategy-specific examples

### Deterministic fact (Google Ads spend)

```json
{
  "strategy": "deterministic_fact",
  "coverage": "complete",
  "freshness": "fresh",
  "claims": [{
    "required_source_class": "provider_data",
    "numeric_value": 30.0,
    "unit": "EUR"
  }],
  "runtime_provenance": {
    "ai_used": false,
    "llm_arithmetic": false,
    "provider_calls": 0
  }
}
```

### Unavailable (missing data)

```json
{
  "strategy": "unavailable",
  "abstained": true,
  "abstention_reason": "no_data",
  "blocks": [{
    "type": "limitation",
    "missing_as_zero": false
  }]
}
```

### Clarification (opportunity tie)

```json
{
  "strategy": "clarification",
  "clarification_reason": "canonical_order_unavailable",
  "blocks": [{
    "type": "clarification",
    "magic_score": null,
    "first_row_fallback": false,
    "candidates": ["..."]
  }]
}
```

---

## Evaluation compatibility

`AssistantEvaluationHooks::assertCompatible()` expects:

- Privacy: no `contributor_id` / `lineage_entries` in serialized answer
- Grounding: deterministic facts have non-empty claims
- Abstention: reason present when `abstained`
- No auto-tune / fine-tuning flags

Answers must serialize via `AssistantAnswer::toArray()` for evaluation runners.

---

## Non-goals

- Markdown as authoritative output
- Chain-of-thought exposure
- Unpinned claims
- Model-computed metrics without pool refs
- Zero-as-missing default
