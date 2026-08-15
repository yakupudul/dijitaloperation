# WORK / TASK DOMAIN ALIGNMENT

## 1. Purpose

Prompt 43 aligns Task persistence with the frozen Work product: **Task** is the single canonical persisted agency execution record; **Work** is an aggregate UI/read surface over Tasks. No second Work persistence domain.

## 2. Frozen Work Product Audit

| Surface | Persistence today (before → after) | Notes |
|---|---|---|
| Operations → Work (`TasksIndex`) | Demo tasks + prod Client Requests + Demo reviews/approvals → **prod Tasks** + prod Client Requests + Demo reviews/approvals | Approvals/QA/Reviews remain Demo until P44–46 |
| Customer Work | mixed → Task-backed rows for that Customer | via Work/Task filters |
| Brand Operations Work | Demo filter by brand name → Task `brand_id` + Brand-scope/Asset-scope | Customer-wide Tasks not auto-included |
| DigitalAsset Operations | Task rows with matching `digital_asset_id` | Brand-level Tasks not inferred |
| Dashboard Work cards | derived counts from Work list | Task-derived for task types |
| Recommendation detail Create Task | Filament real; Livewire was no-op → **both real** | Explicit only |
| Client Request Create Task | Prompt 42 bridge → converged into `CreateTask` | Preserved |
| Capture type=`task` | DemoState → **CreateDirectTask** | DIRECT source |

## 3. Existing Work Primitive Audit

| Primitive | Classification | Decision |
|---|---|---|
| `tasks` / `App\Models\Task` | CANONICAL_TASK | Keep; evolve scope/source |
| `AgencyExecutionFixtures::workItems()` | AGGREGATE_READ_MODEL (was Demo tasks) | Delegates to `WorkReadService` |
| `WorkReadService` | AGGREGATE_READ_MODEL | Canonical Work read |
| `DemoState` tasks / `DemoCatalog::tasksSeed` | DEMO_ONLY | Retired from production Work list |
| `works` table / Work model | NONE | Do not create |
| MetaAdsDatasetExecutor `$workItems` | UNRELATED collector paging | Untouched |

## 4. Existing Task Domain Audit

One Task row = one actionable agency execution unit with Customer tenant, typed scope, bounded source, status lifecycle, optional assignee/priority/due_date, optional Recommendation or Client Request lineage, Activity via `brand_context_activities`.

## 5. Existing Task Constraint Audit

| Constraint | Before | After |
|---|---|---|
| `customer_id` | NOT NULL | retained (tenant) |
| `brand_id` | NOT NULL | **nullable** with `scope_kind` |
| `digital_asset_id` | NOT NULL | **nullable** with `scope_kind` |
| Recommendation FK | nullable | retained |
| Client Request FK (P42) | nullable | retained + `source_kind` |
| First Brand/Asset fallback | none in services | none |

## 6. Canonical Execution Decision

Task = persistence. Work = aggregate read/UI. No WorkV2 / works table.

## 7. Task vs Work

Work Item ID = Task ID. Work status/assignee/scope/source = Task fields. Opening Work creates 0 Work DB rows.

## 8. Task Source vs Task Scope

**Source** = why the Task exists (RECOMMENDATION / CLIENT_REQUEST / DIRECT).  
**Scope** = where execution applies (CUSTOMER / BRAND / DIGITAL_ASSET).

## 9. Task Scope Ontology

`TaskScopeKind`: `customer` | `brand` | `digital_asset`.

## 10. Customer Scope

`customer_id` set; `brand_id` null; `digital_asset_id` null. Supported via Capture Direct Task without Brand.

## 11. Brand Scope

`customer_id` + `brand_id`; `digital_asset_id` null. **No fake DigitalAsset.**

## 12. DigitalAsset Scope

`customer_id` + `brand_id` + `digital_asset_id`. Hierarchy validated in `CreateTask`.

## 13. Existing Task Scope Migration

Migration `2026_08_14_190000_align_task_scope_and_source_architecture.php`:

