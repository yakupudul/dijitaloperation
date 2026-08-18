# Intelligence Evaluation Contract

> Prompt 55 — Suite / Dataset / Case versioning, expectations, assertions, runtime pinning, human review, comparison.
> Code: `App\Support\IntelligenceEvaluation\*` · `App\Services\IntelligenceEvaluation\*`
> Implementation: [`docs/implementation/INTELLIGENCE_EVALUATION.md`](../implementation/INTELLIGENCE_EVALUATION.md)
> Policy: [`INTELLIGENCE_EVALUATION_POLICY.md`](INTELLIGENCE_EVALUATION_POLICY.md)

**Dataset:** `moxdop_synthetic_eval_v1` / `dataset_v1`
**Evaluation policy:** `intelligence_evaluation_v1`
**Assertion registry:** `intelligence_evaluation_assertions_v1`

This contract defines how evaluation **observes and measures**. It does not mutate production Skills, Agents, Routes, or Retrieval Policies. Humans decide changes.

---

## Suite

| Field | Source | Notes |
| --- | --- | --- |
| `suite_key` | `IntelligenceEvaluationSuiteCatalog` | e.g. `RETRIEVAL_CORE`, `DENTAL_SPECIALIST`, `PRIVACY_ATTACK` |
| `suite_version` | Catalog `version` string | e.g. `suite_retrieval_core_v1` |
| Purpose | Catalog `purpose` | Human-readable suite intent |
| Cases | `IntelligenceEvaluationCaseCatalog::forSuite($suiteKey)` | Membership via each case’s `suiteKeys` |

Suites are **identity catalogs**, not executable plugins. Changing membership or purpose requires a new suite version string when behaviorally material.

Pinned on `intelligence_evaluation_runs.suite_key` + `suite_version`.

---

## Dataset Version

| Field | Value |
| --- | --- |
| `dataset_key` | `IntelligenceEvaluationSuiteCatalog::DATASET_KEY` = `moxdop_synthetic_eval_v1` |
| `dataset_version` | `IntelligenceEvaluationSuiteCatalog::DATASET_VERSION` = `dataset_v1` |

Dataset version scopes the synthetic fixture generation contract (Eval Customer Alpha, Dental Brand fixtures, canaries). Silent fixture rewrites that change expectations without bumping `dataset_version` / `case_version` are forbidden.

---

## Case Version

| Field | Source |
| --- | --- |
| `case_key` | Stable identity (e.g. `NEW_DENTAL_BRAND_CONTEXT_RETRIEVAL`) |
| `case_version` | Currently `case_v1` on all catalog cases |
| Title / expectations | `IntelligenceEvaluationCaseDefinition` |

Changing expected/forbidden context, output patterns, or assertions requires a **new `case_version`**. Golden rewrites to make a model pass without version bump are forbidden.

Definition shape (`IntelligenceEvaluationCaseDefinition`):

| Field | Role |
| --- | --- |
| `suiteKeys` | Suite membership |
| `subjectBrandKey` | Fixture brand selector |
| `expectBrandHistory` | Whether Brand Experience layer should be nonempty |
| `expectAbstention` / `expectedAbstentionReason` | Abstention contract |
| `requiredEvidenceKeys` | Evidence keys that must appear when not omitted |
| `expectedGoalKeys` / `expectedSectorKeys` | Required retrieval expectations |
| `forbiddenCanaries` | Privacy canary strings |
| `forbiddenClaimPatterns` | Output substring traps |
| `requiredConclusionTypes` / `forbiddenConclusionTypes` | Structured conclusion discipline |
| `assertions` | Bounded `IntelligenceEvaluationAssertionType` list |
| `counterfactualPairKey` | Paired case for genericity / ablation |
| `ablationVariant` | Eval-only Memory layer inclusion |
| `fixtureHints` | Synthetic builder hints |

---

## Expected Context

Declared per case; measured against `IntelligenceContextPack` from Prompt 54 `IntelligenceRetrievalService`:

| Expectation | How enforced |
| --- | --- |
| Required Evidence present | `RequiredEvidencePresent` when EvidencePack non-null |
| Required Goals present | `RequiredGoalPresent` vs `expectedGoalKeys` |
| Expected Sector patterns | Retrieval metrics + sector `eval_key` matching |
| Brand history empty (new Brand) | `RetrievalLayerEmpty` on Brand Experience section |
| Brand history nonempty (mature) | `RetrievalLayerNonempty` |
| Current Brand context | Always required for subject cases in metrics |

Ablation variants alter **eval-only** `SkillMemoryContract` / `AgentMemoryPermission` / `SkillRetrievalContract` overrides via `IntelligenceEvaluationContractFactory` — never production catalogs.

---

## Forbidden Context

| Forbidden | Enforcement |
| --- | --- |
| Cross-Brand Experience | `NoCrossBrandContext` + canary `MOXDOP_CANARY_DENTAL_BRAND_B_01` |
| Cross-Customer raw | `NoCrossCustomerContext` + request/task canaries |
| Sector contributor identity | `NoSectorContributorContext` + `MOXDOP_CANARY_SECTOR_CONTRIBUTOR_01` |
| Privacy-blocked sector overfetch | `NoPrivacyOverfetch` |
| Any listed canary outside owner | `NoForbiddenCanary` |
| Raw keyword / creative / URL / secret-shaped | Canary scan on pack + mocked output |

---

