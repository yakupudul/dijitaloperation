# Automatic AI Intelligence Policy

> Prompt 63 — human-approved, version-pinned automation that may add `AI_SKILL` analyzers to an Intelligence Execution Plan.  
> Code: `AutomaticIntelligencePolicy`, `AutomaticIntelligencePolicyService`, `AutomaticIntelligencePolicyStatus`, Phase 3 in `ExecuteIntelligencePlanService`.  
> Implementation: [`docs/implementation/INTELLIGENCE_SCHEDULING.md`](../implementation/INTELLIGENCE_SCHEDULING.md)  
> Related: [`ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md`](ANALYZER_DEPENDENCY_ELIGIBILITY_CONTRACT.md) · [`INTELLIGENCE_EXECUTION_DAG.md`](INTELLIGENCE_EXECUTION_DAG.md)

**Base:** Prompt 62 HEAD `e43e2ae`.

---

## Canonical rule

Automatic AI in Prompt 63 is **opt-in, human-created, exact-version-pinned, budget-bounded**, and **planner-only** (Prompt 50 `AgentExecutionPlanner`). Without an Active policy, Phase 3 is empty. Policies are never AI-created or AI-enabled. Candidates are never auto-promoted. Direct LLM calls from the scheduler are **0**.

```text
Human creates AutomaticIntelligencePolicy (exact pins)
  → Planner attaches matching AI_SKILL if Evidence wake + eligibility
    → Execute calls AgentExecutionPlanner::plan
      → outcome planned_for_prompt50 | blocked | deduped
```

---

## Policy fields

| Field | Contract |
| --- | --- |
| Scope | `customer_id`, `brand_id`, optional `digital_asset_id` |
| Agent pin | `agent_slug` + `agent_version` (exact; not latest) |
| Skill pin | `skill_signature` + `skill_version` |
| Route pin | `route_key` + `route_signature` |
| Triggers | `allowed_trigger_kinds` (non-empty; default Evidence analytical) |
| Evidence wake | `trigger_on_required_evidence_change` (default true), `trigger_on_optional_evidence_change` (default false) |
| Budget | `max_automatic_runs_per_window`, `window_minutes`, `min_interval_minutes` |
| Fan-out | `max_fanout_per_plan` (default 3; also capped by config `max_ai_fanout_per_plan`) |
| Identity | `policy_fingerprint` (hash of scope+pins+triggers+budget knobs), `policy_version` |
| Runtime counters | `last_automatic_run_at`, `runs_in_window`, `window_started_at` |
| Status | `active` \| `paused` \| `disabled` \| `archived` |

Unique constraint: `(brand_id, digital_asset_id, skill_signature, agent_slug, agent_version)`.

---

## Human control

| Operation | Service method | Notes |
| --- | --- | --- |
| Create | `AutomaticIntelligencePolicyService::create` | Requires all pin fields; asserts customer/brand authorization lists when provided |
| Disable | `disable` | Status → `disabled`; subsequent plans get zero eligible AI for that policy |
| Pause | `pause` | Status → `paused` (not Active) |
| Resume | `resume` | Status → `active` |

AI / Assistant must not call create/enable paths. `isActive()` is true only for `active`.

---

## Version pinning

| Token | Accepted |
| --- | --- |
| Explicit semver / signature strings (e.g. `1.0.0`, `route@1`) | YES |
| `latest`, `*`, `auto` (case-insensitive) on agent_version, skill_version, or route_signature | **NO** → `ValidationException` `EXACT_VERSION_REQUIRED` |

Config `forbid_latest_version_tokens` documents the same rule. At execute time, registry `AgentProfileDefinition.version` must equal `agent_version` or the analyzer returns `AGENT_VERSION_PIN_MISMATCH`.

---

## AI policy matrix

| Concern | Status |
| --- | --- |
| Human-created policy rows | **REAL** |
| Exact Agent/Skill/Route pins | **REAL** |
| Reject latest-like tokens | **REAL** |
| Allowed trigger kinds gate | **REAL** |
| Required/optional Evidence wake flags | **REAL** |
| Budget window + min interval | **REAL** |
| Fan-out cap per plan | **REAL** |
| Prompt 50 planner invocation | **REAL** |
| Direct LLM from Prompt 63 | **NONE** (0) |
| Auto-promote AI candidates | **FORBIDDEN** |
| Agent-to-agent from scheduler | **NONE** |
| AI creates policies | **FORBIDDEN** |

---

## Eligibility & budget

`AnalyzerEligibilityResolver` for `AI_SKILL`:

1. Policy Active  
2. Trigger source ∈ `allowed_trigger_kinds` (when provided)  
3. Brand/asset scope  
4. Required Evidence present, fresh, integrity-ok  
5. `withinBudget` (min interval; window run count)

`ExecuteIntelligencePlanService::consumeBudget` advances window counters after a successful planner path (non-deduped, non-blocked before planner success).

---

## Same-input automatic dedup

Before calling Prompt 50, execute hashes:

`asset + agent@version + skill_signature + route_signature + policy_fingerprint + analyzer_id`.

If a prior **COMPLETED** plan for the asset stored that hash under `metadata.ai_input_fingerprints.{policyId}`, return `deduped` / `SAME_AUTOMATIC_INPUT` with `ai_calls = 0`.

---

## Phase 3 outcomes

| Outcome | Meaning |
| --- | --- |
| `planned_for_prompt50` | Pins match; skill eligible in Prompt 50 plan; budget consumed; no LLM fired here |
| `blocked` | Automation disabled, pin mismatch, skill not eligible, planner unavailable, … |
| `deduped` | Same automatic input already completed |

Always recorded: `direct_llm_calls = 0`, `candidate_auto_promotion = false`, `agent_to_agent = false`, `retrieval_owner = Prompt54` (owner tag only).

---

## Forbidden recursive edges

Documented in config and enforced by architecture:

- AI result → Agent  
- AI candidate → Agent  
- Opportunity → Finding (as a scheduler edge)  
- Task / Activity / Notification → Intelligence  

Prompt 63 must not open those edges.