1. Add nullable `scope_kind` / `source_kind` / `idempotency_key`
2. Backfill DIGITAL_ASSET when asset present; BRAND when brand without asset; CUSTOMER when neither
3. Backfill source from recommendation_id / client_request_id else DIRECT
4. Relax brand/asset nullability (SQLite rebuild with FKs; pgsql DROP NOT NULL + CHECK)

## 14. Scope Constraints

Application validation mandatory. PostgreSQL CHECK `tasks_scope_shape_check`. Invalid shapes throw `TaskScopeValidationException`.

## 15. Task Source Ontology

`TaskSourceKind`: `recommendation` | `client_request` | `direct`. No morphTo.

## 16. Recommendation Source

`source_kind=recommendation`, `recommendation_id` NOT NULL, `client_request_id` NULL.

## 17. Client Request Source

`source_kind=client_request`, `client_request_id` NOT NULL, `recommendation_id` NULL.

## 18. Direct Task Boundary

Frozen Capture supports type=`task` → DIRECT implemented. No fake Recommendation/Request.

## 19. Source Cardinality

One Recommendation → many Tasks. One Client Request → many Tasks. One Task → one primary source. No UNIQUE(recommendation_id) / UNIQUE(client_request_id).

## 20. Recommendation → Task

`CreateTaskFromRecommendation` → `CreateTask`. Explicit. Idempotent via `idempotency_key`. Does not copy Finding/Opportunity/Evidence FKs onto Task. Does not auto-change Recommendation status.

## 21. Client Request → Task

`CreateTaskFromClientRequest` delegates to `CreateTask`. Brand-scoped Requests may create Brand-scope Tasks without DigitalAsset. Explicit DIGITAL_ASSET without asset still `TARGET_SCOPE_REQUIRED`. Planned status transition preserved when allowed. Request owner **not** silently assigned.

## 22. Source Lineage

Task → Recommendation → Finding|Opportunity → Evidence.  
Task → Client Request.

## 23. Source / Scope Compatibility

Source Customer hard boundary. Brand-scoped source cannot target other Brand. Customer-wide source may target child Brand/Asset only via explicit selection. No first-asset narrowing.

## 24. Task Identity

Stable Task ID. Title/description not identity. Idempotency keys prevent double-submit; same source + different keys may create multiple Tasks.

## 25. Task Lifecycle

Statuses: open / in_progress / blocked / completed / cancelled (`App\Support\Tasks\TaskStatus`). Execution-only.

## 26. Recommendation / Task Lifecycle

Independent. Task DONE ≠ Recommendation implemented.

## 27. Request / Task Lifecycle

Independent except optional Planned on first Task create (existing P42 rule). Task DONE does not auto-close Request.

## 28. Assignee / Owner

`assignee_id` = execution owner. Request/Recommendation owners not auto-copied.

## 29. Priority

Existing enum critical/high/medium/low. No magic score.

## 30. Due Date

Optional. Never invented.

## 31. Task Scope Changes

Not exposed as a frozen UI command in this prompt; creation-time scope is authoritative. Future explicit command must validate source boundary + hierarchy + Activity.

## 32. Task Source Reassignment

Not supported. Source immutable on normal update.

## 33. Work Aggregate Read Architecture

`TaskReadService` → `WorkReadService` → Operations Work UI. Approvals/QA (P44), Playbooks (P45), and Recurring Reviews (P46) are production-backed on Work paths.

## 34. Work Filters

Frozen filters: view segments (my/all/tasks/client_requests/…), status, type. Source/scope available on Task presentation for future filters without IA redesign.

## 35. Customer Work

All Tasks for Customer (all scope kinds).

## 36. Brand Work

Brand-scope + DigitalAsset-scope Tasks for that Brand. Customer-wide Tasks excluded unless Brand UI explicitly includes them (currently excluded).

## 37. DigitalAsset Operations

Only Tasks with that `digital_asset_id`. No inference from source.

## 38. Activity

