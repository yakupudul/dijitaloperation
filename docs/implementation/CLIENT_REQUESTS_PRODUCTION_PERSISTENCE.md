# CLIENT REQUESTS PRODUCTION PERSISTENCE

**Prompt:** 42  
**Status:** PASS  
**Branch:** `cursor/client-requests-production-persistence-ea01`  
**Base:** Prompt 41 HEAD `3ef49350518278f7bacd28c02a2315c680ff0df5`

## 1. Purpose

Make Client Request a first-class production domain: durable agency-input records with Customer/Brand scope, Service Scope awareness, intake vs current scope explainability, and an explicit Request→Task bridge — without redesigning the frozen product, without TaskV2, without automatic Service Scope expansion, and without conflating Request with Finding / Opportunity / Recommendation / Task.

## 2. Frozen Client Request Product Audit

Frozen surfaces audited:

| Surface | Component | Pre-42 source |
|---|---|---|
| Customer → Requests | `CustomerDetail` + blade | `DemoState::clientRequestsWithState()` |
| Brand → Ops Requests | `BrandShow` | DemoState filter by brand |
| Work index `client_requests` | `TasksIndex` via `AgencyExecutionFixtures::workItems()` | Demo seeds |
| Work detail | `WorkShow` type=`client_request` | `DemoState::findClientRequest` |
| Capture | `CaptureModal` type=`client_request` | `DemoState::captureClientRequest` |
| Dashboard / notifications | fixtures | Demo IDs (residual, not production truth) |

Demo fields (AgencyExecutionFixtures): title, description, customer_id/name, brand_id/name, asset/asset_type (labels only), service_code/label, source/source_label (meeting|email|whatsapp|phone), status, waiting_on_client, in_scope bool, owner_id/owner, due/due_key, priority, effort, linked_task_id, optional goal_title/offering/approval_id.

Statuses: `new`, `triaged`, `planned`, `waiting_on_client`, `in_progress`, `done`, `declined`.

Actions: triage, plan, wait, create_task, done, decline, open.

## 3. Existing Request Primitive Audit

| Primitive | Location | Decision |
|---|---|---|
| Demo session `client_requests` | `DemoState` | DEMO_ONLY — retired from production consumers |
| `AgencyExecutionFixtures::clientRequests()` | fixtures | DEMO_ONLY — not migrated |
| Production `ClientRequest` | **CREATED** Prompt 42 | CANONICAL |
| Ticket / InboxItem / Need | none | N/A |

No prior production ClientRequest model/table existed (`AgencyExecutionSystemTest` previously asserted absence).

## 4. Existing Task Primitive Audit

| Concept | Current | Prompt 42 |
|---|---|---|
| Model | `App\Models\Task` | reused |
| Customer | required | derived from Request |
| Brand | required | derived from Request |
| DigitalAsset | **required** NOT NULL | explicit target; no first-asset / fake asset |
| source/origin | nullable `recommendation_id` only | additive nullable `client_request_id` |
| Recommendation relation | yes | unchanged |
| Client Request relation | none → **added** | FK + idempotency key |
| Creation service | `CreateTaskFromRecommendation` | parallel `CreateTaskFromClientRequest` |

Prompt 43 owns broader Work/Task origin alignment. Prompt 42 does **not** introduce unrestricted `source_type`/`source_id` morphTo.

## 5. Canonical Client Request Decision

**CREATE** `App\Models\ClientRequest` + `client_requests` table. One canonical domain. No ClientRequestV2.

## 6. Request vs Recommendation

Request is agency input. Recommendation remains Finding XOR Opportunity (Prompt 41). Request is never a Recommendation source.

## 7. Request vs Task

Request = what the client asked. Task = what we execute. Request may exist with zero Tasks. Explicit Create Task action creates Task(s).

## 8. Request Identity

Stable surrogate `id`. Title/description are mutable and **not** identity. No title/description unique. No fuzzy/semantic dedup. Create idempotency via optional `idempotency_key`.

