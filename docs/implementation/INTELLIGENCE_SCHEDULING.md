# INTELLIGENCE SCHEDULING

## STATUS: REAL (Prompt 63)

**Prompt:** 63  
**Canonical path:** `docs/implementation/INTELLIGENCE_SCHEDULING.md`  
**Contracts:** [`INTELLIGENCE_TRIGGER_CONTRACT.md`](../architecture/INTELLIGENCE_TRIGGER_CONTRACT.md) · [`ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md`](../architecture/ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md) · [`AUTOMATIC_AI_INTELLIGENCE_POLICY.md`](../architecture/AUTOMATIC_AI_INTELLIGENCE_POLICY.md) · [`INTELLIGENCE_EXECUTION_DAG.md`](../architecture/INTELLIGENCE_EXECUTION_DAG.md)  
**Depends on:** Prompt 39 Finding Evaluation · Prompt 40 Opportunity Evaluation · Prompt 50 Agent Execution · Prompt 54 Retrieval (owner tag only) · Prompt 61 Recurring Automation · Prompt 62 Collection Scheduler (upstream boundary)  
**Branch:** `cursor/intelligence-scheduling-ea01`  
**Base HEAD:** Prompt 62 `e43e2ae` (`feat: automate provider collection lifecycle`)

| Fact | Value |
| --- | --- |
| Entry event | `EvidenceCanonicalized` → `ScheduleIntelligenceFromEvidenceService` |
| CollectionRun completion as trigger | **FORBIDDEN** |
| Fingerprint builder | `EvidenceAnalyticalFingerprintBuilder` (excludes `updated_at`) |
| Tables | `intelligence_triggers`, `intelligence_execution_plans`, `automatic_intelligence_policies`, `intelligence_schedules` |
| Analyzer kinds | `FINDING_RULE`, `OPPORTUNITY_RULE`, `AI_SKILL` only |
| Phases | `PHASE_1_FINDING_RULES` → `PHASE_2_OPPORTUNITY_RULES` → `PHASE_3_AI_SKILLS` |
| Finding runtime | Prompt 39 `FindingEvaluationService` |
| Opportunity runtime | Prompt 40 `OpportunityEvaluationService` |
| AI runtime | Prompt 50 `AgentExecutionPlanner` (planner only; direct LLM from scheduler = **0**) |
| Candidate auto-promotion | **NONE** |
| `IntelligenceEngineV2` / Agent swarm / new top-level nav | **NONE** |

---

## 1. Purpose

Prompt 63 owns **when** intelligence work runs after Evidence becomes analytically usable. It records durable, idempotent triggers from Evidence analytical change (and bounded related sources), builds a finite immutable execution plan, and runs only dependency-eligible analyzers in a fixed three-phase DAG. It does not invent Findings, Opportunities, or AI candidates itself: Phase 1/2 call Prompt 39/40; Phase 3 invokes Prompt 50’s planner under human `AutomaticIntelligencePolicy` pins. Collection orchestration (Prompt 62) and provider collectors stay upstream and never become intelligence trigger sources.

```text
EvidenceCanonicalized
  → IntelligenceTrigger (idempotent trigger_key)
    → IntelligenceExecutionPlan (finite phases)
      → FindingEvaluationService
      → OpportunityEvaluationService
      → AgentExecutionPlanner (policy-gated; no direct LLM)
```

---

## 2. Existing Intelligence Trigger Audit

Pre-Prompt-63 behavior and replacement:

| Primitive / path | Pre-63 | Prompt 63 |
| --- | --- | --- |
| `EvidenceCanonicalized` listener | Optional blind `EvaluateFindingsForAssetJob` when `moxdop-finding-rules.evaluate_after_canonicalization` | `ScheduleIntelligenceFromEvidenceService::handleEvidenceCanonicalized` when scheduling enabled |
| CollectionRun completed | Not a typed intelligence trigger | Remains **FORBIDDEN** (`COLLECTION_RUN_COMPLETED` in config + absent from `IntelligenceTriggerSource`) |
| Finding evaluation | Prompt 39 `FindingEvaluationService` | **Unchanged** — scheduled with filtered `ruleIds` |
| Opportunity evaluation | Prompt 40 `OpportunityEvaluationService` | **Unchanged** — Phase 2 with filtered `ruleIds` |
| AI Agent planning | Prompt 50 `AgentExecutionPlanner` | **Unchanged** — Phase 3 calls `plan()` only |
| Recurring freshness | N/A for intelligence | Prompt 61 adapter `IntelligenceValidityRecheckScheduleAdapter` |
| Activity / Notification / Task as triggers | Not formalized | Explicitly **FORBIDDEN** |
| `IntelligenceEngineV2` | Did not exist | Still **NONE** |

### Primitive audit matrix