`TaskActivityRecorder` events: TASK_CREATED, TASK_UPDATED, TASK_STATUS_CHANGED, TASK_ASSIGNED, TASK_SCOPE_CHANGED, TASK_COMPLETED, TASK_CANCELLED. Customer-scope (null brand) skips brand_context_activities (table is brand-scoped). Payload omits full body/description.

## 39. Files

No change; reuse canonical Files if Task attachments already exist. Source files not auto-copied.

## 40. Service Scope Context

Never mutated by Task create. Outside-scope Client Request Tasks remain valid.

## 41. Goal / Offering Context

Reachable only via Recommendation → Finding/Opportunity lineage. No text inference.

## 42. Demo Retirement

Production Work task rows no longer read Demo task seeds. Capture Task writes production. Brand create-from-recommendation uses production service.

## 43. Legacy Work Convergence

No legacy `works` table found. Nothing to migrate.

## 44. Authorization

CreateTask / CreateTaskFromRecommendation / CreateTaskFromClientRequest require Admin or Team Member. Tenant boundaries enforced in `CreateTask`.

## 45. Tenancy

`customer_id` required. Brand must belong to Customer. Asset must belong to Brand.

## 46. Privacy

Activity payloads omit Request/Task body text. No provider credentials.

## 47. Performance

Task list eager-loads customer/brand/asset/assignee/recommendation/clientRequest. Counts use SQL groupBy. Work list capped; pagination available via `TaskReadService::paginate`.

## 48. Tests

`tests/Feature/WorkTask/WorkTaskDomainAlignmentTest.php` + updated Client Request / Task migration / Recommendation→Task suites.

## 49. Reality Matrix

See Prompt §344 expected column — Task Domain REAL/ALIGNED; Work persistence NONE; Recommendation→Task REAL; Client Request→Task REAL; Direct REAL; Approvals/QA REAL (P44); Playbooks REAL (P45); Recurring Reviews REAL (P46).

## 50. Prompt 44 Handoff

Approvals & QA still Demo residual in Work aggregate. Do not treat Demo approvals as Task sources.

## 51. Definition of Done

See Prompt §361 — satisfied when quality gate green and matrices below hold.

---

## FROZEN WORK AUDIT MATRIX

| Surface | Field/card/filter/action | Current source | Task-backed? | Demo? | Derived? | Persistent? | Prompt 43 target | Supported? | Reason |
|---|---|---|---|---|---|---|---|---|---|
| Operations Work | list rows type=task | TaskReadService | Y | N | N | Task | Task | Y | Aggregate |
| Operations Work | Client Request rows | ClientRequestReadService | N (Request) | N | N | ClientRequest | keep | Y | P42 |
| Operations Work | recurring review rows | DemoState | N | Y | N | N | P46 | residual | Deferred |
| Operations Work | approval rows | Demo | N | Y | N | N | P44 | residual | Deferred |
| Operations Work | glance counts | Work list | Y for tasks | partial | Y | N | Task aggregates | Y | Derived |
| Brand Work | task filter | Task brand_id | Y | N | N | Task | Task | Y | |
| Capture Task | create | CreateDirectTask | Y | N | N | Task | DIRECT | Y | Frozen capture |
| Recommendations createTask | Livewire | CreateTaskFromRecommendation | Y | N | N | Task | RECOMMENDATION | Y | Wired |
| Request createTask | UiActions | CreateTaskFromClientRequest | Y | N | N | Task | CLIENT_REQUEST | Y | Converged |

## WORK PRIMITIVE MATRIX

| Primitive | Model/table/file | Semantic | Writable? | Task dup? | Prod data? | Demo? | Canonical? | Decision | Migration | Reason |
|---|---|---|---|---|---|---|---|---|---|
| Task | tasks | execution | Y | N | Y | N | Y | keep | scope/source columns | Canonical |
| WorkReadService | service | aggregate | N | N | reads Tasks | residual reviews | Y for Tasks | keep | n/a | Aggregate |
| Demo tasks seed | DemoCatalog | demo | Y (session) | would dup | N | Y | N | retire from Work | stop reading | P43 |
| works | — | — | — | — | — | — | — | do not create | — | Forbidden |

