# RECURRING REVIEW PRODUCTION PERSISTENCE

## 1. Purpose

Prompt 46 productionizes frozen Demo Recurring Reviews as a canonical Schedule → Run → Check outcome domain. Recurring Review is a cadence-bound operator checklist execution over a Playbook-bound scope — distinct from QA (`qa_reviews`), Approval, Task, Playbook knowledge, Recommendation, Finding/Opportunity detection engines, Business Outcomes, AI, and provider collection. Work remains an aggregate UI. No cron / Laravel Schedule registration in this prompt.

## 2. Frozen Review Product Audit

Surfaces inspected: Operations → Work filter `recurring_reviews`; Work list rows `type=recurring_review`; Work detail (`type=recurring_review`) complete/skip actions; Brand Operations recurring review list; Dashboard capacity glance (`recurring_reviews_due`); Playbook detail residual “related reviews” Demo helper. No Filament Recurring Review resource. No schedule create UI redesign in Prompt 46 (service write + frozen Work/Brand reads).

## 3. Existing Review Primitive Audit

Demo: `AgencyExecutionFixtures::recurringReviews()` with synthetic ids `rr-*`, Livewire complete/skip via DemoState. No prior `recurring_review_*` tables. Ambiguous Demo Approval `type=qa` retired in Prompt 44. Generic `Review` model was never created — reserved for this domain’s tables. QA uses `qa_reviews` only.

## 4. QA vs Recurring Review

| Concept | Question | Persistence |
|---|---|---|
| QA | Was Task work checked against quality criteria? | `qa_reviews` (Task subject) |
| Recurring Review | Did we execute a scheduled Playbook-bound checklist on a Customer/Brand/Asset scope? | `recurring_review_schedules` + runs + items |

No shared `review_status`. Completing a Recurring Review does not create QA. QA PASS does not complete a Recurring Review.

## 5. Canonical Recurring Review Decision

Tables: `recurring_review_schedules`, `recurring_review_check_definitions`, `recurring_review_runs`, `recurring_review_run_items`, `recurring_review_run_item_task_links`. Task source extended: `source_kind=recurring_review_check` + `tasks.recurring_review_run_item_id`. Distinct from `qa_reviews`.

## 6. Schedule vs Run

Schedule = durable cadence + scope + Playbook binding + check definitions. Run = one materialized occurrence (scheduled or manual) with snapshotted Playbook revision and check items. Materialization creates Runs; completing Checks creates outcomes. Schedule create does not auto-create Runs.

## 7. Schedule Identity

Stable numeric `id`. Optional unique `idempotency_key` on create. Playbook referenced by `playbook_id` (not stable_key alone). Title comes from Playbook current revision at read time — Schedule has no independent title column.

## 8. Schedule Lifecycle

Status: `active` | `paused` | `ended` (`RecurringReviewScheduleStatus`). Pause clears `next_due_at`. Resume recalculates from now (no catch-up explosion). End clears `next_due_at` and blocks cadence updates / pause-resume.

## 9. Cadence / Recurrence

Cadences: `weekly` | `monthly` | `quarterly` only (`RecurringReviewCadence`). Required on create/update — **no hidden monthly default** (`CADENCE_REQUIRED`). Advancement via `RecurringReviewDueCalculator` (`addWeek` / `addMonthNoOverflow` / `addMonthsNoOverflow(3)`).

## 10. Timezone

`timezone` required (PHP timezone validator). Due calculation is timezone/DST-aware on the schedule’s zone. Occurrence keys use local due wall-time format.

## 11. Scope Ontology

`RecurringReviewScopeKind`: `customer` | `brand` | `digital_asset`. Same closed shape as Task/Playbook execution scopes. PostgreSQL CHECK `rr_schedules_scope_shape_check`.

## 12. Customer Scope

`customer_id` set; `brand_id` null; `digital_asset_id` null. Finding/Opportunity/Evidence outcomes require DigitalAsset — Customer-scope runs cannot publish those outcomes (publisher returns null / validators throw `DIGITAL_ASSET_REQUIRED`).

## 13. Brand Scope

`customer_id` + `brand_id`; `digital_asset_id` null. Brand must belong to Customer. Same DigitalAsset requirement for Finding/Opportunity/Evidence.

## 14. DigitalAsset Scope

`customer_id` + `brand_id` + `digital_asset_id`. Hierarchy validated in `RecurringReviewScheduleService`. Enables Evidence / Finding / Opportunity publication.

