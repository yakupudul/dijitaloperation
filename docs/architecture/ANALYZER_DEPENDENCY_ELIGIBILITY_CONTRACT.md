# Analyzer Dependency & Eligibility Contract

> Prompt 63 — which analyzers wake for an Evidence/Finding change, and whether they may run.  
> Code: `AnalyzerDependencyIndex`, `AnalyzerEligibilityResolver`, `AnalyzerKind`, `AnalyzerEligibilityDisposition`.  
> Implementation: [`docs/implementation/INTELLIGENCE_SCHEDULING.md`](../implementation/INTELLIGENCE_SCHEDULING.md)  
> Related: [`INTELLIGENCE_TRIGGER_CONTRACT.md`](INTELLIGENCE_TRIGGER_CONTRACT.md) · [`AUTOMATIC_AI_INTELLIGENCE_POLICY.md`](AUTOMATIC_AI_INTELLIGENCE_POLICY.md)

**Base:** Prompt 62 HEAD `e43e2ae`.

---

## Canonical rule

Analyzer selection is **deterministic set intersection** over registries. Eligibility is **categorical** (enum disposition + reason string). There is **no** numeric relevance score, embedding similarity, or LLM classifier in Prompt 63.

```text
changed Evidence definition_ids (+ optional Finding stable ids)
  → AnalyzerDependencyIndex
    → candidate analyzers {FINDING_RULE | OPPORTUNITY_RULE | AI_SKILL}
      → AnalyzerEligibilityResolver
        → ELIGIBLE | blocked disposition
```

---

## Analyzer kinds

| Kind | Source registry | Identity |
| --- | --- | --- |
| `FINDING_RULE` | `FindingRuleRegistry::enabled()` | `analyzer_id` = rule id; `stable_id`; `version`; `evidence_definition_ids` |
| `OPPORTUNITY_RULE` | `OpportunityRuleRegistry::enabled()` | + `finding_rule_stable_ids` |
| `AI_SKILL` | `SkillRegistry` (optional DI) | `analyzer_id` / `skill_signature` = `signature()`; required/optional Evidence lists |

Forbidden kinds: anything else (`GENERAL`, `SWARM`, `OMNISCIENT` → `tryFrom` null).

---

## Dependency index

### Finding rules

`findingRulesForEvidenceDefinitions($changedEvidenceDefinitionIds)` — include enabled rules whose `evidenceDefinitionIds` intersect the changed set.

### Opportunity rules

`opportunityRulesForChanges($changedEvidenceDefinitionIds, $changedFindingRuleStableIds)` — include when Evidence intersection **or** Finding stable-id intersection is non-empty.

### AI skills

| Method | Match rule |
| --- | --- |
| `skillsForRequiredEvidenceChanges` | Intersects `requiredEvidence` |
| `skillsForOptionalEvidenceChanges` | Intersects `optionalEvidence` **and** does **not** intersect `requiredEvidence` |

If `SkillRegistry` is null, skill lists are empty.

### Dependency matrix

| Analyzer | Wakes on Evidence defs | Wakes on Finding stable ids | Policy required |
| --- | --- | --- | --- |
| Finding Rule | YES (intersection) | NO | NO |
| Opportunity Rule | YES | YES | NO |
| AI Skill | YES (required and/or optional per policy flags) | NO | YES (Active + signature match) |

---

## Required vs optional Evidence (skills)

| Class | Scheduling | Eligibility |
| --- | --- | --- |
| Required | Policy `trigger_on_required_evidence_change` (default true) | Every required definition must be present in change refs, not stale, not integrity-blocked |
| Optional | Policy `trigger_on_optional_evidence_change` (default false) | Optional wake only; required Evidence still must pass eligibility if listed on the skill |

Missing required Evidence → `REQUIRED_EVIDENCE_MISSING`. Stale `fresh_until` → `EVIDENCE_STALE`. Integrity blocked/failed or `eligibility_status = integrity_blocked` → `INTEGRITY_BLOCKED`.

---

## Scope applicability

| Check | Disposition | Reason |
| --- | --- | --- |
| Unknown analyzer kind | `SCOPE_NOT_APPLICABLE` | `UNKNOWN_ANALYZER_KIND` |
| Policy `digital_asset_id` set and ≠ asset | `SCOPE_NOT_APPLICABLE` | `ASSET_SCOPE_MISMATCH` |
| Policy `brand_id` ≠ asset brand | `SCOPE_NOT_APPLICABLE` | `BRAND_SCOPE_MISMATCH` |

Finding/Opportunity rules selected by the index are treated as `ELIGIBLE` / `DETERMINISTIC_RULE_ELIGIBLE` (downstream Prompt 39/40 may still evaluate blocked/indeterminate conditions).

---

## Eligibility dispositions

| Disposition | Used by Prompt 63 resolver / planner |
| --- | --- |
| `ELIGIBLE` | Rules + AI when all gates pass |
| `AUTOMATION_DISABLED` | Policy inactive or trigger kind not in `allowed_trigger_kinds` |
| `AI_BUDGET_BLOCKED` | Min interval / window max / planner fan-out skip |
| `REQUIRED_EVIDENCE_MISSING` | Required Evidence absent from present defs/refs |
| `EVIDENCE_STALE` | Required Evidence `fresh_until` past |
| `INTEGRITY_BLOCKED` | Required Evidence integrity/eligibility blocked |
| `SCOPE_NOT_APPLICABLE` | Kind/scope mismatch |

Also defined on the enum (reserved / adjacent): `NO_RELEVANT_DEPENDENCY`, `COVERAGE_INSUFFICIENT`, `SERVICE_SCOPE_NOT_APPLICABLE`, `ACTIVE_EQUIVALENT_EXECUTION`, `UNCHANGED_INPUT`. Same-input AI skip at execute time uses outcome `deduped` / `SAME_AUTOMATIC_INPUT` rather than forcing that disposition at plan time.

**Hard rule:** no disposition value is a numeric score (`SCORE_42` must not exist).

---

## Evidence eligibility matrix (AI required Evidence)

| Observation signal | Gate |
| --- | --- |
| Definition id not in present set / refs | Missing |
| `fresh_until` &lt; now (UTC) | Stale |
| `integrity` in {blocked, integrity_blocked, failed} (case-insensitive) | Integrity blocked |
| `eligibility_status = integrity_blocked` | Integrity blocked |
| Otherwise | Pass for that definition |

---

## Planner integration

`IntelligenceSchedulingPlanner`:

1. Builds candidates from the index (Finding-state triggers → Opportunity candidates only).  
2. For AI, iterates Active policies for brand/asset, merges skill candidates per policy flags, filters by `skill_signature`, attaches pin fields.  
3. Runs eligibility; splits into phase buckets and `skipped`.  
4. Empty eligible → plan status `NO_RELEVANT_ANALYZER`.

AI fan-out: stop adding when `aiAdded >= min(config max_ai_fanout_per_plan, policy.max_fanout_per_plan)`.
