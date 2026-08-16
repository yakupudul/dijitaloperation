# Intelligence Evaluation Policy

> Prompt 55 — hard safety gates, grounding, retrieval metrics, abstention, specificity, human usefulness, baseline comparison, no aggregate score, no auto-tuning.  
> Code: `App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy`  
> Implementation: [`docs/implementation/INTELLIGENCE_EVALUATION.md`](../implementation/INTELLIGENCE_EVALUATION.md)  
> Contract: [`INTELLIGENCE_EVALUATION_CONTRACT.md`](INTELLIGENCE_EVALUATION_CONTRACT.md)

**Policy ID:** `intelligence_evaluation`  
**Version:** `intelligence_evaluation_v1`  
**Assertion registry:** `intelligence_evaluation_assertions_v1`  
**Human rubric:** `intelligence_evaluation_human_rubric_v1`  
**Judge contract:** `intelligence_evaluation_judge_v1_advisory`

Evaluation **observes and measures**. It never auto-tunes Skills, Agents, Routes, Retrieval Policies, Sector Policies, or models. Humans decide changes.

---

## Snapshot Flags

`IntelligenceEvaluationPolicy::snapshot()` pins:

| Flag | Value |
| --- | --- |
| `single_ai_score` | `null` |
| `weighted_composite` | `false` |
| `auto_tuning` | `false` |
| `auto_skill_edit` / `auto_agent_edit` / `auto_retrieval_edit` | `false` |
| `auto_route_switch` / `auto_model_promotion` | `false` |
| `fine_tuning` | `false` |
| `training_export` | `false` |
| `embeddings` / `vector_db` / `similar_customer` | `false` |
| `quality_thresholds_calibrated` | `false` |
| `judge_sole_authority` | `false` |
| `judge_may_override_privacy` | `false` |
| `human_may_override_privacy` | `false` |
| `ci_live_paid_ai` | `false` |

---

## Hard Safety Gates (Zero Tolerance)

Never averaged into a quality score. Any hard-safety assertion failure → run/case `safety_gate_status = fail` / `SafetyFail`.

| Gate ID (`hardSafetyGates()`) | Typical assertion |
| --- | --- |
| `cross_customer_raw_leakage` | `NoCrossCustomerContext` |
| `cross_brand_experience_leakage` | `NoCrossBrandContext` |
| `sector_contributor_identity_leakage` | `NoSectorContributorContext` |
| `raw_confidential_keyword_leakage` | Canary / forbidden scan |
| `raw_confidential_creative_leakage` | Canary / forbidden scan |
| `raw_confidential_url_leakage` | Canary / forbidden scan |
| `unknown_evidence_references` | `OutputDoesNotReferenceUnknownEvidence` |
| `unknown_memory_references` | `OutputDoesNotReferenceUnknownMemory` |
| `credential_token_leakage` | Secret-shaped canary |
| `privacy_canary_leakage` | `NoForbiddenCanary` / `NoPrivacyOverfetch` |

Also treated as zero-tolerance safety via `IntelligenceEvaluationAssertionType::isZeroToleranceSafety()`:

- `NoProviderCall`
- `MemoryNotAsEvidence`
- (and the cross-tenant / canary / unknown-ref types above)

**Neither advisory judge nor human review may accept a privacy/safety override.**

---

## Grounding

| Rule | Policy stance |
| --- | --- |
| Evidence refs must resolve to EvidencePack | Enforced |
| Memory refs must resolve to pack Memory items | Enforced |
| Memory cannot substitute Evidence | `MemoryNotAsEvidence` |
| Invented Brand history forbidden when history empty | `NoInventedBrandHistory` |
| Provider semantics preserved | Forbidden claim patterns (GSC rank, Ads lead, ETV≠GA4, …) |

Grounding failures that are safety-class (unknown refs, memory-as-evidence) fail hard. Soft grounding quality remains a separate dimension — not folded into an AI score.

---

## Retrieval Metrics

`IntelligenceEvaluationRetrievalMetrics` records **separate** measures:

| Metric | Meaning |
| --- | --- |
| `precision` | `relevant_selected / selected` (nullable if undefined) |
| `required_context_recall` | `required_selected / required_total` |
| `optional_recall` | optional selected / optional total (nullable) |
| `irrelevant_overfetch_count` | Selected but not relevant |
| `privacy_overfetch_count` | Privacy-forbidden selections |
| `context_serialized_bytes` | Pack prompt serialization size |
| `silent_truncation_detected` | Budget silent truncate flag |
| **`composite_retrieval_score`** | **Always `null`** |

Soft floors (`RetrievalPrecisionFloor`, `RequiredContextRecallFloor`) are **diagnostic** until `QUALITY_THRESHOLDS_CALIBRATED = true`. Do not invent calibrated science.

Retrieval uses existing Prompt 54 `IntelligenceRetrievalService` + `intelligence_retrieval_v1`. Evaluation does **not** mutate production retrieval policy.

---

## Abstention

| Expectation | Assertion |
| --- | --- |
| Should abstain (missing required Evidence) | `ExpectedAbstention` + `ExpectedReasonCode` |
| Should answer (Evidence present) | `ExpectedNoAbstention` |

Abstention is a first-class dimension (`abstention`). Over-answering without Evidence is a quality/safety concern measured separately from retrieval precision.

---

## Specificity / Genericity

| Concern | Mechanism |
| --- | --- |
| Context-specific conclusions | `requiredConclusionTypes` + `OutputRequiresConclusionType` |
| Forbidden generic boilerplate | `forbiddenClaimPatterns` + `OutputForbidsClaimPattern` |
| Counterfactual pair divergence | `GENERICITY_COUNTERFACTUAL_DENTAL_ADS` ↔ `…_SEO` |
| Scaffold | `NoGenericContextInsensitivity` |

Specificity and genericity are **separate dimensions** — never collapsed with usefulness into one score.

---

## Human Usefulness

| Item | Rule |
| --- | --- |
| Rubric version | `intelligence_evaluation_human_rubric_v1` |
| Outcomes | `PASS` / `NEEDS_REVIEW` / `FAIL` only |
| Numeric usefulness score | **Forbidden** (`numeric_score: null`) |
| Dimensions | grounding, context_specificity, decision_usefulness, actionability, prioritization_clarity, limitation_honesty, non_genericity |
| Authority | Advisory to humans; cannot override hard safety |

---

## Baseline Comparison

| Rule | Detail |
| --- | --- |
| Baseline creation | Explicit `IntelligenceEvaluationBaselineService::register` only |
| Implicit “last run” baseline | **Forbidden** (`is_explicit: true`) |
| Comparison | Per-dimension fail-count deltas |
| Aggregate regression score | **null** |
| Automatic remediation | **null** — humans decide |

---

## No Aggregate Score

| Forbidden construct | Policy |
| --- | --- |
| Single AI / Brain / Intelligence score | `single_ai_score: null` |
| Weighted composite across dimensions | `weighted_composite: false` |
| Composite retrieval score | Always null in metrics |
| Judge numeric score | Always null |
| Human numeric score | Always null |

Dimensions remain independent: safety, retrieval, grounding, current_truth, abstention, specificity, genericity, usefulness, efficiency, regression.

---

## No Auto-Tuning

| Action | Allowed? |
| --- | --- |
| Auto-edit Skill / Agent / Route | **NO** |
| Auto-edit Retrieval / Sector policy | **NO** |
| Auto model promotion / route switch | **NO** |
| Fine-tuning / training JSONL export | **NO** |
| Embeddings / vector DB / similar-customer | **NO** |
| `IntelligenceEvaluationV2` | **FORBIDDEN** |

`IntelligenceEvaluationBoundaryGuard` asserts policy flags and scans evaluation services for forbidden training/embedding APIs after runs.

Ablation uses **eval-only contract overrides** — production policy untouched.

---

## CI / Live

| Mode | CI | Paid live AI |
| --- | --- | --- |
| `deterministic_only` / `mocked_ai` / `comparison` | Allowed | No |
| `live_controlled` | Forbidden | Privileged tooling only (currently throws) |

`ci_live_paid_ai: false`. Business provider calls during evaluation: **0**.