| Primitive | Status | Owner |
| --- | --- | --- |
| `IntelligenceTrigger` | **REAL** | Prompt 63 |
| `IntelligenceExecutionPlan` | **REAL** | Prompt 63 |
| `AutomaticIntelligencePolicy` | **REAL** | Prompt 63 |
| `IntelligenceSchedule` | **REAL** (validity recheck domain schedule) | Prompt 63 + Prompt 61 runtime |
| `EvidenceAnalyticalFingerprintBuilder` | **REAL** | Prompt 63 |
| `AnalyzerDependencyIndex` | **REAL** | Prompt 63 |
| `AnalyzerEligibilityResolver` | **REAL** | Prompt 63 |
| Finding / Opportunity / AI engines V2 | **NONE** | — |

---

## 3. Existing Rule Evaluator Audit

| Evaluator | Module ID | Called by Prompt 63 | Writes |
| --- | --- | --- | --- |
| `FindingEvaluationService` | `finding-evaluation` | Phase 1 via `evaluateAsset($asset, $actor, $findingRuleIds, null)` | Findings only (guards Recommendations/Tasks/Opportunities) |
| `OpportunityEvaluationService` | `opportunity-evaluation` | Phase 2 via `evaluateAsset($asset, $actor, $oppRuleIds, null)` | Opportunities only when rule activation is true; no Recommendation/Task auto-create |

Both services read Canonical Evidence through `CanonicalEvidenceReadService` and do not call providers. Prompt 63 passes **filtered rule ID lists** from the dependency index; it does not reimplement condition evaluators.

---

## 4. Existing AI Runtime Audit

| Component | Role in Prompt 63 |
| --- | --- |
| `AgentExecutionPlanner` | Sole AI planning entry from `ExecuteIntelligencePlanService::executeAiAnalyzer` |
| `AgentProfileRegistry` | Resolves pinned `agent_slug`; version must equal policy `agent_version` |
| Direct LLM / inference from scheduler | **0** (`direct_llm_calls` recorded as 0) |
| Prompt 54 retrieval | Tagged `retrieval_owner => Prompt54`; scheduler does not call retrieval services |
| Agent-to-agent fan-out | **NONE** (`agent_to_agent` = 0 / false) |

Scheduler outcome for eligible AI is `planned_for_prompt50`. Inference/runtime remains Prompt 50–owned and is not auto-fired by Prompt 63 without an explicit operator/runtime path outside this scheduler.

---

## 5. Collection → Evidence → Intelligence Boundary

| Layer | May write | May trigger intelligence |
| --- | --- | --- |
| Prompt 62 Collection lifecycle / collectors | Pool / CollectionRun | **NO** |
| Evidence canonicalization pipeline | Canonical Evidence rows + `EvidenceCanonicalized` | **YES** (via listener) |
| Prompt 63 scheduling | Triggers + plans + policy counters | Orchestrates only |
| Prompt 39 / 40 | Findings / Opportunities | Downstream of plan |

Collection handoff is **event-mediated after Evidence identity is stable**, not after CollectionRun status flips.

### Collection handoff matrix

| Handoff | Status |
| --- | --- |
| `CollectionRun` completed → intelligence | **FORBIDDEN** |
| Canonical Evidence written → `EvidenceCanonicalized` | **REAL** |
| Listener → `ScheduleIntelligenceFromEvidenceService` | **REAL** |
| Scheduler → provider HTTP | **NONE** (tests assert `Http::assertNothingSent`) |

---

## 6. Why CollectionRun Completion Is Not an Intelligence Trigger

A CollectionRun can finish with partial datasets, integrity-blocked rows, or no new canonical analytical identity. Intelligence must react to **effective Evidence analytical state** (definition fingerprints, freshness, integrity, eligibility), not orchestrator success. Config lists `COLLECTION_RUN_COMPLETED` under `forbidden_trigger_sources`; `IntelligenceTriggerSource` has no such case. Metadata on Evidence-driven triggers stores `collection_run_direct_trigger => false`.

---

## 7. Effective Evidence Analytical State

Effective state for an asset is the set of **canonical** Evidence rows (`is_canonical = true`) loaded by `IntelligenceTriggerService`, ordered by id. Non-canonical rows are ignored by `EvidenceAnalyticalFingerprintBuilder::forEvidenceSet`. Empty canonical sets may still record a trigger for Evidence analytical change so blocked/empty states remain deterministically reevaluable.

---

## 8. Evidence Analytical Fingerprint

`EvidenceAnalyticalFingerprintBuilder` builds:

- Per-row `analytical_fingerprint` = `eaf:` + SHA-256 of an observation slice (definition_id, evidence_fingerprint, eligibility_status, observed_at, fresh_until, derived/AI flags, goal/offering ids, metrics_hash, period, integrity, freshness, completeness, timezone, currency, attribution).
- Set fingerprint = `easet:` + SHA-256 of sorted `{definition_id, evidence_fingerprint, analytical_fingerprint}` tuples.