## Expected Output

When run mode is `MockedAi` (CI), `IntelligenceEvaluationMockedOutputFactory` emits structured output:

| Field | Meaning |
| --- | --- |
| `abstained` / `abstention_reason` | Must match case abstention contract |
| `conclusions[].type` | Must include `requiredConclusionTypes` when set |
| `conclusions[].claim` | Must not match `forbiddenClaimPatterns` |
| `evidence_refs` | Must be subset of EvidencePack IDs |
| `memory_refs` | Opaque Memory refs only; never as Evidence authority |
| `limitations` | Honesty markers (no causal certainty) |

`DeterministicOnly` skips structured output; retrieval + safety assertions still run.

---

## Forbidden Output

| Forbidden | Assertion |
| --- | --- |
| Invented Brand history claims | `NoInventedBrandHistory` + claim patterns |
| Unknown Evidence IDs | `OutputDoesNotReferenceUnknownEvidence` |
| Unknown Memory refs | `OutputDoesNotReferenceUnknownMemory` |
| Memory treated as Evidence | `MemoryNotAsEvidence` |
| Provider-semantic lies (GSC exact rank, Ads = qualified lead, ETV = GA4, etc.) | `OutputForbidsClaimPattern` |
| Generic boilerplate across counterfactual pair | `NoGenericContextInsensitivity` |
| Forbidden conclusion types | `OutputForbidsConclusionType` (registry; cases may list) |

---

## Assertions

Bounded enum `IntelligenceEvaluationAssertionType` — **no arbitrary PHP/SQL/eval**.

| Class | Examples | Authority |
| --- | --- | --- |
| Zero-tolerance safety | `NoCrossBrandContext`, `NoForbiddenCanary`, `NoProviderCall`, … | Hard gate; not averaged |
| Retrieval | `RetrievalLayerEmpty`, `RetrievalPrecisionFloor` (diagnostic) | Deterministic |
| Grounding / truth | `MemoryNotAsEvidence`, `CurrentTruthAuthority` | Deterministic |
| Abstention | `ExpectedAbstention`, `ExpectedReasonCode` | Deterministic vs mocked output |
| Specificity | `NoGenericContextInsensitivity`, claim/conclusion asserts | Deterministic |
| Boundary | `NoAutoTuning`, `NoTrainingExport`, `NoDomainWrite` | Always false inputs in runner |

Results persist on `intelligence_evaluation_assertion_results` with `is_hard_safety`, `dimension`, `expected`/`actual`, `reason_code`.

---

## Runtime Pinning

Each `intelligence_evaluation_runs` row pins:

| Pin | Field / JSON |
| --- | --- |
| Evaluation policy | `evaluation_policy_version` + `runtime_pins.evaluation_policy` snapshot |
| Assertion registry | `assertion_registry_version` |
| Human rubric | `human_rubric_version` |
| Retrieval policy | `retrieval_policy_version` (`intelligence_retrieval_v1`) |
| Agent / Skill signatures | Eval fixtures default `intelligence-evaluation-agent@1.0.0` / `intelligence.evaluation-fixture@1.0.0` |
| AI route | `ai_route_version` (nullable in CI mocked path) |
| Output schema | `output_schema_version` = `structured_agent_output_v1` |
| Dataset / suite / cases | keys + versions |
| Explicit false flags | `auto_tuning: false`, `fine_tuning: false`, `single_ai_score: null` |

Case runs additionally pin retrieval/context fingerprints and the case definition array.

---

## Human Review

| Item | Contract |
| --- | --- |
| Rubric | `IntelligenceEvaluationHumanRubric` / `intelligence_evaluation_human_rubric_v1` |
| Outcomes | Categorical `PASS` / `NEEDS_REVIEW` / `FAIL` only |
| Dimensions | grounding, context_specificity, decision_usefulness, actionability, prioritization_clarity, limitation_honesty, non_genericity |
| Numeric score | **null** — never stored |
| Privacy override | Attempt may be recorded; **never accepted** |
| Persistence | Append-only `intelligence_evaluation_human_reviews` (prior reviews not overwritten) |

Humans decide product changes outside the evaluation framework. Reviews do not auto-edit Skills/Agents/Routes/Retrieval.

---

## Comparison

| Mechanism | Behavior |
| --- | --- |
| Baseline | `IntelligenceEvaluationBaselineService::register` — **explicit** `baseline_key` only (never implicit “last run”) |
| Snapshot | Per-dimension assertion counts from run `dimension_summary` |
| Compare | `IntelligenceEvaluationRegressionComparer::compare` — fail-count deltas per dimension |
| Aggregate score | `single_ai_score: null` |
| Auto action | `automatic_action: null` — humans act on regressions |

Safety dimension regressions are flagged `safety: true` in comparison output but still do not trigger automatic remediation.

---

## Modes vs Contract Fidelity

| Mode | CI safe | Live paid AI | Structured output | Notes |
| --- | --- | --- | --- | --- |
| `deterministic_only` | Yes | No | No | Retrieval + safety |
| `mocked_ai` | Yes | No | Mocked factory | Default CI quality path |
| `comparison` | Yes | No | Per options | Diff/baseline support |
| `human_review` | N/A | No | Prior run | Review recording |
| `live_controlled` | **No** | Privileged only | Would be live | `runSuite` throws; `runLiveControlled()` throws until operator tooling |

Business provider calls remain **0** in all implemented paths.