## 15. Playbook Binding

Schedule requires active Playbook with current revision. Applicability checked at create via `PlaybookApplicabilityResolver::resolveForReviewScope`. Schedule does **not** auto-convert Playbook instructions into checks.

## 16. Playbook Revision Snapshot

On materialize, Run stores `playbook_id` + `playbook_revision_id` = Playbook’s **current** revision at materialization time. Historical Runs retain their revision FK (`restrictOnDelete`). Later Playbook revisions do not rewrite past Runs.

## 17. Checklist / Check Definitions

Schedule owns `recurring_review_check_definitions` (ordered `position`, `title`, optional `description`, `is_required`, `is_active`, optional `finding_rule_stable_id` / `opportunity_rule_stable_id`). At least one check required on create. `updateChecks` soft-retires prior active defs (`is_active=false`) and inserts new rows — historical Run Items keep FKs + snapshots.

## 18. Check vs Playbook Instruction Boundary

Playbook `playbook_instructions` / knowledge / `qa_guidance` = **PLAYBOOK_INSTRUCTION / knowledge** — not Recurring Review checks. Operators supply check definitions explicitly on the Schedule. No automatic instruction→check conversion.

## 19. Run Materialization

`MaterializeRecurringReviewOccurrence` creates a Run + pending items from **active** check definitions. Does not create Tasks/Findings/Opportunities/Evidence. Does not register a scheduler. Archived / missing-revision Playbook → `PLAYBOOK_UNAVAILABLE`.

## 20. Occurrence Key / Idempotency

Unique `(schedule_id, occurrence_key)`. Scheduled keys: `scheduled:{Y-m-d\TH:i:s}` via `RecurringReviewDueCalculator::occurrenceKey`. Manual: `manual:{uuid}`. Duplicate materialize returns existing Run (unique violation retry-safe).

## 21. Occurrence Kind

`scheduled` | `manual` (`RecurringReviewOccurrenceKind`). Only scheduled completions advance `schedule.next_due_at`. Manual “Run now” never collides with scheduled keys.

## 22. Run Lifecycle / Status

`scheduled` → `in_progress` → `completed` | `skipped` | `cancelled` (`RecurringReviewRunStatus`). Start sets `started_at` + optional `reviewer_user_id`. Complete requires all required items terminal. Skip allowed from scheduled/in_progress. Run lifecycle never mutates Finding/Opportunity/Task status fields.

## 23. Run Items

One item per active check at materialize. Snapshots: `title_snapshot`, `description_snapshot`, `is_required_snapshot`, rule stable id snapshots. Unique `(run_id, check_definition_id)`.

## 24. Run Item State

`pending` | `completed` | `skipped` | `not_applicable` (`RecurringReviewRunItemState`). Terminal states immutable except identical idempotent replay. Conflict on different outcome → `CONFLICT`.

## 25. Outcomes

Primary outcomes when `state=completed`: `no_issue` | `finding` | `opportunity` | `task` (`RecurringReviewOutcomeKind`). Skip / not_applicable set state only (`outcome_kind` null). Finding/Opportunity outcomes do **not** auto-create Tasks. No Recommendation auto-create.

## 26. Outcome XOR FKs

PostgreSQL `rr_run_items_outcome_fk_check`:

| outcome_kind | finding_id | opportunity_id | task_id |
|---|---|---|---|
| null (skip/n/a) | null | null | null |
| no_issue | null | null | null |
| finding | NOT NULL | null | null |
| opportunity | null | NOT NULL | null |
| task | null | null | NOT NULL |

`evidence_id` optional companion (published when DigitalAsset present).

## 27. Review Evidence

`RecurringReviewEvidencePublisher` publishes `definition_id=recurring_review.operator_observation`, `source_module=recurring-review`. Bounded payload (outcome, check ids, title snapshot) — **no confidential notes**. Zero provider HTTP. Requires `digital_asset_id` on Run; otherwise returns null. Fingerprint over review_run_id + run_item_id + observation_kind + outcome_revision.

## 28. Finding Integration

`CreateFindingFromReviewCheck`: origin `operator`; `source_module=recurring-review`; rule/fingerprint `rr.check.{check_definition_id}` (or explicit `finding_rule_stable_id_snapshot`); requires DigitalAsset + Evidence. Re-sees existing open Finding (`last_seen_at`) — **never direct resolve**. Never creates Task/Recommendation.

## 29. Opportunity Integration

