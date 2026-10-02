# Client Value Story Contract

> Prompt 58 — deterministic period-scoped read projection.  
> Implementation: `App\Services\ClientValueStory\ClientValueStoryReadService`  
> Authority: [`CLIENT_VALUE_STORY_SOURCE_AUTHORITY.md`](CLIENT_VALUE_STORY_SOURCE_AUTHORITY.md)

## Scope

| Field | Contract |
| --- | --- |
| Customer | Required; must match Brand |
| Brand | Required; authorization enforced |
| Period | Explicit `period_start` / `period_end` (dates) |
| Generated at | Projection generation time (not business observation time) |

## Sections

| Section | Source | Label |
| --- | --- | --- |
| Observed | Findings | What We Observed |
| Potential | Opportunities | Opportunities / Potential |
| Work Performed | Tasks (completed / active) | What We Did |
| Reported Outcomes | Business Outcomes (Prompt 57) | Reported Business Outcomes |
| Limitations | Derived | Limitations |

Semantic mixing forbidden (Finding ≠ Outcome, Task ≠ Business Result).

## Finding items

- Finding ID, title, severity, status, DigitalAsset
- Period role from lifecycle intersection
- Evidence evaluation id when present
- Never claims business impact / causality

## Opportunity items

- Opportunity ID, title, status, qualitative priority when canonical
- Always `is_potential=true`, `realized_value=false`
- No magic value score

## Work items

- Task ID, title, source kind, completion timestamp
- Completed only via canonical `completed_at` in period
- QA / Approval projections exposed; never imply verified success / approved / business result
- Optional Recommendation → Finding/Opportunity lineage refs

## Business Outcome items

- Kind order: Qualified Lead → Consultation → Sale/Patient → Revenue
- Value / currency / coverage / completeness / gaps / revision ids from Prompt 57
- Missing = null; zero = explicit `"0"`
- No provider conversion fallback

## Status

`complete` | `partial` | `unavailable`

## Source Manifest

References only (no full payload copies):

- finding_ids
- opportunity_ids
- task_ids
- outcome_definition_ids
- outcome_observation_revision_ids
- limitation_codes

`prompt59_pinnable: true`

## Ordering

- Findings: `last_seen_at` DESC, id ASC
- Opportunities: qualitative priority then `last_detected_at` DESC
- Completed Work: `completed_at` DESC
- Outcomes: fixed kind order

## Forbidden

Writable Story tables, AI copy, attribution, ROI/ROAS, Value Score, Demo fallback for production Brands.
