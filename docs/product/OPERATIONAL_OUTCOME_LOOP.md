# Operational Outcome Loop V1 + Search Demand Phase 13

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
| `technically_fixed` | Human-accepted post-change HTML observation no longer contains the original deterministic technical condition |
| `content_change_verified` | Human accepted the stored before/after semantic verification of the intended change |
| `visibility_increased` | Comparable stored GSC/SERP visibility observations increased; no causal claim |
| `visibility_decreased` | Comparable stored GSC/SERP visibility observations decreased; no causal claim |
| `no_change_observed` | Comparable observations show no consistent direction |
| `too_early` | The configured review-after time has not arrived |
| `insufficient_data` | Stored post-change evidence or comparable periods are missing |

Evaluator versions: `finding-lifecycle-outcome-v1` for the generic loop and `search-demand-change-outcome-v1` for Phase 13 (stored in `outcome_json.version`).

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

## Search Demand Phase 13 extension

For completed Tasks promoted from an approved Phase 12 proposal, `/library/search-demand-changes` records the implementation and coordinates:

1. baseline HTML fingerprint capture;
2. exact affected-URL plus bounded page-family Public Crawl;
3. collection-linked post-change fingerprint capture and deterministic technical recheck;
4. review-only AI semantic before/after verification;
5. stored GSC, GA4 and SERP period comparison;
6. explicit human acceptance before Task Outcome and Finding lifecycle updates.

The Phase 13 tables are audit/provenance records for applied changes and verification attempts. They are not a second Outcome truth. Current Outcome remains on Task, and no provider/CMS write is introduced.

## Explicit non-goals

- No Result entity / Result table / Outcome table / task_outcome_events
- No automatic Task creation
- No automatic external actions / Google Ads writes / Website writes
- No autonomous AI Outcome decision; Phase 13 AI output requires human acceptance
- No Capability Router / Playbook runtime / RAG / learned-memory automation
- No causal attribution, automatic paid SERP refresh or generic cross-module metric Outcome engine

---

## Future (PLANNED — not implemented)

- Additional module-specific Outcome Signals beyond Search Demand
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