`CreateOpportunityFromReviewCheck`: origin `operator`; rule `rr.check.{id}` (or snapshot); fingerprint = rule + semantic hash over scope/check; requires DigitalAsset + Evidence. Re-detects existing Opportunity — **never auto-close**. No magic score. Never creates Task/Recommendation.

## 30. Task Outcome

`CreateOrLinkTaskFromReviewCheck` via `CreateTask` with `source_kind=recurring_review_check` and `recurring_review_run_item_id`. Does **not** auto-assign reviewer. Writes `recurring_review_run_item_task_links` (`created` | `existing_linked`).

## 31. Task Spam Prevention

`ResolveReviewTaskDisposition::findOpenReviewOriginTask` correlates open Tasks by **schedule_id + check_definition_id + customer/brand/digital_asset scope** — never title matching. Default task outcome links existing open Task; `forceCreateAnother` creates another.

## 32. Task Source

Bounded source XOR (Prompt 43 + 46): `recommendation` | `client_request` | `direct` | `recurring_review_check`. For recurring: `recurring_review_run_item_id` NOT NULL; recommendation/client_request FKs NULL. No unrestricted morphTo. Finding/Opportunity/Evidence are never Task sources.

## 33. Review Task Lineage

Primary lineage: `tasks.recurring_review_run_item_id` → originating item. Secondary links: `recurring_review_run_item_task_links` for created vs existing_linked across occurrences. Linking an existing Task does **not** rewrite its primary `recurring_review_run_item_id`.

## 34. Review Completion

`RecurringReviewRunService::completeRun` requires required items completed/skipped/not_applicable; writes `summary_json` counts; records activity; advances schedule `next_due_at` for scheduled occurrences when schedule still active. Frozen UI `RecurringReviewUiActions::completeReview` applies primary disposition to first pending item then fills remaining as `no_issue`.

## 35. Skip / Cancel

`skipRun` sets status skipped + optional reason in summary; advances schedule like completion for scheduled kind. Cancelled is a terminal status in the enum/CHECK; no dedicated cancel writer in Prompt 46 services beyond status vocabulary.

## 36. Service Scope Awareness

Materialize stores `service_scope_context_json` from Playbook applicability resolution (context + reasons + service_match + asset_type_compatible + applicable). Recurring Review **never mutates** CustomerServiceScope / billing / contract.

## 37. Playbook Applicability

Create requires `service_match` and `asset_type_compatible` true (`PLAYBOOK_SERVICE_MATCH_FALSE` / `PLAYBOOK_ASSET_TYPE_INCOMPATIBLE`). Materialize still snapshots resolution context even when historically scheduled. Archived Playbook blocks new materialization.

## 38. Work Aggregate Integration

`WorkReadService` includes `RecurringReviewReadService::forWorkItemPresentation` (scheduled/in_progress Runs only). Passive read — **never materializes on list**. Rows use numeric Run id, `source_state=REAL`. Demo `rr-*` ids not used on production Work path.

## 39. Brand Operations Integration

`forBrandPresentation` lists brand-scoped scheduled/in_progress/completed Runs for Brand Operations. Same DB-backed presentations.

## 40. Activity

`RecurringReviewActivityRecorder` → `BrandContextActivity` only when `brand_id` present. Events: schedule created/updated/paused/resumed/ended; review started/completed/skipped; finding/opportunity recorded; task created / existing linked. **No activity for `no_issue`.** No notification engine.

## 41. Scheduler Boundary

`MaterializeRecurringReviewOccurrence` is an explicit callable. `dueSchedules` is a read of `next_due_at` — does not materialize. **No** `Schedule::daily`, no Laravel console schedule registration, no automatic occurrence fan-out in Prompt 46. Automatic scheduler is Prompt 61 handoff.

## 42. Read Architecture

`RecurringReviewReadService`: scheduleList, dueSchedules, runDetail, forWorkItemPresentation, forBrandPresentation. DB only. Never Demo fixtures on production paths.

## 43. Write Architecture

Writers: `RecurringReviewScheduleService`, `MaterializeRecurringReviewOccurrence`, `RecurringReviewRunService`, `CompleteRecurringReviewCheck`, Finding/Opportunity/Task creators, Evidence publisher. UI adapter: `RecurringReviewUiActions` (thin). No Livewire domain writes bypassing services.

## 44. Demo Migration / Retirement

Demo fixtures in `AgencyExecutionFixtures::recurringReviews()` are **not** migrated as Customer history (no fabricated actors/timestamps). Classification: DEMO_ONLY / CURATED_EXAMPLES — skip as operational truth. Production Work/Brand use DB Runs only. Residual fixture helpers may remain for non-production catalog tests.