**Excluded:** `updated_at`, queue IDs, browser session, Activity IDs, full noisy payload dumps when a fingerprint exists. Tests prove bumping `updated_at` alone does not change the analytical fingerprint.

---

## 9. Evidence Change Events

Primary product event: `App\Events\EvidenceCanonicalized` (dispatched after commit from `CanonicalEvidencePipeline`). Listener `QueueFindingEvaluationAfterEvidenceCanonicalized` either:

1. Scheduling enabled → `handleEvidenceCanonicalized($asset, $run)`, or  
2. Scheduling disabled → legacy optional `EvaluateFindingsForAssetJob` if finding-rules config allows.

Reason code stored: `EVIDENCE_CANONICALIZED` with source `EVIDENCE_ANALYTICAL_STATE_CHANGED`.

---

## 10. Time-Based Freshness Changes

Without new Evidence writes, freshness can still expire (`fresh_until`). Prompt 63 uses Prompt 61 via `IntelligenceValidityRecheckScheduleAdapter` on `IntelligenceSchedule` rows (Active status, asset-scoped). Execute path calls `handleValidityRecheck` → source `SCHEDULED_EVIDENCE_VALIDITY_RECHECK`, metadata `provider_calls => 0`. Allowed frequencies: Hourly, Daily. Does not call providers or full-scan every Agent.

---

## 11. Intelligence Trigger

Model `IntelligenceTrigger` (table `intelligence_triggers`) is durable orchestration identity, **not** business truth. Fields include customer/brand/asset scope, `source_kind`, `source_identity`, `source_revision_fingerprint`, unique `trigger_key`, `reason`, `status`, `changed_evidence_refs`, `metadata`, actor timestamps.

### Trigger matrix

| Source (`IntelligenceTriggerSource`) | Entry | Status |
| --- | --- | --- |
| `EVIDENCE_ANALYTICAL_STATE_CHANGED` | Canonicalization / analytical change | **REAL** |
| `FINDING_STATE_CHANGED` | Material Finding create/reopen/resolve lineage from Phase 1 | **REAL** (lineage; does not mutate running plan) |
| `SCHEDULED_EVIDENCE_VALIDITY_RECHECK` | Prompt 61 validity adapter | **REAL** |
| `MANUAL_REEVALUATION` | `handleManualReevaluation` | **REAL** |
| `ACTIVITY` / `NOTIFICATION` / `TASK` / `AGENT_RESULT` / `AI_CANDIDATE` / `COLLECTION_RUN_COMPLETED` / `RECOMMENDATION` / `APPROVAL` / `QA` | — | **FORBIDDEN** |

---

## 12. Trigger Idempotency

Evidence-driven `trigger_key` = `intel:{source}:asset:{assetId}:{evidenceSetFingerprint}`. Finding-state key = `intel:FINDING_STATE_CHANGED:asset:{id}:{findingRuleStableId}:{findingStateFingerprint}`. Re-recording the same key returns the existing row inside a DB transaction. Unchanged Evidence retries therefore do not create duplicate work identity (covered by feature tests).

---

## 13. Change Sets

There is no separate ChangeSet entity. The trigger’s `changed_evidence_refs` JSON holds the fingerprint builder’s `refs` (definition_id, evidence_id, fingerprints, observation). Metadata also stores `definition_ids` and `evidence_set_fingerprint`. Planners derive analyzer candidates from those definition IDs (and finding stable IDs for Finding-state triggers).

---

## 14. Coalescing

When creating a new plan, `IntelligenceSchedulingPlanner` marks prior **PLANNED** plans for the same digital asset as `SUPERSEDED`, stamps `superseded_by_fingerprint`, and marks their triggers `SUPERSEDED`. Enum statuses also include `COALESCED` for terminal classification. Same `plan_fingerprint` reuses the existing plan row (idempotent plan identity).

### Coalescing matrix

| Situation | Behavior |
| --- | --- |
| Same trigger_key | Reuse trigger |
| Same plan_fingerprint | Reuse plan |
| Newer plan while prior PLANNED on asset | Prior → `SUPERSEDED` |
| Terminal plan re-execute | `ExecuteIntelligencePlanService` no-ops (`isTerminal`) |

---

## 15. Analyzer Identity

| Kind | `analyzer_id` | Extra identity |
| --- | --- | --- |
| `FINDING_RULE` | Finding rule `id` | `stable_id`, `version`, `evidence_definition_ids` |
| `OPPORTUNITY_RULE` | Opportunity rule `id` | `stable_id`, `version`, evidence + `finding_rule_stable_ids` |
| `AI_SKILL` | Skill `signature()` | `skill_signature`, `version`, required/optional evidence lists; policy pins agent/route |

