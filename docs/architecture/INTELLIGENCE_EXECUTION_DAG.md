# Intelligence Execution DAG

> Prompt 63 — finite, server-side, immutable three-phase execution graph.  
> Code: `IntelligenceSchedulingPlanner`, `ExecuteIntelligencePlanService`, `ExecuteIntelligencePlanJob`, `IntelligenceExecutionPlan`, `IntelligencePlanPhase`, `IntelligencePlanStatus`.  
> Implementation: [`docs/implementation/INTELLIGENCE_SCHEDULING.md`](../implementation/INTELLIGENCE_SCHEDULING.md)  
> Related: [`INTELLIGENCE_TRIGGER_CONTRACT.md`](INTELLIGENCE_TRIGGER_CONTRACT.md) · [`ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md`](ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md) · [`AUTOMATIC_AI_INTELLIGENCE_POLICY.md`](AUTOMATIC_AI_INTELLIGENCE_POLICY.md)

**Base:** Prompt 62 HEAD `e43e2ae`.

---

## Canonical rule

Every Intelligence Execution Plan is a **finite DAG fixed before AI calls**. Phases are always:

1. `PHASE_1_FINDING_RULES` → Prompt 39 `FindingEvaluationService`  
2. `PHASE_2_OPPORTUNITY_RULES` → Prompt 40 `OpportunityEvaluationService`  
3. `PHASE_3_AI_SKILLS` → Prompt 50 `AgentExecutionPlanner` only  

No Agent swarm, no client-authored workflow graph, no morph executor, no mid-run edge insertion. Plans stamp `analyzers.finite_before_ai = true` and `analyzers.swarm = false`.

```text
IntelligenceTrigger
  → IntelligenceSchedulingPlanner.planForTrigger
      plan_fingerprint = iplan:sha256(trigger_id, asset_id, source_revision, phases)
  → [optional] supersede other PLANNED plans for same asset
  → ExecuteIntelligencePlanService.execute  (sync or ExecuteIntelligencePlanJob)
      PHASE_1 → PHASE_2 → PHASE_3
  → COMPLETED (or terminal NO_RELEVANT_ANALYZER at plan time)
```

---

## Plan identity

| Field | Contract |
| --- | --- |
| `plan_fingerprint` | Unique; reuse returns existing plan |
| `intelligence_trigger_id` | Originating trigger |
| `trigger_ids` | JSON list (coalesce-ready) |
| `evidence_input_fingerprints` | Per-ref analytical fingerprints |
| `analyzers` | `{ phases, skipped, finite_before_ai, swarm }` |
| `phase_results` | Per-phase stats / AI outcomes |
| `current_phase` | Set while running; cleared on completion |
| `status` | See status table |

---

## Phase matrix

| Phase | Analyzers | Runtime | Prompt 63 direct writes |
| --- | --- | --- | --- |
| `PHASE_1_FINDING_RULES` | `FINDING_RULE` eligible | `FindingEvaluationService::evaluateAsset(..., $ruleIds, null)` | None beyond orchestration; Findings via Prompt 39 |
| `PHASE_2_OPPORTUNITY_RULES` | `OPPORTUNITY_RULE` eligible | `OpportunityEvaluationService::evaluateAsset(..., $ruleIds, null)` | Opportunities via Prompt 40 only |
| `PHASE_3_AI_SKILLS` | `AI_SKILL` eligible | `AgentExecutionPlanner::plan` | No Recommendations/Tasks; no LLM |

Hard guard: if Recommendation or Task row counts change during execute → `RuntimeException`.

---

## Finding → Opportunity edge (bounded)

Material Finding create/reopen/resolve in Phase 1 may record **lineage** `FINDING_STATE_CHANGED` triggers via `recordFindingStateChanged`. Those triggers **do not mutate** the immutable running plan. Phase 2 already contains Opportunity rules selected at plan time from Evidence and/or Finding dependencies. Opportunity → Finding edges are **FORBIDDEN** as scheduler recursion.

---

## AI phase constraints

| Constraint | Enforcement |
| --- | --- |
| Finite before AI | Phases serialized into plan JSON before execute |
| Policy required | No Active policy ⇒ empty Phase 3 |
| Version pins | Agent registry version must match policy |
| Planner only | No direct LLM; `direct_llm_calls = 0` |
| No swarm | `swarm = false`; no agent-to-agent |
| No auto promotion | `candidate_auto_promotion = 0/false` |
| Same-input dedup | Skip paid planner path when fingerprint matches prior COMPLETED plan |
| Fan-out | Global + per-policy max AI analyzers |

---

## Status lifecycle

| Status | Meaning |
| --- | --- |
| `PLANNED` | Ready / queued |
| `RUNNING` | Execute in progress |
| `COMPLETED` | All phases finished |
| `NO_RELEVANT_ANALYZER` | Planned with zero eligible analyzers |
| `SUPERSEDED` | Replaced by newer plan for same asset while still PLANNED |
| `COALESCED` | Terminal coalesced classification |
| `FAILED` / `BLOCKED` | Terminal failure/block (enum present) |

`isTerminal()` treats Completed, Failed, Blocked, Coalesced, Superseded, NoRelevantAnalyzer as non-executable. Jobs no-op on missing or terminal plans.

---

## Coalescing & dispatch

| Mechanism | Behavior |
| --- | --- |
| Plan fingerprint hit | Reuse plan; mark trigger PLANNED if needed |
| Newer plan for asset | Prior PLANNED → SUPERSEDED (+ trigger SUPERSEDED) |
| Async | `ExecuteIntelligencePlanJob` on `moxdop-intelligence-scheduling.queue` when `dispatch_async` and not sync |
| Sync | `executor->execute` inline (tests / manual / validity adapter) |

---

## Swarm prevention matrix

| Pattern | Status |
| --- | --- |
| Dynamic Agent swarm graph | **FORBIDDEN / NONE** |
| LLM chooses next agents mid-flight | **FORBIDDEN** |
| AI candidate → new Agent edge | **FORBIDDEN** |
| Opportunity → Finding scheduler edge | **FORBIDDEN** |
| Activity/Notification/Task → plan | **FORBIDDEN** |
| Server-fixed 3-phase DAG | **REAL** |

---

## Downstream ownership

| Concern | Owner |
| --- | --- |
| Finding semantics / persistence | Prompt 39 |
| Opportunity semantics / persistence | Prompt 40 |
| Agent skill eligibility planning | Prompt 50 |
| Retrieval packs | Prompt 54 (not called here) |
| Validity schedule ticks | Prompt 61 adapter → Prompt 63 scheduler |
| Collection lifecycle | Prompt 62 (upstream only) |

---

## What this DAG is not

- Not `IntelligenceEngineV2`  
- Not a generic BPMN / morph workflow engine  
- Not a CollectionRun continuation graph  
- Not an Activity/Notification processor  
- Not an automatic Recommendation/Task factory  