## 45. Authorization

Admin / Team Member for schedule lifecycle and review completion (existing panel auth). Internal only. No client portal Recurring Review.

## 46. Tenancy

`customer_id` on Schedule/Run is tenant authority. Brand/Asset hierarchy validated server-side. Browser customer/brand context not trusted as write authority.

## 47. Privacy

Operator notes stored on run items; Evidence payload omits notes; Activity omits note bodies. No provider payload expansion.

## 48. Performance

Work presentation constrained (`limit` defaults). Eager-load schedule/playbook/items relations on detail. Due query indexed on `(status, next_due_at)`. Occurrence uniqueness prevents duplicate Runs.

## 49. Security / Provider Boundary

Zero provider calls from Recurring Review services (`Http::assertNothingSent` in tests). No external write actions. Evidence is operator observation only.

## 50. Notifications Boundary

No notification engine, bell fan-out, or email cadence in Prompt 46. Activity records only.

## 51. Recommendation / Approval / QA Boundary

Recurring Review never auto-creates Recommendation, Approval, or QA review. Task from review is independent of Approval/QA requirement policy.

## 52. Business Outcome Boundary

No Business Outcome create/link/causation. Summary counts are operational tallies only.

## 53. Files

No Recurring Review file attachment surface in Prompt 46. Task files unchanged; no physical duplication into review tables.

## 54. Tests

`tests/Feature/RecurringReviews/RecurringReviewProductionPersistenceTest.php` + AgencyExecutionSystem / Client Value updates for Work path. Covers tables, cadence required, materialize idempotency, outcomes XOR, task spam link, pause/resume without materialize-on-due-read, Work aggregate REAL ids, archived Playbook block, check update history safety.

## 55. Reality Matrix

See Capability Reality updates in `docs/implementation/MILESTONE_5_PANEL_FREEZE.md` and matrices below. Recurring Review Domain/Persistence/Schedule/Run/Checks/Outcomes/Evidence/Finding/Opportunity/Task source/Work projection: **REAL**. Demo fallback on production Work: **NONE**. Automatic scheduler: **NOT YET (Prompt 61)**. Notification engine: **NO**.

## 56. Prompt 47 Handoff

Downstream consumers may read Recurring Review Runs/items as operational context. Do not invent Review = Business Outcome, Review = QA, or automatic Recommendation from review outcomes unless a later prompt defines it.

## 57. Prompt 61 Handoff

Automatic occurrence materialization / human-controlled scheduler may call `MaterializeRecurringReviewOccurrence` + `dueSchedules` / `next_due_at`. Must remain idempotent by `occurrence_key`. Must not catch-up-explode paused→resumed gaps. Collection scheduler (Prompt 62) remains a separate domain.

## 58. Definition of Done

Satisfied when Recurring Reviews are REAL DB Schedules/Runs/Items distinct from QA, Playbook-revision-safe, outcome-XOR-safe, Evidence/Finding/Opportunity/Task integrated without spam or silent resolves, Work production-backed without Demo `rr-*` history migration, scheduler-free in-process, and quality gates for this domain pass.

---

## MANDATORY MATRICES

### Frozen Review Audit Matrix

| Surface | Concept | Demo before | Production after | Decision |
|---|---|---|---|---|
| Work list `recurring_reviews` | Due/open Runs | `AgencyExecutionFixtures::recurringReviews()` | `RecurringReviewReadService::forWorkItemPresentation` | KEEP→REAL |
| Work detail complete/skip | Disposition | DemoState / fixture ids | `RecurringReviewUiActions` | KEEP→REAL |
| Brand Operations reviews | Brand list | fixtures filtered by brand | `forBrandPresentation` | KEEP→REAL |
| Dashboard due glance | Count | Demo open items | may still derive from Work aggregate | EVOLVE→REAL rows |
| Playbook “related reviews” | Knowledge helper | fixture filter by playbook_id | not Customer history | DEMO residual / non-authoritative |
| Filament RR resource | CRUD UI | none | none | NOT ADDED |
| Schedule create UI | Writer UX | none | service-only | NO UI REDESIGN |

### Existing Review Primitive Matrix