No `GENERAL`, `SWARM`, or `OMNISCIENT` kinds (`AnalyzerKind::tryFrom` returns null).

---

## 16. Finding Rule Analyzer

Selected by `AnalyzerDependencyIndex::findingRulesForEvidenceDefinitions` when enabled Finding rules intersect changed Evidence definition IDs. Eligibility for scheduling is `DETERMINISTIC_RULE_ELIGIBLE`. Execution uses Prompt 39 with the selected rule IDs only.

---

## 17. Opportunity Rule Analyzer

Selected when Evidence definition IDs **or** Finding rule stable IDs intersect rule dependencies (`opportunityRulesForChanges`). Finding-state triggers plan **only** Opportunity candidates linked to the changed finding rule. Execution uses Prompt 40 with selected rule IDs.

---

## 18. AI Skill Analyzer

Included only when an **Active** `AutomaticIntelligencePolicy` matches brand/asset scope, allowed trigger kinds, skill signature, and required/optional evidence change flags. Planner enforces global `max_ai_fanout_per_plan` and per-policy `max_fanout_per_plan`. Eligibility may block on missing/stale/integrity Evidence, budget, or disabled automation.

---

## 19. Analyzer Dependency Index

`AnalyzerDependencyIndex` is deterministic: set intersection over registries (`FindingRuleRegistry`, `OpportunityRuleRegistry`, optional `SkillRegistry`). No fuzzy matching, embeddings, or LLM classification.

### Dependency matrix

| Analyzer | Depends on |
| --- | --- |
| Finding Rule | Evidence definition IDs on the rule |
| Opportunity Rule | Evidence definition IDs and/or Finding rule stable IDs |
| AI Skill | Skill required and/or optional Evidence lists + matching policy |

---

## 20. Required vs Optional Evidence

For skills:

- `skillsForRequiredEvidenceChanges` — required Evidence intersection with changed definitions.
- `skillsForOptionalEvidenceChanges` — optional intersection **and** no required intersection (optional-only wake).

Policies gate each path via `trigger_on_required_evidence_change` (default true) and `trigger_on_optional_evidence_change` (default false). Eligibility requires all **required** Evidence present, not stale, and not integrity-blocked.

### Evidence eligibility matrix

| Check | Disposition |
| --- | --- |
| Required definition missing | `REQUIRED_EVIDENCE_MISSING` |
| `fresh_until` in past | `EVIDENCE_STALE` |
| Integrity blocked / failed | `INTEGRITY_BLOCKED` |
| All required OK + budget OK | `ELIGIBLE` (`AI_SKILL_ELIGIBLE`) |

---

## 21. Scope Applicability

Policies may be brand-wide (`digital_asset_id` null) or asset-scoped. Resolver blocks `ASSET_SCOPE_MISMATCH` / `BRAND_SCOPE_MISMATCH` with `SCOPE_NOT_APPLICABLE`. Triggers require the asset’s Brand (null brand → no trigger). Policy create asserts authorized customer/brand ID lists when provided.

---

## 22. Analyzer Eligibility

`AnalyzerEligibilityResolver` returns categorical dispositions only — **no numeric scores** (tests reject `SCORE_42`). Finding/Opportunity rules are eligible when selected by the dependency index. AI skills require active policy, allowed trigger kind, scope match, required Evidence health, and budget window.

### Analyzer eligibility matrix (dispositions used)

| Disposition | Typical reason |
| --- | --- |
| `ELIGIBLE` | Deterministic rule or AI skill eligible |
| `AUTOMATION_DISABLED` | Policy inactive / trigger kind not allowed |
| `AI_BUDGET_BLOCKED` | Min interval or window max runs |
| `REQUIRED_EVIDENCE_MISSING` / `EVIDENCE_STALE` / `INTEGRITY_BLOCKED` | Evidence gates |
| `SCOPE_NOT_APPLICABLE` | Brand/asset/unknown kind |

Other enum values (`NO_RELEVANT_DEPENDENCY`, `COVERAGE_INSUFFICIENT`, `SERVICE_SCOPE_NOT_APPLICABLE`, `ACTIVE_EQUIVALENT_EXECUTION`, `UNCHANGED_INPUT`) exist for contract completeness; AI same-input skip is handled at execute time as `deduped` / `SAME_AUTOMATIC_INPUT`.

---

## 23. Intelligence Execution Plan

`IntelligenceExecutionPlan` stores immutable `plan_fingerprint` = `iplan:` + SHA-256 of `{trigger_id, asset_id, source_revision, phases}`. JSON `analyzers` includes `phases`, `skipped`, `finite_before_ai => true`, `swarm => false`. Statuses: `PLANNED`, `RUNNING`, `COMPLETED`, `FAILED`, `BLOCKED`, `COALESCED`, `SUPERSEDED`, `NO_RELEVANT_ANALYZER`. Empty eligible set → `NO_RELEVANT_ANALYZER` without dispatch execute work beyond planning.