## TASK CONSTRAINT MATRIX

| Constraint | DB? | App? | UI? | Was required? | Frozen needs? | Result | Risk | Test |
|---|---|---|---|---|---|---|---|---|
| Customer | Y | Y | Y | Y | Y | retained | low | WorkTask |
| Brand | was Y | Y by scope | optional | Y | Brand/Customer optional | nullable+kind | med | WorkTask |
| DigitalAsset | was Y | Y by scope | optional | Y | Brand/Customer optional | nullable+kind | med | WorkTask |
| Recommendation source | nullable FK | XOR | action | optional | Y | source_kind | low | WorkTask |
| Client Request source | nullable FK | XOR | action | optional | Y | source_kind | low | ClientRequest |
| Source exclusivity | pgsql CHECK | Y | — | new | Y | XOR | low | WorkTask |
| Assignee | nullable | optional | optional | N | N | unchanged | low | WorkTask |
| Priority | required string | enum | optional | Y | Y | unchanged | low | existing |
| Due date | nullable | optional | optional | N | N | no invent | low | existing |

## TASK SCOPE MATRIX

| Scope | customer_id | brand_id | digital_asset_id | Valid? | Meaning | Frozen use | Creation | Auth |
|---|---|---|---|---|---|---|---|---|
| CUSTOMER | NOT NULL | NULL | NULL | Y | Customer-wide | Capture without brand | CreateDirectTask | tenant |
| BRAND | NOT NULL | NOT NULL | NULL | Y | Brand-wide | Brand work / Request without asset | Direct/Request/Rec | tenant+brand |
| DIGITAL_ASSET | NOT NULL | NOT NULL | NOT NULL | Y | Asset execution | Default Rec/Request with asset | all paths | hierarchy |

## INVALID SCOPE MATRIX

| scope_kind | Customer | Brand | Asset | Valid? | Reason |
|---|---|---|---|---|---|
| customer | set | set | null | N | brand must null |
| customer | set | null | set | N | asset must null |
| brand | set | null | null | N | brand required |
| brand | set | set | set | N | asset must null |
| digital_asset | set | null | set | N | brand required |
| digital_asset | set | set | null | N | asset required |
| digital_asset | set | brandA | asset(brandB) | N | hierarchy |
| any | set | brand(other customer) | * | N | tenancy |

## TASK SOURCE MATRIX

| Source | Required | Forbidden | Customer | Brand boundary | Goal/Offering | Service | Multi-Task? | Prod? |
|---|---|---|---|---|---|---|---|---|
| RECOMMENDATION | recommendation_id | client_request_id | from source | no cross-brand | via Rec lineage | context only | Y | Y |
| CLIENT_REQUEST | client_request_id | recommendation_id | from request | no cross-brand if brand-scoped | only if request has | context only | Y | Y |
| DIRECT | neither | both FKs | explicit | explicit | none | none | Y | Y (Capture) |

## SOURCE / SCOPE MATRIX

| Source kind | Source scope | Allowed Task scope | Explicit target? | Default? | Cross-Brand? | Cross-Customer? | Reason |
|---|---|---|---|---|---|---|---|
| Recommendation Brand/Asset | brand via Finding/Asset | BRAND or DIGITAL_ASSET | optional brand scope | asset if present | N | N | boundary |
| Client Request Brand + Asset | brand+asset | DIGITAL_ASSET default | — | request asset | N | N | P42/43 |
| Client Request Brand no asset | brand | BRAND default; DIGITAL_ASSET needs explicit asset | for asset | brand | N | N | no first asset |
| Client Request Customer-wide | customer | CUSTOMER or explicit child Brand/Asset | yes for child | customer | N | N | explicit only |

## EXISTING TASK MIGRATION MATRIX

