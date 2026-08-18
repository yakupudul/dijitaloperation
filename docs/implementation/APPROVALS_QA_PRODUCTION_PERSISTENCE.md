# APPROVALS & QA PRODUCTION PERSISTENCE

## 1. Purpose

Prompt 44 makes **Approval** and **QA** two distinct production persistence semantics, separate from Task execution status. Task remains the canonical execution object (Prompt 43). Work remains an aggregate UI. No workflow engine, Playbooks (Prompt 45), Recurring Reviews (Prompt 46), Business Outcomes, AI, or provider calls.

## 2. Frozen Approval Product Audit

Frozen surfaces inspected: Operations → Work (list filters `approvals`, `waiting_on_client`), Work detail (`type=approval`), Brand Operations approvals list, Activity (none dedicated), Dashboard (capacity only). Demo fixtures previously mixed a `type=qa` “approval” row into Approvals — retired.

## 3. Frozen QA Product Audit

Frozen surfaces: Work `qa_required` filter/glance, QA badge on Task work rows, Work detail “Approve QA”, knowledge-context `qa_guidance` text (not a structured checklist DB). No Filament QA resource. No magic score.

## 4. Existing Approval Primitive Audit

Demo: `AgencyExecutionFixtures::approvals()`, `DemoState::approval_states`, Livewire `approve` / `approveItem`. No production `approvals` table before Prompt 44. Recommendation `accepted`/`rejected` and Client Request disposition are **not** Approval.

## 5. Existing QA Primitive Audit

Demo: `DemoState::qa_states`, Work `qa_required`/`qa_status`, Livewire `approveQa`. Ambiguous Demo Approval row `type=qa` retired. No generic `Review` model reused (reserved for Prompt 46 Recurring Reviews).

## 6. Task Status Conflation Audit

Task statuses remain execution-only: `open`, `in_progress`, `blocked`, `completed`, `cancelled`. No `APPROVED` / `QA_PASSED` task status.

## 7. Recommendation Status Conflation Audit

`accepted` / `rejected` = operator disposition of a proposed action (Prompt 41). **Not** migrated to Approval.

## 8. Client Request Status Conflation Audit

Intake/disposition lifecycle (Prompt 42). **Not** Approval.

## 9. Canonical Approval Decision

Table `approvals`. One row = one approval round for a Task subject. Status ≠ decision. History preserved across rounds.

## 10. Canonical QA Decision

Table `qa_reviews`. One row = one QA verification round for a Task. Status ≠ result. History preserved.

## 11. Task vs QA vs Approval

| Concept | Question | Persistence |
|---|---|---|
| Task | What work was executed? | `tasks` |
| QA | Was work checked against quality criteria? | `qa_reviews` |
| Approval | Did an authorized human decide? | `approvals` |

No shared `review_status`. Task DONE ≠ QA PASS ≠ APPROVED ≠ Business Outcome.

## 12. QA Subject

**TASK only.** FK `qa_reviews.task_id`. No unrestricted morphTo. Finding/Opportunity/Evidence/Customer/Brand/DigitalAsset are not QA subjects.

## 13. Approval Subject

**TASK only** (`subject_kind=task`, FK `approvals.task_id`). Recommendation and Client Request are not Approval subjects.

## 14. Bounded Subject Architecture

Closed enums: `ApprovalSubjectKind::Task`. Real FK. Tenant (`customer_id`/`brand_id`) derived server-side from Task.

## 15. Approval Request vs Decision

Request creates `pending` round. Decision sets `decided` + `approved|rejected|changes_requested`. Pending has null decision.

## 16. Approval Lifecycle

`pending` → `decided` (with decision) | `cancelled`. Final decided rounds immutable; re-request creates a new round.

## 17. Approval Rounds

Multiple rounds per Task allowed. One active `pending` round per Task+kind. No `UNIQUE(task_id)`.

## 18. Approver / Requester / Creator