## 9. Customer Scope

`customer_id` required. Cross-customer Brand/contact/asset rejected.

## 10. Brand Scope

Frozen product is Brand-scoped. `brand_id` required. No first-Brand fallback. No Customer-wide Request without Brand in this Prompt (frozen Demo always had Brand).

## 11. DigitalAsset Applicability

Optional on Request. Operator may set explicitly; same Brand validated. Never guessed from text. Never first-asset fallback.

## 12. Requester vs Creator

- `customer_contact_id` → requester (`CustomerContact`, existing CRM primitive)
- `created_by_user_id` → MoxDOP operator
Distinct. No ContactV2.

## 13. Request Channel

Persisted as `channel` enum matching frozen Demo: meeting, email, whatsapp, phone, other. Provenance only. No inbox ingestion.

## 14. Request Content

title, description (strip_tags), effort, due_label / due_date, priority. No Goal/Offering inference fields written from text. Attachments: frozen Request UI has no Files — deferred; canonical Files reused if added later.

## 15. Lifecycle

Enum `ClientRequestStatus` with validated transitions. Terminal: `done`, `declined`. `in_progress` kept because frozen Demo allowed it (not invented from Task). Task lifecycle is independent except frozen create_task → `planned`.

## 16. Owner / Assignee

`owner_user_id` on Request. Task assignee defaults from Request owner (frozen Demo behavior) but is not continuously synced.

## 17. Priority

Explicit `critical|high|medium|low`. No magic score. No AI.

## 18. Service Relevance

Optional `service_definition_id` → Prompt 36 `ServiceDefinition`. Human-confirmed only. Never inferred from text. One Service per Request (MIXED unused).

## 19. Service Scope Awareness

`ClientRequestScopeResolver` reads canonical `CustomerServiceScope` (active|paused + brand applicability). States: `in_scope`, `outside_current_scope`, `unclassified`, `not_applicable`, `mixed` (reserved). Missing Service ≠ outside scope.

## 20. Intake Scope Snapshot

At create: `intake_scope_state`, `intake_scope_snapshot` JSON, `intake_scope_assessed_at`. Never rewritten when current scope later changes.

## 21. Current Scope Resolution

Resolver recomputes on read. Batched in list reads.

## 22. Outside-Scope Requests

Remain real, visible, not auto-declined/accepted, do not create/modify Service Scope, do not create Opportunity/Recommendation. Task creation still allowed with scope context in Task snapshot.

## 23. Request → Task Bridge

`CreateTaskFromClientRequest` + nullable `tasks.client_request_id`. Explicit only. No auto Task on Request create.

## 24. Task Target Validation

Customer/Brand must match Request. DigitalAsset required by Task schema: use Request asset if set, else require explicit `digital_asset_id`, else `TARGET_SCOPE_REQUIRED`. Request preserved.

## 25. Multiple Tasks

Supported. No unique(request_id). Presentation exposes `task_count` + latest `linked_task_id`.

## 26. Task Idempotency

`tasks.client_request_task_idempotency_key` unique. Same key → same Task. New key → new Task.

## 27. Request / Task Lifecycle Independence

Task start/complete/cancel does not rewrite Request (except create_task → planned per frozen Demo). Request close/decline does not delete Task.

## 28. Activity

`ClientRequestActivityRecorder` → `brand_context_activities`: CREATED, UPDATED, STATUS_CHANGED, SCOPE_CLASSIFIED, OWNER_CHANGED, TASK_CREATED. No full body duplication.

## 29. Files

Frozen Request UI has no attachments. No second attachment store. Canonical Files deferred.

## 30. Goal / Offering Boundary

No inference. Presentation leaves goal/offering null unless future explicit FK (not in Prompt 42).

## 31. Finding / Opportunity Boundary

Request create/task create create 0 Findings / Opportunities.

## 32. Recommendation Boundary

Request is not a Recommendation source. Prompt 41 XOR unchanged.

## 33. Task / Work Boundary

