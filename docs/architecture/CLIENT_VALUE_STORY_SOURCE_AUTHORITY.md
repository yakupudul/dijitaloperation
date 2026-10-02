# Client Value Story Source Authority

> Prompt 58 — what each Story source may and may not claim.

## Sources

| Story section | Canonical source | May claim | May NOT claim |
| --- | --- | --- | --- |
| Observed | Finding | Condition identified / resolved in period | Business impact, Revenue caused, Patients caused |
| Potential | Opportunity | Potential situation present/closed | Realized value, captured €, attribution |
| Work Performed | Task | Work created / active / completed; QA/Approval state | Finding resolved by Task, Outcome caused, ROI |
| Reported Outcomes | Business Outcome | Client-reported aggregate for period with coverage | Channel attribution, agency-generated Revenue |

## Causality boundary

Temporal coexistence is allowed:

> During the selected period, X Findings were identified, Y Tasks were completed, and the client reported Z.

Forbidden:

> As a result of our work, Revenue increased…
> Google Ads generated 21 Qualified Leads…
> We increased Consultations by 38%.

## Attribution boundary

Business Outcomes are Brand-level reported results.

Never auto-attribute to Google Ads, Meta, SEO, GBP, Website, Recommendation, or Task.

No multi-touch attribution domain in Prompt 58.

## Precise Assistant facts

| Question | Preferred source |
| --- | --- |
| Summarize value story this month | `ClientValueStoryReadService` |
| How many Patients were reported? | `BusinessOutcomeReadService` directly |
| What Findings are open? | Finding read service |

Value Story summaries always retain `no_attribution` / `no_causality` limitations.

## QA / Approval

| State | May say completed? | Verified success? | Client approved? | Business result? |
| --- | --- | --- | --- | --- |
| Task completed | YES | NO (unless QA pass separate) | NO | NO |
| Completed + QA failed | YES | NO | NO | NO |
| Completed + Approval pending | YES | NO | NO | NO |
| QA pass | — | QA verification only | NO | NO |

## Claim examples

| Claim | Allowed? |
| --- | --- |
| “5 Findings were identified.” | YES |
| “8 Tasks were completed.” | YES |
| “Client reported 12 Consultations.” | YES |
| “Revenue was EUR 20,000.” | YES (reported) |
| “We generated EUR 20,000.” | NO |
| “Google Ads generated 12 Consultations.” | NO |
| “Reported Consultations were higher than prior period.” | YES if comparable |
| “We increased Consultations.” | NO |