---

## 24. Execution Phases

Fixed order in `ExecuteIntelligencePlanService`:

### Phase matrix

| Phase enum | Runtime | Writes from Prompt 63 itself |
| --- | --- | --- |
| `PHASE_1_FINDING_RULES` | Prompt 39 | Findings via Prompt 39 |
| `PHASE_2_OPPORTUNITY_RULES` | Prompt 40 | Opportunities via Prompt 40 |
| `PHASE_3_AI_SKILLS` | Prompt 50 planner | **No** Recommendations/Tasks; no auto candidates |

Phase results and counters are persisted on the plan. Prompt 63 throws if Recommendation or Task counts change during execute.

---

## 25. Finding Reevaluation

Phase 1 calls `FindingEvaluationService::evaluateAsset` with the plan’s Finding analyzer IDs. Material create/reopen/resolve counts cause `recordFindingStateChanged` lineage triggers for dependent Opportunity planning **without mutating the immutable running plan** (Phase 2 already includes Evidence/Finding-dependent Opportunity rules selected at plan time).

---

## 26. Finding → Opportunity Dependency

Opportunity rules declare `findingRuleStableIds`. Dependency index wakes Opportunity analyzers on Evidence change and/or Finding stable ID change. Finding-state-only triggers skip Finding phase selection and plan Opportunity candidates from finding dependency links.

### Finding → Opportunity dependency matrix

| Input change | Finding analyzers | Opportunity analyzers |
| --- | --- | --- |
| Evidence definitions | Matching Finding rules | Matching Evidence and/or Finding-linked Opportunity rules |
| Finding state lineage | Not re-planned into same plan | Candidates via `opportunityRulesForChanges([], [stableId])` for future planning |

---

## 27. Opportunity Reevaluation

Phase 2 runs `OpportunityEvaluationService::evaluateAsset` with planned Opportunity rule IDs. No Finding is auto-promoted; Opportunity creation remains rule-activation gated inside Prompt 40.

---

## 28. Automatic Intelligence Policy

Human-controlled CRUD via `AutomaticIntelligencePolicyService`. Creates Active policies with pinned agent/skill/route versions, allowed trigger kinds, Evidence change flags, and budget fields. AI/Assistant cannot create or enable policies (service is human/API oriented; no AI writer). Statuses: `active`, `paused`, `disabled`, `archived`. Methods: `create`, `disable`, `pause`, `resume`.

### AI policy matrix

| Concern | Contract |
| --- | --- |
| Creator | Human (`created_by`); not AI |
| Versions | Exact pins; `latest` / `*` / `auto` rejected |
| Default allowed trigger | `EVIDENCE_ANALYTICAL_STATE_CHANGED` |
| Fan-out | `max_fanout_per_plan` (default 3) + global config cap |
| Unique scope | Unique on brand + asset + skill_signature + agent_slug + agent_version |

---

## 29. Agent Version Pinning

Policy stores `agent_slug` + `agent_version`. At execute time, registry profile version must equal the pin or outcome is `blocked` / `AGENT_VERSION_PIN_MISMATCH`. Silent “latest” tokens are rejected at policy create (`forbid_latest_version_tokens` config true).

---

## 30. Skill Version Pinning

Policy stores `skill_signature` + `skill_version`. Planner only attaches skill candidates whose signature matches the policy. Execute checks the pin against Prompt 50 plan `eligibleSkills` (exact or prefix match helper).

---

## 31. Route Version Pinning

Policy stores `route_key` + `route_signature`. Both required at create; `route_signature` rejects latest-like tokens. Pins are recorded on AI analyzer entries and planner outcomes; route resolution remains Prompt 50–adjacent (planner notes route resolution out of scope in Prompt 50, but Prompt 63 still persists the human pin).

---

## 32. Human Approval of AI Automation

Automatic AI phase is empty unless an Active policy exists for the brand/asset. Disabling a policy yields zero AI analyzers on subsequent plans (feature-tested). Creating a policy requires explicit version fields and authorized customer/brand lists when supplied.

---

## 33. Automatic AI Budget / Throttle

Eligibility `withinBudget` enforces `min_interval_minutes` since `last_automatic_run_at` and `runs_in_window` vs `max_automatic_runs_per_window` inside `window_minutes`. Successful planner path calls `consumeBudget` to advance window counters. Fan-out caps skip further AI with `AI_BUDGET_BLOCKED` / `MAX_FANOUT`.

---

## 34. AI Execution