| Primitive | Location | Semantic | Decision |
|---|---|---|---|
| `AgencyExecutionFixtures::recurringReviews()` | Demo | DEMO_ONLY | RETIRE from Work production path; do not migrate as history |
| Demo `rr-*` work ids | fixtures | DEMO_ONLY | NOT production ids |
| `qa_reviews` | Prompt 44 | CANONICAL QA | KEEP — distinct |
| `approvals` | Prompt 44 | CANONICAL Approval | KEEP — distinct |
| Generic Review model | — | NONE | DO NOT invent |
| `recurring_review_*` tables | Prompt 46 | CANONICAL | CANONICAL |
| Playbook instructions | Prompt 45 | PLAYBOOK_INSTRUCTION / knowledge | DO NOT auto-convert to checks |

### QA vs Recurring Review Matrix

| Dimension | QA | Recurring Review |
|---|---|---|
| Subject | Task only | Customer / Brand / DigitalAsset scope via Schedule |
| Table | `qa_reviews` | `recurring_review_*` |
| Cadence | none | weekly/monthly/quarterly |
| Checklist | none structured in P44 | Schedule-owned check definitions |
| Playbook | optional future criteria | required binding + revision snapshot |
| Outcome | passed/failed/needs_changes | no_issue/finding/opportunity/task |
| Creates Finding/Opp | NO | YES (operator, DigitalAsset-scoped) |
| Creates Task | NO | YES (explicit task outcome / link) |
| Work row type | via Task projection / qa badges | `type=recurring_review` |

### Schedule / Run Matrix

| Object | Creates | Owns checks | Snapshots revision | Outcomes | Advances next_due |
|---|---|---|---|---|---|
| Schedule | cadence + scope + playbook | YES (definitions) | NO | NO | via due calculator |
| Run (materialize) | occurrence | snapshots active defs → items | YES | NO until complete check | NO |
| Complete check | — | — | — | YES | NO |
| Complete/skip run (scheduled) | — | — | — | summary | YES if schedule active |
| Manual run | — | — | YES | same | NO |

### Recurrence Matrix

| Cadence | Allowed | Silent default? | Advance |
|---|---|---|---|
| weekly | YES | NO | +1 week |
| monthly | YES | NO | +1 month no overflow |
| quarterly | YES | NO | +3 months no overflow |
| daily/custom cron | NO | — | — |
| missing cadence | REJECT `CADENCE_REQUIRED` | — | — |

### Scope Matrix

| scope_kind | brand_id | digital_asset_id | Evidence/Finding/Opp | Hierarchy check |
|---|---|---|---|---|
| customer | NULL | NULL | blocked (no asset) | brand/asset must be null |
| brand | NOT NULL | NULL | blocked (no asset) | brand.customer_id match |
| digital_asset | NOT NULL | NOT NULL | allowed | brand+asset hierarchy |

### Playbook Revision Matrix

| Event | playbook_id | playbook_revision_id | Historical runs |
|---|---|---|---|
| Schedule create | current Playbook | — | — |
| Materialize | copied from schedule | Playbook.current_revision_id | new run only |
| Later Playbook revise | schedule unchanged | past runs unchanged | retained |
| Playbook archived | schedule retained | materialize fails | history kept |

### Checklist Definition Matrix

| Source | Becomes RR check? | Classification |
|---|---|---|
| Explicit schedule `checks[]` | YES | CANONICAL |
| Playbook instructions | NO | PLAYBOOK_INSTRUCTION |
| Playbook knowledge / qa_guidance | NO | knowledge |
| QA criteria (P44) | NO | QA domain |
| updateChecks | new active rows; prior soft-retired | history-safe |

### Run Item State Matrix

| State | outcome_kind | Terminal | Blocks run complete if required? |
|---|---|---|---|
| pending | null | NO | YES |
| completed | set | YES | NO |
| skipped | null | YES | NO |
| not_applicable | null | YES | NO |

### Outcome Matrix

| Outcome | Creates Evidence* | Finding | Opportunity | Task | Activity |
|---|---|---|---|---|---|
| no_issue | YES if asset | NO | NO | NO | NO |
| finding | YES | YES (upsert see) | NO | NO | FINDING_RECORDED |
| opportunity | YES | NO | YES (redetect) | NO | OPPORTUNITY_RECORDED |
| task | NO (via task path) | NO | NO | create or link | TASK_CREATED / EXISTING_TASK_LINKED |
| skipped / n/a | NO | NO | NO | NO | NO |

\*Evidence requires DigitalAsset on Run.

### Outcome FK Matrix