Bridge only. Prompt 43 owns broader alignment.

## 34. Application Services

`CreateClientRequest`, `UpdateClientRequest`, `ClientRequestScopeResolver`, `CreateTaskFromClientRequest`, `ClientRequestReadService`, `ClientRequestActivityRecorder`, `ClientRequestUiActions`.

## 35. Read Architecture

`ClientRequestReadService` presentation DTOs for Customer/Brand/Work. Pagination on `forCustomer`. Eager loads avoid N+1. `source_state=REAL`. No Demo fallback.

## 36. Write Architecture

Services reload by ID. Transactions for create+snapshot+activity and task+link+activity. Livewire calls services only.

## 37. Frozen UI Migration

CustomerDetail / BrandShow / TasksIndex / WorkShow / CaptureModal wired to production. Empty = empty. Errors flash; no Demo fallback.

## 38. Demo Retirement

Production consumers no longer read Demo Request seeds. `DemoState` Request helpers remain for residual Demo-only surfaces/tests but are not production truth.

## 39. Legacy Migration

No production Request rows existed. Demo fixtures are not migrated. Idempotent no-op.

## 40. Authorization

Create Task gated by Admin/Team Member roles (same pattern as Recommendation→Task). Tenancy validated server-side.

## 41. Tenancy

Customer/Brand/Asset/Contact/Service consistency enforced in writers.

## 42. Privacy

Request body treated as tenant-confidential. Activity omits description/body. No Gmail/WhatsApp/Meta ingestion.

## 43. Performance

List eager-loads relations; scope batch by customer; task counts via loaded relation.

## 44. Tests

`tests/Feature/ClientRequests/ClientRequestProductionPersistenceTest.php` + updated `AgencyExecutionSystemTest`.

## 45. Reality Matrix

See §324 below / Milestone 5 update.

## 46. Prompt 43 Handoff

Broader Work/Task alignment for Recommendation + Client Request + other origins. Optional Task DigitalAsset softening if product requires Brand-level Tasks without asset. Do not invent TaskV2 here.

## 47. Definition of Done

All Prompt 42 invariants satisfied (see final report).

---

## FROZEN REQUEST AUDIT MATRIX

| Surface | Demo source | Fields | Status | Priority | Requester | Owner | Service | Scope | DigitalAsset | Task | Actions | After P42 | Decision |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Customer Requests | DemoState | title/status/scope/owner/due | demo enum | yes | no FK | yes | code label | in_scope bool | label | linked_task_id | triage/plan/create/open | DB | EVOLVE |
| Brand Requests | DemoState | list | — | — | — | — | — | badge | — | — | open | DB | EVOLVE |
| Work client_requests | fixtures | work-item shape | yes | yes | — | yes | label | in_scope | label | linked | triage/plan/create | DB | EVOLVE |
| WorkShow | DemoState | full | yes | yes | — | yes | — | — | — | create_task | full | DB | EVOLVE |
| Capture | DemoState | title/desc/source/priority/due | new | yes | — | — | — | default true | — | 0 | save | DB | EVOLVE |

## EXISTING REQUEST PRIMITIVE MATRIX

| Primitive | Model/table | Meaning | Cust | Brand | Asset | Service | Requester | Status | Task | Activity | Prod? | Demo? | Canonical? | Decision |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Demo client_requests | session | demo inbox | Y | Y | label | code | N | Y | linked id | demo event | N | Y | N | DEMO_ONLY |
| ClientRequest | client_requests | production | Y | Y | opt FK | FK | contact FK | enum | tasks FK | brand_context | Y | N | Y | CREATE |

## TASK AUDIT MATRIX