| Existing shape | Customer | Brand | Asset | Source | New scope | New source | Deterministic? | ID | Status | Action |
|---|---|---|---|---|---|---|---|---|---|---|
| full FKs + recommendation | Y | Y | Y | rec | DIGITAL_ASSET | RECOMMENDATION | Y | keep | keep | backfill |
| full FKs + client_request | Y | Y | Y | cr | DIGITAL_ASSET | CLIENT_REQUEST | Y | keep | keep | backfill |
| full FKs no source FK | Y | Y | Y | none | DIGITAL_ASSET | DIRECT | Y | keep | keep | backfill |
| brand no asset | Y | Y | N | * | BRAND | per FKs | Y | keep | keep | backfill |
| inconsistent hierarchy | * | * | * | * | — | — | N | keep | keep | LEGACY_SCOPE_INVALID (no auto-fix) |

## RECOMMENDATION → TASK MATRIX

| Rec state | Source | Customer | Brand | Asset ctx | Task scopes | Allowed? | Auto? | Task source | Multi? | Rec status change? |
|---|---|---|---|---|---|---|---|---|---|---|
| open/accepted/… | Finding XOR Opp | from brand | from brand | optional | BRAND / DIGITAL_ASSET | Y explicit | N | RECOMMENDATION | Y | N by default |

## CLIENT REQUEST → TASK MATRIX

| Request state | Scope state | Customer | Brand | Asset | Task scope | Allowed? | Auto? | Source | Multi? | Request auto? | Service Scope? |
|---|---|---|---|---|---|---|---|---|---|---|---|
| open/triaged/… | in/out | Y | usually Y | optional | BRAND or DIGITAL_ASSET | Y explicit | N | CLIENT_REQUEST | Y | Planned optional | N |

## SOURCE LINEAGE MATRIX

| Task source | → Rec | → Request | → Finding | → Opp | → Evidence | Copied to Task? |
|---|---|---|---|---|---|---|
| RECOMMENDATION | direct FK | — | via Rec | via Rec | via Finding/Opp | N (no FKs) |
| CLIENT_REQUEST | — | direct FK | — | — | — | N |
| DIRECT | — | — | — | — | — | N |

## TASK LIFECYCLE MATRIX

| Task state | Next | Rec mutate? | Request mutate? | Finding? | Opp? | Approval? | QA? | Outcome? | Activity |
|---|---|---|---|---|---|---|---|---|---|
| open | in_progress/blocked/cancelled | N | N* | N | N | N | N | N | STATUS |
| completed | — | N | N | N | N | N | N | N | COMPLETED |

\*Except optional Planned on create (P42).

## WORK AGGREGATE MATRIX

| Work UI concept | Task field | Derived? | Separate persist? | Filterable? | Sortable? | Demo before? | Real after? |
|---|---|---|---|---|---|---|---|
| id | id | N | N | — | — | Demo ids | Task id |
| status | status | N | N | Y | Y | Demo | Task |
| assignee | assignee | N | N | Y | — | Demo | Task |
| scope | scope_kind + FKs | N | N | Y | — | implicit | explicit |
| source | source_kind | N | N | Y | — | origin string | enum |
| counts | SQL/collection | Y | N | — | — | Demo mix | Task-derived |

## WORK SCOPE VISIBILITY MATRIX

| Surface | Customer Tasks | Brand Tasks | Asset Tasks | Inclusion | Ambiguity | Frozen behavior |
|---|---|---|---|---|---|---|
| Operations Work | Y | Y | Y | all Tasks | none | all |
| Customer Work | Y | Y | Y | customer_id | none | all under customer |
| Brand Operations | N (default) | Y | Y | brand_id | customer-wide | exclude customer-wide |
| DigitalAsset Ops | N | N | Y | digital_asset_id | brand-level | asset only |

## TASK IDENTITY / DEDUP MATRIX