For each Phase 3 analyzer: validate Active policy → same-input dedup → pin-check Agent profile → `AgentExecutionPlanner::plan` → skill eligibility → consume budget → return `planned_for_prompt50` with `direct_llm_calls = 0`, `candidate_auto_promotion = false`, `agent_to_agent = false`. Failures/blocks are recorded as outcomes without throwing the whole plan for planner unavailability (warning log + blocked result).

---

## 35. Prompt50 Integration

Prompt 63’s only AI entry is `AgentExecutionPlanner::plan($profile, $availableEvidence)`. Available Evidence = canonical definition IDs on the asset. No `AgentRuntimeV2`. Inference is not auto-dispatched by this scheduler.

---

## 36. Prompt54 Retrieval Integration

Execute AI results label `retrieval_owner => 'Prompt54'`. Prompt 63 does **not** invoke `IntelligenceRetrievalService` or mutate retrieval policies. Retrieval remains Prompt 54’s concern when Prompt 50 runtime eventually runs.

---

## 37. AI Candidates

Prompt 63 does not create Recommendation/Task/candidate domain rows. Phase 3 records planner eligibility only. Candidate materialization stays outside this prompt’s write set.

---

## 38. No Automatic Candidate Promotion

Hard guards: Recommendation and Task counts must remain unchanged through plan execute; phase results set `candidate_auto_promotion` to 0/false. Opportunity creation is Prompt 40 rule-gated, not “AI candidate promoted.”

---

## 39. Agent Swarm Prevention

Plans stamp `swarm => false` and `finite_before_ai => true`. Analyzer kinds exclude swarm/general. Config forbids recursive edges: AI result → Agent, AI candidate → Agent, Opportunity → Finding, Task/Activity/Notification → Intelligence.

### Swarm prevention matrix

| Pattern | Status |
| --- | --- |
| Agent swarm / omniscient analyzer | **FORBIDDEN / NONE** |
| AI → another Agent from scheduler | **NONE** (`agent_to_agent = 0`) |
| LLM fan-out before finite plan | **FORBIDDEN** (phases fixed at plan time) |
| `IntelligenceEngineV2` | **NONE** |

---

## 40. Server-Side Finite Execution Graph

The execution graph is the three-phase list inside `analyzers.phases`, computed entirely server-side before any AI planner call. No client-built workflow graph, morph executor, or dynamic edge insertion mid-run. Finding-state lineage triggers do not rewrite the running plan.

See [`INTELLIGENCE_EXECUTION_DAG.md`](../architecture/INTELLIGENCE_EXECUTION_DAG.md).

---

## 41. Same-Input Dedup

AI execute builds `inputFingerprint` over asset + agent@version + skill + route + policy fingerprint + analyzer_id. If a prior **COMPLETED** plan stored the same fingerprint under `metadata.ai_input_fingerprints.{policyId}`, outcome is `deduped` / `SAME_AUTOMATIC_INPUT` with zero AI calls.

### Same-input matrix

| Layer | Key |
| --- | --- |
| Trigger | `trigger_key` including Evidence set fingerprint |
| Plan | `plan_fingerprint` |
| Automatic AI paid path | `inputFingerprint` vs prior completed metadata |

---

## 42. Freshness Reevaluation

Owned by `IntelligenceSchedule` + `IntelligenceValidityRecheckScheduleAdapter` (Prompt 61 kind `intelligence_validity_recheck`). Recheck re-reads current canonical Evidence analytical state; may no-op via trigger idempotency if fingerprint unchanged. Domain run type: `intelligence_execution_plan`.

---

## 43. Rule Version Changes

Prompt 63 does **not** auto-emit triggers when Finding/Opportunity rule registry versions change. New versions apply on the next Evidence/manual/validity-driven plan. No dedicated `RULE_VERSION_CHANGED` source.

### Version change matrix

| Change | Auto-trigger in Prompt 63 |
| --- | --- |
| Finding/Opportunity rule version | **NONE** (next eligible plan uses registry) |
| Skill version in registry | **NONE** unless policy pin matches and Evidence wakes skill |
| Agent/Route registry drift vs pin | Execute **blocks** (`AGENT_VERSION_PIN_MISMATCH`) |
| Policy pin update | Requires human policy create/update path |

---

## 44. Skill Version Changes

Policies pin `skill_version` / signature. Registry drift that breaks Prompt 50 eligibility blocks AI outcome (`SKILL_NOT_ELIGIBLE_IN_PROMPT50_PLAN`). No automatic replan solely from skill catalog edits.

---

## 45. Agent / Route Version Changes

Agent pin mismatch blocks Phase 3 for that analyzer. Route pin is persisted and required; silent latest tokens forbidden. No Agent swarm restart on version bump.

---

## 46. Manual Reevaluation