| outcome_kind | finding_id | opportunity_id | task_id | Valid |
|---|---|---|---|---|
| null | null | null | null | YES |
| no_issue | null | null | null | YES |
| finding | set | null | null | YES |
| opportunity | null | set | null | YES |
| task | null | null | set | YES |
| finding + task | set | null | set | NO |
| any dual FK | — | — | — | NO |

### Review Evidence Matrix

| Field | Value |
|---|---|
| definition_id | `recurring_review.operator_observation` |
| source_module | `recurring-review` |
| provider HTTP | NONE |
| notes in payload | NO |
| Run digital_asset null | publish returns null |
| Collection Run | synthetic completed `Run` with metadata review_run_id/run_item_id |

### Finding Integration Matrix

| Rule | Behavior |
|---|---|
| origin | operator |
| fingerprint | `rr.check.{check_definition_id}` (or snapshot rule id) |
| digital_asset required | YES |
| direct resolve | NEVER |
| duplicate open | update last_seen_at |
| auto Task/Recommendation | NO |

### Opportunity Integration Matrix

| Rule | Behavior |
|---|---|
| origin | operator |
| rule_id | `rr.check.{id}` or snapshot |
| fingerprint | rule + semantic scope/check hash |
| magic score | NO |
| auto close | NEVER |
| auto Task/Recommendation | NO |

### Task Spam Prevention Matrix

| Scenario | forceCreateAnother | Result |
|---|---|---|
| Open Task same schedule+check+scope | false | link existing (`existing_linked`) |
| Open Task same schedule+check+scope | true | create new Task |
| No open Task | — | create Task (`created`) |
| Correlation by title | — | FORBIDDEN |

### Task Source Matrix

| source_kind | recommendation_id | client_request_id | recurring_review_run_item_id |
|---|---|---|---|
| recommendation | NOT NULL | NULL | NULL |
| client_request | NULL | NOT NULL | NULL |
| direct | NULL | NULL | NULL |
| recurring_review_check | NULL | NULL | NOT NULL |

### Review Task Lineage Matrix

| Link | Location | Rewrites primary FK? |
|---|---|---|
| Primary provenance | `tasks.recurring_review_run_item_id` | set on create only |
| Occurrence link | `recurring_review_run_item_task_links` | NO |
| Existing linked across runs | link row `existing_linked` | NO |

### Review Completion Matrix

| Action | Required items | Run status | Schedule next_due | Summary |
|---|---|---|---|---|
| completeRun | all terminal | completed | advance if scheduled+active | counts |
| skipRun | n/a | skipped | advance if scheduled+active | reason |
| UI completeReview | primary + fill no_issue | completed | via completeRun | yes |
| incomplete required | — | reject REQUIRED_ITEMS_INCOMPLETE | unchanged | — |

### Service Scope Matrix

| Operation | Reads scope | Mutates CustomerServiceScope |
|---|---|---|
| Schedule create applicability | YES | NO |
| Materialize context JSON | YES | NO |
| Complete outcomes | NO | NO |

### Playbook Applicability Matrix

| Check | On create | On materialize |
|---|---|---|
| Playbook active | required | archived → fail |
| current revision | required | required |
| service_match | required true | snapshotted |
| asset_type_compatible | required true | snapshotted |
| first Brand/Asset fallback | NO | NO |

### Activity Matrix

| Event | Constant | Brand required | Payload spam |
|---|---|---|---|
| Schedule created/updated/paused/resumed/ended | RECURRING_REVIEW_SCHEDULE_* | YES | fields/ids only |
| Review started/completed/skipped | RECURRING_REVIEW_* | YES | summary/reason |
| Finding/Opportunity recorded | REVIEW_*_RECORDED | YES | ids |
| Task created / linked | REVIEW_TASK_* | YES | ids |
| no_issue | — | — | NONE |

### Scheduler Boundary Matrix

| Mechanism | In Prompt 46? |
|---|---|
| `MaterializeRecurringReviewOccurrence` explicit call | YES |
| `dueSchedules` read | YES (no side effects) |
| Laravel `Schedule::` registration | NO |
| Cron / auto fan-out | NO (Prompt 61) |
| Collection scheduler | NO (Prompt 62) |

### Demo Migration Matrix

| Source | Deterministic Customer history? | Action | Guess actors/times? |
|---|---|---|---|
| `recurringReviews()` fixtures | NO | do not migrate | NO |
| Demo `rr-*` ids | NO | retire from WorkReadService | — |
| Production Schedules/Runs | YES (operator/service created) | canonical | — |
