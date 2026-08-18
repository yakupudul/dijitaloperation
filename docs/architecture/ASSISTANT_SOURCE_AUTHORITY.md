# Assistant Source Authority

> Prompt 56 — semantic authority of Assistant source classes (`assistant_source_authority_v1`).  
> Implementation: `app/Support/Assistant/AssistantSourceAuthority.php`  
> Enforcement: `app/Services/Assistant/AssistantAnswerGroundingValidator.php`  
> Architecture: [`FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md`](FUTURE_MOXDOP_ASSISTANT_ARCHITECTURE.md)

**No numeric authority score.** Authority is expressed as **semantic capability flags** per source class. Claims declare a `required_source_class`; each `source_ref` must match that class exactly (no cross-class substitution).

---

## Design principles

1. **Current fact wins over history** — Provider Data and Evidence supersede Brand Experience and Sector patterns for “what is true now.”
2. **Brand fact wins over Sector** — Same-brand observations beat privacy-qualified cohort patterns for brand-specific questions.
3. **Impersonation forbidden** — Sector, Skill, and Brand Experience cannot satisfy Provider Data, Evidence, or numeric fact claims.
4. **Exact class match** — `AssistantSourceAuthority::canSatisfy()` returns true only when claim class equals ref class.
5. **Recommendation registered, not executed** — `recommendation` exists in the enum/matrix for domain alignment; Assistant executor does not emit recommendation-backed answers in v1.

---

## Authority matrix

| Source class | Current measured fact | Current condition | Potential | Execution | Historical | Sector | Methodology | Can satisfy provider metric | Can override current evidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `provider_data` | ✓ | — | — | — | — | — | — | ✓ | — |
| `evidence` | ✓ | — | — | — | — | — | — | — | — |
| `finding` | — | ✓ | — | — | — | — | — | — | — |
| `opportunity` | — | — | ✓ | — | — | — | — | — | — |
| `recommendation` | — | — | — | — | — | — | — | — | — |
| `work` | — | — | — | ✓ | — | — | — | — | — |
| `brand_experience` | — | — | — | — | ✓ | — | — | — | — |
| `sector_pattern` | — | — | — | — | — | ✓ | — | — | — |
| `skill_knowledge` | — | — | — | — | — | — | ✓ | — | — |

Legend: ✓ = this source class may author claims of that semantic category.

---

## Per-source semantics

### Provider Data (`provider_data`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Persisted provider pool metrics (e.g. `google_ads_account_daily` sums) |
| **Authorizes** | Numeric spend/impressions/clicks; currency units |
| **Does not authorize** | Causal claims, benchmarks, cross-brand comparisons |
| **Assistant path** | `GoogleAdsAssistantReadAdapter` → `opaque_ref: google_ads:account_daily:…` |
| **Live calls** | 0 — pool only |

### Evidence (`evidence`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Canonical Evidence entities (`is_canonical`) |
| **Authorizes** | Evidence-backed factual assertions; optional numeric when evidence supports |
| **Does not authorize** | Provider metrics without provider_data ref |
| **Specialist route** | Retrieval fingerprint refs use `evidence` class for analysis readiness |

### Finding (`finding`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Persisted Finding records via `FindingReadService` |
| **Authorizes** | Current condition statements (titles/summaries) |
| **Does not authorize** | Spend metrics, execution status |

### Opportunity (`opportunity`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Open/detected Opportunity records |
| **Authorizes** | Potential / priority-qualified opportunity statements |
| **Does not authorize** | Magic ranking scores; first-row picks without canonical order |

### Recommendation (`recommendation`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Domain recommendation entity (registry alignment) |
| **Assistant v1** | Not an executable capability — no Assistant read path |
| **Future** | Would require explicit capability + read service |

### Work (`work`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Work items / tasks via `WorkReadService` |
| **Authorizes** | Execution status, QA status, approval status |
| **Does not authorize** | `done` ⇒ QA passed; `done` ⇒ approved (explicitly false in blocks) |

### Brand Experience (`brand_experience`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Confirmed same-brand experience revisions |
| **Authorizes** | Historical context, situational summaries |
| **Limitations** | `historical_context`, `causality_not_established`, `not_current_metric_source` |
| **Scope** | Same `brand_id` + `customer_id` only |
| **vs Provider Data** | Cannot answer “what is spend now” |

### Sector Pattern (`sector_pattern`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Prompt 53 **released** `SectorLearningArtifact` projections |
| **Authorizes** | Privacy-qualified cohort observations (`observational_only`) |
| **Does not authorize** | Industry benchmarks, contributor identities, similar-customer narratives |
| **“Similar”** | Means `privacy_safe_sector_cohort` — **not** nearest Customer |
| **vs Brand** | Sector loses for brand-specific current facts |

