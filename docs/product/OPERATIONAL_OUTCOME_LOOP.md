# Operational Outcome Loop V1

> **STATUS: IMPLEMENTED V1**  
> Authority: `MASTER_SPEC` → ADR-025 / ADR-029 / ADR-034 / ADR-036 → this doc.  
> Related: [`ANALYSIS_PIPELINE.md`](./ANALYSIS_PIPELINE.md), [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md).

---

## Implemented loop

```
Finding
→ Recommendation
→ Task (human-created, immutable snapshot)
→ human completion
→ later comparable Finding evaluation
→ current observed Outcome signal on Task
```

This closes MoxDOP’s first real operational loop:

**ANALYZE → ACT → OBSERVE → VERIFY PROGRESS**

---

## Implemented Outcome signals

Stored on `tasks.outcome_status` (no Result / Outcome entity or table):

| Signal | Meaning |
| --- | --- |
| `awaiting_follow_up` | Completed; no eligible comparable Finding evaluation yet |
| `improvement_observed` | Linked Finding resolved in a successful eligible follow-up evaluation |
| `still_observed` | Linked Finding still present in latest comparable successful evaluation |
| `regression_observed` | Improvement was observed earlier; same stable Finding later reopened |
| `insufficient_evidence` | Relevant follow-up attempt existed but was not successful/complete enough |
| `not_evaluable` | Insufficient auditable Finding provenance |

Evaluator version: `finding-lifecycle-outcome-v1` (stored in `outcome_json.version`).

---

## Task status vs Outcome status

These are **separate**:

- **Task status** (`open` / `in_progress` / `blocked` / `completed` / `cancelled`) = human work progress
- **Outcome status** = observed post-action Evidence signal after completion

Example: Task = Completed, Outcome = Still observed.

Completing a Task does **not**:

- resolve the Finding
- mutate Evidence
- rewrite Recommendation truth
- perform external writes

---

## No causal attribution

Outcome V1 is **observed post-action Evidence**, not scientific causal attribution.

Good:

> “The linked Finding was resolved in a successful follow-up Run after this Task was completed.”

Bad:

> “This Task fixed the problem.” / “This optimization increased conversions.”

Every Outcome explanation sets `causal_attribution: false`.

---

## Exact Finding identity

Outcome matching uses:

- Task → Recommendation → Finding
- stable Finding fingerprint
- same Digital Asset
- same source module
- owning rule evaluated (`evaluatedRuleIds` ownership, same as Finding lifecycle)

Not used: title text, AI similarity, fuzzy matching, category alone.

Optional `outcome_review_after_at` delays eligibility when immediate follow-up data would be too early.

---

## Explicit non-goals (V1)

- No Result entity / Result table / Outcome table / task_outcome_events
- No automatic Task creation
- No automatic external actions / Google Ads writes / Website writes
- No AI Outcome classification
- No Capability Router / Playbook runtime / RAG / learned-memory automation
- No module-specific metric Outcome engines (SEO traffic, CPA, ROAS, rankings)

---

## Future (PLANNED — not implemented)

- Module-specific Outcome Signals comparing bounded Watch Metrics (before/after, measurement window) where metrics are genuinely comparable
- Learning Candidates promoted from trustworthy Outcomes
- Playbooks orchestrating multiple Tasks/Agents
- Digital Operations Analyst interpreting Outcomes with AI

---

## Operator surfaces

- Operations → Tasks (list + Before / Action / After / Outcome detail)
- Human actions: Start work, Block, Resume, Complete, Cancel
- Manual **Re-evaluate outcome** (stored Run/Finding state only; no external I/O / AI)
- Finding / Recommendation traceability to related Tasks + Outcome
- Ops dashboard counts for open / awaiting follow-up / regression / improvement