| Concept | Field | Required? | Semantics | Request compat | P42 change | P43 deferred |
|---|---|---|---|---|---|---|
| Customer | customer_id | Y | tenant | must match | derive | — |
| Brand | brand_id | Y | tenant | must match | derive | — |
| DigitalAsset | digital_asset_id | Y | target | optional on Request | explicit validate | maybe soften |
| status | status | Y | task life | independent | none | — |
| assignee | assignee_id | N | executor ≠ request owner sync | default from owner | copy default | — |
| priority | priority | Y | — | copy default | copy | — |
| due | due_date | N | — | no invent | optional | — |
| source | recommendation_id | N | rec origin | — | + client_request_id | unify origins |
| Recommendation | recommendation_id | N | — | unchanged | none | — |
| Client Request | client_request_id | N | P42 bridge | — | ADDED | broader graph |

## REQUEST IDENTITY MATRIX

| Component | Canonical ID? | Mutable? | Dedup? | Historical? |
|---|---|---|---|---|
| Request ID | YES | NO | N/A | YES |
| title | NO | YES | NO | NO |
| description | NO | YES | NO | NO |
| requester | NO | YES | NO | NO |
| channel | NO | YES | NO | NO |
| Customer | scope | NO (FK) | NO | YES |
| Brand | scope | NO (FK) | NO | YES |
| DigitalAsset | optional | YES | NO | YES |
| created_at | NO | NO | NO | YES |

## REQUEST LIFECYCLE MATRIX

| State | Allowed actions | Next | Task required? | Scope effect? | Activity? | Terminal? |
|---|---|---|---|---|---|---|
| new | triage/plan/wait/decline/done/create_task | triaged/planned/waiting/declined/done | N | N | Y | N |
| triaged | plan/wait/progress/decline/done/create_task | … | N | N | Y | N |
| planned | wait/progress/decline/done/create_task | … | N | N | Y | N |
| waiting_on_client | plan/progress/decline/done | … | N | N | Y | N |
| in_progress | wait/plan/decline/done | … | N | N | Y | N |
| done | — | — | N | N | Y | Y |
| declined | — | — | N | N | Y | Y |

## REQUESTER / CREATOR MATRIX

| Scenario | requested_by | created_by | channel | Contact required? | Valid? |
|---|---|---|---|---|---|
| Contact + operator | CustomerContact | User | whatsapp/email/… | N | Y |
| Operator only | null | User | meeting | N | Y |
| Portal direct | — | — | — | — | NOT IMPLEMENTED |

## SERVICE SCOPE MATRIX

| Request service | Active scope? | Current state | Valid Request? | Auto Task? | Scope changed? | Intake | Current |
|---|---|---|---|---|---|---|---|
| classified + active | Y | in_scope | Y | N | N | in | in |
| classified + paused | Y (paused counts) | in_scope | Y | N | N | in | in |
| classified + ended | N | outside | Y | N | N | outside | outside |
| classified + absent | N | outside | Y | N | N | outside | outside |
| unclassified | — | unclassified | Y | N | N | unclass | unclass |

## SCOPE STATE MATRIX

| State | Meaning | Service known? | Coverage? | Hidden? | Auto-decline? | Auto-task? | Operator accept? |
|---|---|---|---|---|---|---|---|
| in_scope | covered | Y | Y | N | N | N | Y |
| outside_current_scope | classified, uncovered | Y | N | N | N | N | Y |
| unclassified | no service | N | — | N | N | N | Y |
| not_applicable | reserved | — | — | N | N | N | Y |
| mixed | reserved multi | multi | partial | N | N | N | Y |

## INTAKE VS CURRENT SCOPE MATRIX

| Scenario | Intake | Current | ID changes? | History | Impact |
|---|---|---|---|---|---|
| outside → later active | outside | in_scope | N | intake retained | operator sees both |
| active → later ended | in | outside | N | retained | — |
| unclassified → classified | unclass | depends | N | intake unclass; activity on classify | — |

## REQUEST → TASK MATRIX

| Request state | Scope | Asset known? | Target required? | Can create? | Auto? | Request auto status | Relation | Activity |
|---|---|---|---|---|---|---|---|---|
| any non-blocking | any | Y | satisfied | Y | N | → planned if allowed | FK | TASK_CREATED |
| any | any | N | Y | N TARGET_SCOPE | N | unchanged | none | none |
| declined terminal | — | — | — | Y if not blocked by policy | N | planned only if transition allows | FK | — |