`requested_by`, `created_by` (User). Decision actor: `decided_by_actor_kind` ∈ `{internal_user, client_contact}` with exclusive User or CustomerContact FK. Cross-customer contact denied.

## 19. QA Review Lifecycle

`pending` → `in_review` → `completed` | `cancelled`. Completed immutable; re-review = new round.

## 20. QA Result

`passed` | `failed` | `needs_changes` only when `status=completed`. Pending/cancelled: result null.

## 21. QA Rounds

Multiple historical rounds. One active (`pending`|`in_review`) per Task. No `UNIQUE(task_id)`.

## 22. QA Reviewer

Internal User (`reviewer_id`). Not auto-copied from Task assignee. Self-review allowed by authorization (Admin/Team Member).

## 23. QA Criteria / Checklist

Frozen product has guidance text only — **no structured checklist persistence** in Prompt 44. Prompt 45 may supply Playbook criteria later. No magic QA score.

## 24. QA Item Result

N/A (no checklist items table).

## 25. QA Requirement Policy

No universal requirement. Explicit QA request / frozen “Approve QA” action. Projection `qa_required` = attention needed from latest applicable round (pending/in_review/failed/needs_changes/stale).

## 26. Approval Requirement Policy

No universal/heuristic requirement. Explicit request. Projection `approval_required` = pending current round.

## 27. Subject Revision / Currentness

`TaskReviewedStateFingerprint` = sha256(id, title, action, rationale, digital_asset_id, brand_id, scope_kind). Excludes assignee/priority/due. Material content change → current projection `stale`; historical rows unchanged.

## 28. QA Current Projection

`QaReadService` / `TaskReadService`: latest round by id; `is_current_for_subject`; UI `qa_status` maps passed→`approved`, pending→`ready`, stale→`stale`.

Prompt 43 Work aggregate note: Approvals/QA productionized in Prompt 44; Recurring Reviews productionized in Prompt 46.

## 29. Approval Current Projection

`ApprovalReadService` / Task/Work projections. Work list also lists pending Approvals as `type=approval` rows.

## 30. QA → Task Rework

Not auto. No hidden listener. Explicit Task transition only if product later defines it.

## 31. Approval → Task Rework

Same — no automatic Task reopen.

## 32. QA / Approval Independence

QA PASS does not create Approval. Approval does not create QA. Independent histories/actors.

## 33. Task Lifecycle Boundary

QA/Approval writes do not mutate Task status, Finding, Opportunity, or Business Outcome.

## 34. Recommendation Boundary

Source architecture unchanged. Acceptance ≠ Approval.

## 35. Client Request Boundary

Disposition ≠ Approval. Request→Task remains Prompt 42/43.

## 36. Finding / Opportunity Boundary

QA/Approval never resolve Finding or close Opportunity. Evidence remains required for Finding resolution.

## 37. Service Scope Boundary

No scope/billing/contract mutation.

## 38. Work Aggregate Integration

`WorkReadService` projects Task QA/Approval; pending Approvals as work rows; Recurring Review Runs via Prompt 46. No `works` table. Demo Approval fixtures removed from Work production path.

## 39. Activity

Canonical `BrandContextActivity`: `QA_REQUESTED|STARTED|COMPLETED|CANCELLED`, `APPROVAL_REQUESTED|APPROVED|REJECTED|CHANGES_REQUESTED|CANCELLED`. No note/checklist payload spam. Brand-scoped only when `brand_id` present.

## 40. Files

No frozen Approval/QA file attachment surface in Prompt 44. Task files unchanged; no physical duplication.

## 41. Legacy Migration

No deterministic production legacy Approval/QA booleans with actor+time existed. Demo fixtures **not** migrated. No fabricated actors/timestamps.

## 42. Demo Retirement

Production Work/Brand Approvals and Task QA projections use DB only. Demo `setApprovalState`/`setQaState` no longer drive WorkShow/TasksIndex actions. Residual Demo fixtures may remain for non-production catalog tests but are not production truth.

## 43. Authorization

Admin / Team Member for request/decide/complete. Client contact must match Approval `customer_id`.