### Skill Knowledge (`skill_knowledge`)

| Aspect | Rule |
| --- | --- |
| **What it is** | Methodology / playbook knowledge |
| **Authorizes** | Investigation guidance, process statements |
| **Does not authorize** | Customer facts, provider metrics |
| **Limitations** | `methodology_only`, `not_customer_fact`, `not_provider_fact` |

---

## Precedence rules

### Current fact wins over history

When answering “what is X **now**” (metrics, canonical evidence):

| Priority | Source classes |
| --- | --- |
| 1 (highest) | `provider_data`, `evidence` |
| 2 | `finding` (condition, not metric) |
| 3 | `work` (execution) |
| 4 (lowest for current truth) | `brand_experience`, `sector_pattern`, `skill_knowledge` |

Brand Experience and Sector **must not** be used to infer current provider metrics. Grounding enforces `NON_PROVIDER_AS_METRIC`.

### Brand fact wins over Sector

For brand-scoped questions:

1. Same-brand **Provider Data** / **Evidence** / **Finding** / **Opportunity** / **Work**
2. Same-brand **Brand Experience** (historical)
3. **Sector pattern** (cohort observational) — lowest precedence for brand-specific truth

Sector answers must not include other brands’ experience text or contributor lineage.

---

## Impersonation rules (enforced)

| Attempted claim | With ref class | Result |
| --- | --- | --- |
| Provider metric | `sector_pattern` | `NON_PROVIDER_AS_METRIC` |
| Provider metric | `skill_knowledge` | `NON_PROVIDER_AS_METRIC` |
| Provider metric | `brand_experience` | `NON_PROVIDER_AS_METRIC` |
| Evidence fact | `sector_pattern` | `MEMORY_OR_SKILL_AS_EVIDENCE` |
| Evidence fact | `skill_knowledge` | `MEMORY_OR_SKILL_AS_EVIDENCE` |
| Numeric fact | `finding` | `NUMERIC_WITHOUT_FACT_SOURCE` |
| Any claim | unknown `opaque_ref` | `UNKNOWN_SOURCE_REF` |
| Any claim | no refs | `FACTUAL_CLAIM_WITHOUT_SOURCE` |
| Brand experience | wrong `brand_id` metadata | `CROSS_SCOPE_SOURCE_REF` |

`canSatisfy(claimClass, provided)` returns **true only when** `claimClass === provided`.

---

## Snapshot contract

`AssistantSourceAuthority::snapshot()`:

```json
{
  "version": "assistant_source_authority_v1",
  "numeric_authority_score": null,
  "matrix": { "... per class flags ..." },
  "current_fact_wins_over_history": true,
  "brand_fact_wins_over_sector": true
}
```

`numeric_authority_score` is **always null** — no weighted blending of sources.

---

## Manifest pinning

Domain answers include authority slice in manifest pins:

```json
{
  "source_class": "finding",
  "authority": {
    "current_measured_fact": false,
    "current_condition": true,
    "...": "..."
  }
}
```

This documents which semantic categories the answer may support — not a ranking score.

---

## Privacy and authority

| Concern | Authority rule |
| --- | --- |
| Sector contributor IDs | Never in manifest (`sector_contributor_identities: null`) |
| Cross-brand experience | Forbidden in sector answers |
| Lineage keys | Serialized answer guard rejects `contributor_id`, `lineage_entries` |
| Authorization | Source refs must be produced under validated `AssistantSessionScope` |

Privacy restrictions are **orthogonal** to semantic authority but enforced alongside grounding.

---

## Relationship to Prompt 54 retrieval authority

Prompt 54 uses related but separate retrieval section authority labels (`CURRENT_CANONICAL_EVIDENCE`, memory layers). Assistant specialist route pins `retrieval_manifest_fingerprint` with `evidence` claim class. Memory sections in retrieval packs **cannot** substitute Evidence or Provider Data in Assistant metric answers.

---

## Tests

| Test | Authority assertion |
| --- | --- |
| `test_source_authority_has_no_numeric_score` | `numeric_authority_score` is null |
| `test_grounding_rejects_sector_as_provider_fact` | Sector ref cannot ground provider metric claim |
| `test_sector_question_uses_released_artifacts_only` | No cross-brand leakage |

---

## Non-goals

- Numeric authority scores or weighted source blending
- LLM-judged source trust
- Cross-class “close enough” matching
- Using Sector as industry benchmark
- Using Brand Experience as current metric source