`ScheduleIntelligenceFromEvidenceService::handleManualReevaluation` records `MANUAL_REEVALUATION` and plans/dispatches (default `sync: true`). Actor user id stored in metadata. Service API exists; no new Filament top-level nav ships in Prompt 63.

---

## 47. Late-Data Repair Reanalysis

Prompt 62 Late Data Repair may rewrite pool data; when Evidence is re-canonicalized, `EvidenceCanonicalized` wakes Prompt 63 normally. There is **no** `LATE_DATA_REPAIR` intelligence trigger source. Reanalysis is Evidence-analytical, not Collection lifecycle intent.

### Late repair matrix

| Path | Status |
| --- | --- |
| P62 late repair → collectors | Prompt 62 |
| Re-canonicalize → intelligence | **REAL** via Evidence event |
| Direct late-repair → intelligence trigger | **NONE** |

---

## 48. Backpressure

Mechanisms present: async job queue (`ExecuteIntelligencePlanJob` on configured queue), AI fan-out caps, AI budget windows, plan supersede of pending work, trigger/plan idempotency. No separate backpressure broker or priority queue ranks.

---

## 49. Priority

No priority column on triggers or plans. Ordering is phase-fixed (Findings → Opportunities → AI) and planner iteration order (policies by id). Collection lifecycle priority concepts do not apply here.

---

## 50. Failure / Retry

Plan status enum includes `FAILED` / `BLOCKED`. Job skips terminal plans. Safe retries rely on idempotent `trigger_key` / `plan_fingerprint` and AI same-input dedup. Prompt 50 planner exceptions become blocked AI outcomes without asserting domain Recommendation writes. No dedicated exponential retry policy class in Prompt 63.

---

## 51. Cancellation

Running-plan cancel API is **not** implemented in Prompt 63 services. Effective cancellation of pending work is **supersede** when a newer plan is planned for the same asset. Prompt 61 occurrence cancellation applies to recurring validity ticks, not to mutating Completed plans.

---

## 52. Human Control

### Human control matrix

| Control | Status |
| --- | --- |
| Create/disable/pause/resume AI policy | **REAL** (`AutomaticIntelligencePolicyService`) |
| Manual reevaluation API | **REAL** |
| Validity schedules (`IntelligenceSchedule`) | **REAL** (table + adapter; operator UI not required for REAL) |
| AI create/enable policies | **FORBIDDEN** |
| New top-level Filament Intelligence Scheduling nav | **NONE** |

---

## 53. Intelligence History

Durable history is the `intelligence_triggers` and `intelligence_execution_plans` tables (fingerprints, phases, phase_results, metadata counters). Triggers are orchestration history, not Findings/Opportunities themselves. No separate Intelligence History product module ships here.

---

## 54. Activity / Notification Boundary

Activity, Notification, and Task are **forbidden trigger sources**. Prompt 63 does not treat them as wake signals and must not create Recommendations/Tasks during execute. Logging uses structured `Log::info` / `warning` channels (`intelligence.trigger.planned`, `intelligence.plan.completed`, `intelligence.ai.prompt50_planned`).

---

## 55. Provider Semantic Guards

Scheduler path performs **zero** provider HTTP (feature test). Validity recheck metadata asserts `provider_calls => 0`. Finding/Opportunity services also document zero provider calls. Intelligence scheduling never starts collectors.

---

## 56. Memory / Retrieval Privacy

Prompt 63 does not read Brand Memory, Sector Memory, or Prompt 54 packs. It only schedules. Privacy boundaries of Prompt 51/53/54 remain unchanged and apply when Agents eventually retrieve. No cross-brand Evidence merge in the scheduler.

### Privacy matrix

| Concern | Prompt 63 |
| --- | --- |
| Brand Memory access | **NONE** |
| Sector Learning write/read | **NONE** |
| Retrieval pack build | **NONE** (owner tag only) |
| Customer/Brand columns on rows | Scoped FKs on triggers/plans/policies |

---

## 57. Sector Learning Boundary

No Sector Learning artifact creation, privacy gate calls, or sector aggregate consumption in Intelligence Scheduling services.

---

## 58. Brand Experience Boundary

No Brand Experience record writes or reads in Prompt 63. Finding/Opportunity evaluation may reference goals/offerings inside Prompt 39/40 only as those prompts already allow.

---

## 59. Security

Exact version pins prevent silent latest Agent/Skill/Route drift. Forbidden trigger list reduces recursive automation. Authorization lists on policy create. Plans are server-authored and fingerprint-unique. No client-supplied analyzer graph execution.

---

## 60. Authorization

`AutomaticIntelligencePolicyService::assertAuthorized` validates optional `authorizedCustomerIds` / `authorizedBrandIds`. Triggers inherit customer_id/brand_id from the asset’s Brand. No SaaS multi-tenant workspace switcher; internal Moximu `web` guard model unchanged.