## 44. Tenancy

Derived from Task subject on create. Browser customer/brand not trusted as authority.

## 45. Privacy

Notes stored on domain rows; Activity omits notes. No provider payload expansion.

## 46. Performance

Batch `latestByTaskIds` for Task list projections. Work Approvals queried with constrained pending list. History methods limited.

## 47. Tests

`tests/Feature/ApprovalsQa/ApprovalsQaProductionPersistenceTest.php` + updated `AgencyExecutionSystemTest` Approval/QA cases.

## 48. Reality Matrix

See Milestone 5 + section below.

## 49. Prompt 45 Handoff

Playbooks Persistence may supply reusable QA criteria templates. Prompt 44 remains compatible (no Playbook dependency). Do not use `Review` for Playbook QA.

## 50. Definition of Done

Satisfied when canonical QA/Approval domains are REAL, distinct from Task, historically round-safe, Demo-free on production Work paths, and quality gates pass.

---

## FROZEN APPROVAL AUDIT MATRIX

| Surface | Concept | Subject | Status | Decision | Requester | Approver | Client/internal | Requested/Decided | Notes | Action | Demo source | Production | Decision |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Work list | Pending approval row | Task | pending | — | User | — | kind | timestamps | optional | Open/Approve | fixtures | REAL | KEEP→REAL |
| Work detail | Approve | Task | pending→decided | approved | User | User/Contact | both | yes | optional | Approve | DemoState | REAL | KEEP→REAL |
| Brand ops | Approvals list | Task | any | any | User | — | kind | yes | — | list | fixtures | REAL | KEEP→REAL |
| Demo type=qa as approval | Misfiled QA | Task | — | — | — | — | — | — | — | — | fixtures | NONE | RETIRE |

## FROZEN QA AUDIT MATRIX

| Surface | Concept | Subject | Status/Result | Reviewer | Criteria | Notes | Re-review | Demo | Production | Decision |
|---|---|---|---|---|---|---|---|---|---|---|
| Work qa_required | Needs QA | Task | ready/failed/stale | User | guidance text only | optional | yes | DemoState | REAL | KEEP→REAL |
| Work Approve QA | Complete pass | Task | completed/passed | User | none structured | optional | new round | DemoState | REAL | KEEP→REAL |

## EXISTING APPROVAL PRIMITIVE MATRIX

| Primitive | Location | Semantic | Decision |
|---|---|---|---|
| Demo approvals fixtures | AgencyExecutionFixtures | DEMO_ONLY | RETIRE from Work |
| DemoState approval_states | DemoState | DEMO_ONLY | RETIRE from Work actions |
| Recommendation accepted | recommendations.status | STATUS_FIELD_ONLY / disposition | DO NOT MIGRATE |
| Client Request statuses | client_requests | disposition | DO NOT MIGRATE |
| approvals table | NEW | CANONICAL | CANONICAL |

## EXISTING QA PRIMITIVE MATRIX

| Primitive | Location | Semantic | Decision |
|---|---|---|---|
| DemoState qa_states | DemoState | DEMO_ONLY | RETIRE from Work actions |
| Work qa badges | WorkReadService | projection | EVOLVE→REAL |
| Recurring Review | production `recurring_review_*` | DISTINCT from QA | REAL — Prompt 46 |
| qa_reviews table | NEW | CANONICAL | CANONICAL |

## DOMAIN SEMANTICS MATRIX

| Concept | Question | Canonical | =Task? | =QA? | =Approval? | Auto mutate? |
|---|---|---|---|---|---|---|
| Task | Execution | tasks | — | NO | NO | NO |
| QA | Verification | qa_reviews | NO | — | NO | NO |
| Approval | Human decision | approvals | NO | NO | — | NO |
| Finding | Problem truth | findings | NO | NO | NO | NO by QA/Approval |
| Opportunity | Growth candidate | opportunities | NO | NO | NO | NO |
| Recommendation | Proposed action | recommendations | NO | NO | NO | NO |
| Client Request | Intake | client_requests | NO | NO | NO | NO |
| Work | Aggregate UI | none | N/A | projection | projection | NO |
| Business Outcome | Later domain | none yet | NO | NO | NO | NO |
| Activity | Audit trail | brand_context_activities | NO | NO | NO | records events |