| Component | Identity? | Dup guard? | Mutable? | Reason |
|---|---|---|---|---|
| Task ID | Y | — | N | PK |
| source kind+id | N | N unique | N | multi-task |
| title | N | N | Y | not identity |
| idempotency_key | — | Y unique | N | retry safety |

## SOURCE REASSIGNMENT MATRIX

| Current | New | Same Customer? | Same Brand? | History | Allowed? | Explicit? | Activity | History retained? |
|---|---|---|---|---|---|---|---|---|
| any | any | — | — | — | N | — | — | — |

## SCOPE CHANGE MATRIX

| Current → New | Same Customer? | Source OK? | Allowed now? | Explicit cmd? | Activity | Notes |
|---|---|---|---|---|---|---|
| Asset→Brand | Y | if source allows | not UI-exposed | future | required | no first-object |
| Brand→other Brand | Y | N if source brand-scoped | N | — | — | boundary |

## SERVICE SCOPE MATRIX

| Task source | Service context | Valid? | Blocked? | Scope changed? | Identity change? | Historical |
|---|---|---|---|---|---|---|
| outside-scope Request | outside | Y | N | N | N | snapshot |
| Rec | via Finding/Opp | Y | N | N | N | lineage |

## GOAL / OFFERING MATRIX

| Source | Goal lineage | Offering lineage | Copied? | Text inferred? | Task identity? | Work UI |
|---|---|---|---|---|---|---|
| Recommendation | via Finding/Opp | via Finding/Opp | N | N | N | optional later |
| Client Request | only if explicit | only if explicit | N | N | N | — |
| Direct | none | none | N | N | N | — |

## ACTIVITY MATRIX

| Event | Trigger | Actor | Task | Source | Scope | Payload | Source-side | Spam |
|---|---|---|---|---|---|---|---|---|
| TASK_CREATED | create | user | id | kind+id | kind | compact | CR has CLIENT_REQUEST_TASK_CREATED | low |
| TASK_STATUS_CHANGED | status svc (future/UI) | user | id | — | — | compact | N | low |

## LEGACY WORK CONVERGENCE MATRIX

| Legacy | Prod data? | Eq Task? | Deterministic map? | Task ID? | History | Writable after? | Action | Guess? |
|---|---|---|---|---|---|---|---|---|
| none | — | — | — | — | — | — | n/a | N |

## DEMO RETIREMENT MATRIX

| Fixture | Consumers before | After | Prod remains? | Test/demo remains? | Remove? | Reason |
|---|---|---|---|---|---|---|
| Demo task seeds in workItems | Operations Work | 0 | N | DemoState may still hold session tasks unused by Work | stop reading | P43 |
| DemoState::captureTask | Capture | CreateDirectTask | N | method may remain unused | optional later | |
| DemoState::createTaskFromRecommendation | BrandShow | production service | N | — | optional later | |

## DOMAIN BOUNDARY MATRIX

| Concept | Task relation | Primary source? | Same? | Task creates? | Auto mutate from Task? | Future |
|---|---|---|---|---|---|---|
| Recommendation | source FK | Y | N | N | N | — |
| Client Request | source FK | Y | N | N | Planned optional | — |
| Finding | via Rec | N | N | N | N | — |
| Opportunity | via Rec | N | N | N | N | — |
| Evidence | via Finding | N | N | N | N | — |
| Work | aggregate | N | N | N | N | — |
| Approval/QA/Playbook/Review | none | N | N | N | N | P44–46 |
| Business Outcome | none | N | N | N | N | later |

## CAPABILITY REALITY MATRIX (after Prompt 43)

| Capability | State |
|---|---|
| Task Domain | REAL / ALIGNED |
| Task Scope Kind | REAL |
| Recommendation → Task | REAL |
| Client Request → Task | REAL |
| Direct Task | REAL (Capture) |
| Work Aggregate | REAL |
| Work persistence | NONE |
| Approvals / QA | NOT YET (P44) |
| Playbooks | NOT YET (P45) |
| Recurring Reviews | REAL (P46) |
| Business Outcomes / AI | NOT YET |