---

## 61. Tenancy

All four tables carry `customer_id` + `brand_id` FKs (`restrictOnDelete` on customer/brand). Asset FKs null-on-delete where applicable. Unique policy scope includes brand and optional asset.

---

## 62. Performance

Dependency index avoids evaluate-all-rules blind scans. Coalescing supersedes pending plans per asset. Async dispatch configurable (`dispatch_async`). AI fan-out and budget caps bound paid planner pressure. Fingerprints avoid `updated_at` noise retriggers.

---

## 63. Demo Retirement

Feature tests assert absence of `IntelligenceEngineV2`, `FindingEngineV2`, `OpportunityEngineV2`, `AgentRuntimeV2`, and `SchedulerV2`. No demo swarm UI. Legacy blind find-all job remains only when scheduling is **disabled**.

---

## 64. Tests

Canonical suite: `tests/Feature/IntelligenceScheduling/IntelligenceSchedulingTest.php`.

Covered: fingerprint ignores `updated_at`; trigger idempotency; dependency selection; Evidence schedules affected Findings only; unchanged retry identity; AI empty without policy; exact version pins / reject latest; disable policy blocks AI; finite plan before AI; no V2 engines; Prompt 61 registry kind; event wiring; eligibility has no score; forbidden sources; Opportunity finding links; CollectionRun not a source; no provider HTTP.

---

## 65. Reality Matrix

| Capability | Status |
| --- | --- |
| Evidence analytical fingerprint (excl. `updated_at`) | **REAL** |
| `EvidenceCanonicalized` → schedule intelligence | **REAL** |
| CollectionRun completion trigger | **FORBIDDEN** |
| Durable `intelligence_triggers` + idempotent `trigger_key` | **REAL** |
| Finite `intelligence_execution_plans` DAG | **REAL** |
| Analyzer kinds FINDING/OPPORTUNITY/AI_SKILL only | **REAL** |
| Dependency index (deterministic) | **REAL** |
| Eligibility dispositions (no numeric score) | **REAL** |
| Phase 1 → Prompt 39 | **REAL** |
| Phase 2 → Prompt 40 | **REAL** |
| Phase 3 → Prompt 50 planner only | **REAL** |
| Direct LLM from scheduler | **NONE** (0) |
| Automatic candidate promotion | **NONE / FORBIDDEN** |
| Human `AutomaticIntelligencePolicy` + exact pins | **REAL** |
| Reject `latest` / `*` / `auto` version tokens | **REAL** |
| AI budget / fan-out throttle | **REAL** |
| Same-input AI dedup | **REAL** |
| Pending plan supersede coalescing | **REAL** |
| Prompt 61 validity recheck adapter | **REAL** |
| Manual reevaluation service API | **REAL** |
| Agent swarm / `IntelligenceEngineV2` | **NONE** |
| Activity/Notification/Task/AgentResult triggers | **FORBIDDEN** |
| Provider calls on scheduler path | **NONE** |
| Sector / Brand Experience / Retrieval writes | **NONE** |
| New top-level Filament nav | **NONE** |
| Priority queue for intelligence | **NONE** |
| Running-plan cancel API | **NONE** |
| Auto-trigger on rule/skill catalog version bump | **NONE** |

---

## 66. Prompt64 Handoff

### Prompt64 handoff matrix

| Topic | Prompt 63 delivers | Prompt 64+ may own |
| --- | --- | --- |
| When to run analyzers | **YES** | Must not reimplement fingerprint/trigger tables |
| Finding/Opportunity semantics | Delegates 39/40 | Product refinements stay in those prompts |
| AI inference/runtime fire | Planner-only handoff | Explicit operator/runtime path under Prompt 50 |
| Retrieval packs | Owner tag only | Prompt 54 |
| Operator UX for policies/schedules | Service/table REAL; nav NONE | Optional Filament CRUD without new swarm engine |
| Collection lifecycle | Upstream only | Stay in Prompt 62 |

Prompt 64 must not introduce CollectionRun-completed intelligence triggers, Agent swarm graphs, or automatic AI candidate promotion.

---

## 67. Definition of Done

Prompt 63 is **DONE** when Reality Matrix statuses match the implemented code on base Prompt 62 HEAD `e43e2ae`: Evidence-analytical triggers (not CollectionRun completion); fingerprint excludes `updated_at`; four orchestration tables exist; three analyzer kinds and three phases only; Finding/Opportunity/AI runtimes are Prompt 39/40/50 respectively with **0** direct LLM from the scheduler; policies are human-pinned exact versions; Prompt 61 validity recheck adapter is registered; swarm/V2 engines/new top-level nav are absent; forbidden trigger sources are configured and untyped; PHPUnit feature coverage in `IntelligenceSchedulingTest` passes for the behaviors above.