## QA SUBJECT MATRIX

| Kind | Model | Allowed | FK | Tenant | Revision | Consumer |
|---|---|---|---|---|---|---|
| TASK | Task | YES | task_id | from Task | fingerprint | Work/Task reads |

## APPROVAL SUBJECT MATRIX

| Kind | Model | Allowed | FK | Status meaning | Revision | Consumer |
|---|---|---|---|---|---|---|
| TASK | Task | YES | task_id | approval round | fingerprint | Work/Brand |

## SUBJECT ARCHITECTURE MATRIX

| Domain | Types | FK strategy | Polymorph? | Tenant | Fingerprint | History-safe |
|---|---|---|---|---|---|---|
| QA | task | real FK | NO | derived | yes | yes |
| Approval | task | real FK + subject_kind | NO | derived | yes | yes |

## QA LIFECYCLE MATRIX

| State | Action | Next | Result | Task auto? | Approval auto? | Activity | Final? |
|---|---|---|---|---|---|---|---|
| — | request | pending | null | NO | NO | QA_REQUESTED | NO |
| pending | start | in_review | null | NO | NO | QA_STARTED | NO |
| pending/in_review | complete | completed | set | NO | NO | QA_COMPLETED | YES |
| pending/in_review | cancel | cancelled | null | NO | NO | QA_CANCELLED | YES |

## APPROVAL LIFECYCLE MATRIX

| State | Action | Next | Decision | Task auto? | QA mutate? | Activity | Final? |
|---|---|---|---|---|---|---|---|
| — | request | pending | null | NO | NO | APPROVAL_REQUESTED | NO |
| pending | decide | decided | set | NO | NO | APPROVED/REJECTED/CHANGES_REQUESTED | YES |
| pending | cancel | cancelled | null | NO | NO | APPROVAL_CANCELLED | YES |

## QA STATUS VS RESULT MATRIX

| Status | Result allowed | Final | Editable |
|---|---|---|---|
| pending | null only | NO | transition |
| in_review | null only | NO | transition |
| completed | passed/failed/needs_changes | YES | NO (new round) |
| cancelled | null | YES | NO |

## APPROVAL STATUS VS DECISION MATRIX

| Status | Decision | Valid | Final |
|---|---|---|---|
| pending | null | YES | NO |
| decided | approved/rejected/changes_requested | YES | YES |
| cancelled | null | YES | YES |

## QA REQUIREMENT MATRIX

| Context | Required? | Source | Magic? | Behavior |
|---|---|---|---|---|
| Any Task | NO by default | explicit request / UI | NO | optional QA |
| After request | attention projection | latest round | NO | qa_required true until pass/current |

## APPROVAL REQUIREMENT MATRIX

| Context | Required? | Source | Magic? |
|---|---|---|---|
| Any Task | NO | explicit request | NO |
| Pending round | projection | approvals.status | NO |

## QA ROUND MATRIX

| Scenario | Prior | New | History | Current |
|---|---|---|---|---|
| Fail then pass | failed #1 | passed #2 | both | #2 |
| Double request | pending | same active | one active | pending |

## APPROVAL ROUND MATRIX

| Scenario | Prior | New | History | Current |
|---|---|---|---|---|
| Changes then approve | changes_requested #1 | approved #2 | both | #2 |

## REVISION / CURRENTNESS MATRIX

| Change | Material? | Fingerprint? | Old QA historical | Current QA valid | Old Approval historical | Current Approval valid |
|---|---|---|---|---|---|---|
| title/action/rationale | YES | YES | YES | NO (stale) | YES | NO (stale) |
| digital_asset/brand/scope | YES | YES | YES | NO | YES | NO |
| assignee | NO | NO | YES | YES | YES | YES |
| priority/due | NO | NO | YES | YES | YES | YES |