## TASK TARGET MATRIX

| Request asset | Explicit target | Task requires asset? | Selected | Valid? | Fallback? |
|---|---|---|---|---|---|
| set | — | Y | request asset | Y if same brand | N |
| none | set | Y | explicit | Y if same brand | N |
| none | none | Y | none | N | N |
| set | foreign | Y | — | rejected | N |

## REQUEST / TASK LIFECYCLE MATRIX

| Request | Task | Auto Request? | Auto Task? | Allowed? | History |
|---|---|---|---|---|---|
| accepted/planned + created | open | planned on create | N | Y | link kept |
| planned + in_progress | in_progress | N | N | Y | — |
| planned + completed | completed | N | N | Y | — |
| closed + open task | open | N | N | Y | task kept |
| declined + historical task | any | N | N | Y | task kept |

## MULTIPLE TASK MATRIX

| Request | Task A | Task B | Allowed? | Model | Unique? | Use |
|---|---|---|---|---|---|---|
| R1 | T1 | T2 | Y | client_request_id | NO | decomposition |

## DOMAIN BOUNDARY MATRIX

| Concept | Same as Request? | Auto from Request? | Created by Request P42? |
|---|---|---|---|
| Evidence | N | N | 0 |
| Finding | N | N | 0 |
| Opportunity | N | N | 0 |
| Recommendation | N | N | 0 |
| Service Scope | N | N | 0 |
| Goal/Offering | N | N | 0 |
| Task | N | only explicit action | yes via bridge |
| Approval/QA/Playbook | N | N | 0 |
| Activity | N | meaningful events | yes |

## ACTIVITY MATRIX

| Event | Trigger | Actor | Task ref? | Scope summary? | Body? | Spam |
|---|---|---|---|---|---|---|
| CREATED | create | operator | N | current state | N | low |
| UPDATED | meaningful edit | operator | N | N | N | guarded |
| STATUS_CHANGED | transition | operator | N | N | N | low |
| SCOPE_CLASSIFIED | service set | operator | N | Y | N | low |
| OWNER_CHANGED | owner change | operator | N | N | N | low |
| TASK_CREATED | create task | operator | Y | at creation | N | low |

## LEGACY MIGRATION MATRIX

| Type | Prod? | Action |
|---|---|---|
| Demo fixtures | N | NOT MIGRATED |
| Prior production rows | none | N/A |

## DEMO RETIREMENT MATRIX

| Fixture/helper | Before | After | Prod use? | Remove? |
|---|---|---|---|---|
| DemoState client request store | Customer/Work/Capture | residual only | 0 | keep for other Demo |
| AgencyExecutionFixtures::clientRequests | Work merge | unused by Work | 0 | keep seeds unused |
| Production ClientRequest | none | all Request surfaces | YES | — |

---

## REALITY MATRIX (Prompt 42)

| Capability | State |
|---|---|
| Evidence / Findings / Opportunities / Recommendations | REAL |
| Service Scope / Goals / Offerings | REAL |
| Client Request Domain / Persistence / Identity | REAL |
| Customer / Brand Scope | REAL |
| DigitalAsset Applicability | REAL (optional) |
| Requester Attribution | REAL (CustomerContact) |
| Lifecycle / Owner / Priority / Service Relevance | REAL |
| Current + Intake Scope | REAL |
| Outside-Scope Requests | REAL / preserved |
| Request→Task / Multi-Task / Target Validation | REAL |
| Request/Task Lifecycle Independence | REAL |
| Request Activity | REAL |
| Request Files | NOT YET (frozen UI absent) |
| Demo fallback | NONE |
| Request→Recommendation / Opportunity / Service auto | NO |
| Work / Task Alignment | PARTIAL — Prompt 43 |
| Approvals / QA / AI | NOT YET |