## QA CHECKLIST MATRIX

| Criterion source | Snapshot | Playbook | Magic score |
|---|---|---|---|
| None in P44 | N/A | NOT IMPLEMENTED | NO |

## ACTOR MATRIX

| Role | Kind | Identity | Customer scoped |
|---|---|---|---|
| QA requested_by | internal User | users.id | via Task |
| QA reviewer | internal User | users.id | via Task |
| Approval requested_by | internal User | users.id | via Task |
| Approval decided_by user | internal_user | users.id | — |
| Approval decided_by contact | client_contact | customer_contacts.id | YES same customer |
| created_by | internal User | users.id | — |

## QA / APPROVAL INDEPENDENCE MATRIX

| QA | Approval | Valid | Auto transition |
|---|---|---|---|
| none + pending | YES | NO |
| passed + pending | YES | NO |
| passed + rejected | YES | NO |
| failed + pending | YES | NO |
| failed + approved | YES (policy allows) | NO |
| passed + none | YES | NO |
| none + approved | YES | NO |

## TASK LIFECYCLE BOUNDARY MATRIX

| Task | QA/Approval | Auto Task transition | Finding | Opportunity | Outcome |
|---|---|---|---|---|---|
| any | any | NO | NO | NO | NO |

## REWORK MATRIX

| Trigger | Explicit action | New Task? | History |
|---|---|---|---|
| QA fail / changes requested | none in P44 | NO | retained |

## WORK AGGREGATE MATRIX

| Field/filter | Source | Persisted on Work? | Demo before | Real after |
|---|---|---|---|---|
| qa_required/status | Task←QA | NO | DemoState | REAL |
| waiting_on_client | Approval | NO | Demo/status | REAL |
| type=approval rows | approvals | NO | fixtures | REAL |

## LEGACY APPROVAL MIGRATION MATRIX

| Source | Deterministic? | Action | Guess? | Demo? |
|---|---|---|---|---|
| Demo fixtures | NO | do not migrate | NO | YES — skip |
| Task approved boolean | N/A (absent) | none | NO | — |

## LEGACY QA MIGRATION MATRIX

| Source | Deterministic? | Action | Guess? |
|---|---|---|---|
| Demo qa_states | NO | do not migrate | NO |

## DEMO RETIREMENT MATRIX

| Fixture | Before | After | Production use |
|---|---|---|---|
| approvals() in Work | Work rows | not used by WorkReadService | 0 |
| qa_states in Work actions | Livewire | services | 0 |

## DOMAIN BOUNDARY MATRIX

| Concept | QA subject? | Approval subject? | Auto mutate by QA/Approval |
|---|---|---|---|
| Task | YES | YES | NO |
| Work | NO | NO | N/A |
| Recommendation | NO | NO | NO |
| Client Request | NO | NO | NO |
| Finding/Opportunity/Evidence | NO | NO | NO |
| Service Scope/Goal/Offering | NO | NO | NO |
| Playbook / Recurring Review / Business Outcome | NO | NO | NO / later prompts |

## CAPABILITY REALITY MATRIX (after Prompt 44)

| Capability | State |
|---|---|
| Approval Domain / Persistence / Subjects / Request / Decision / History / Rounds / Actor | REAL |
| QA Domain / Persistence / Subject / Review / Result / Reviewer / History / Re-review | REAL |
| QA Criteria (structured checklist) | NOT YET (guidance only; Playbooks P45) |
| QA/Approval Separation; Task separations; Subject revision awareness | REAL |
| Work QA/Approval projections | REAL |
| Demo fallback | NONE on production paths |
| Task DONE→QA / QA PASS→Approval / Approval→Business Outcome | NO |
| Playbooks | REAL (P45) |
| Recurring Reviews | REAL (P46; distinct from qa_reviews) |
| Business Outcomes / AI | NOT YET / NO |
