# ACTIVITY NOTIFICATION PRODUCTION PERSISTENCE

## STATUS: PASS (implementation + this doc)

**Prompt:** 47  
**Canonical path:** `docs/implementation/ACTIVITY_NOTIFICATION_PRODUCTION_PERSISTENCE.md`  
**Depends on:** Prompt 46 Recurring Review (and prior domain writers for Finding / Opportunity / Recommendation / Task / QA / Approval / Client Request)

---

## 1. Purpose

Prompt 47 productionizes frozen Demo Activity + Notifications into a durable, preference-aware attention stack:

```text
Domain transaction
  → DomainEventEmitter::emit (same DB transaction)
    → domain_events row (idempotent)
    → MeaningfulEventProjector
      → ActivityProjector  (brand_context_activities, linked by domain_event_id)
      → NotificationProjector (user_notifications, recipient-specific)
```

Three concepts stay separate: **Domain Event** (immutable fact), **Activity** (human timeline projection), **Notification** (recipient-specific in-app attention). No Kafka/outbox V2, no external delivery, no scheduler (Prompt 61), no AI.

## 2. Frozen Activity Product Audit

Surfaces inspected: Operations → Activity (`ActivityIndex`); Brand/module residual activity tabs; domain `*ActivityRecorder` writers into `brand_context_activities`; Dashboard/ops glances that previously implied Demo timeline noise. No ActivityV2 table. No Filament Activity resource redesign in this prompt.

## 3. Frozen Notification Product Audit

Surfaces inspected: header `NotificationBell`; Settings → Notifications preference list; Demo `DemoNotificationFixtures` / `DemoState` bell fallback; Laravel `notifications` table + `Notifiable` trait (present, unused as production canonical). Email/push/Slack/WhatsApp/SMS channels not delivered.

## 4. Existing Activity Primitive Audit

Pre-existing canonical timeline store: `brand_context_activities` (brand-scoped, morph `subject_type`/`subject_id`, event string, optional actor). Domain recorders (`FindingActivityRecorder`, `TaskActivityRecorder`, etc.) continue as legacy/local audit writers. Prompt 47 **reuses** this table — adds `domain_event_id` (unique), `customer_id`, `digital_asset_id`, `actor_kind`, `occurred_at` — and projects meaningful Domain Events into it. No ActivityV2 / ActivityFeed / TimelineEvent rename.

## 5. Existing Notification Primitive Audit

Demo: `DemoNotificationFixtures` + `DemoState::demo_notifications` when DB empty. Laravel: `Illuminate\Notifications\Notifiable` on `User` and stock `notifications` migration — **not** production path. Prompt 47 introduces `user_notifications` + `notification_preferences` as canonical in-app store. Laravel database notifications remain unused for production attention.

## 6. Existing Event Path Audit

Before Prompt 47: domain services wrote Activity recorder rows directly; no durable Domain Event table; no shared projector; bell fell back to Demo. After: writers that matter emit via `DomainEventEmitter` inside the same domain transaction; Activity/Notification are projections of that fact.

## 7. Canonical Three-Concept Decision

| Concept | Question | Persistence |
|---|---|---|
| Domain Event | What meaningful fact happened? | `domain_events` |
| Activity | What should humans see on the timeline? | `brand_context_activities` (REUSED) |
| Notification | Who should be nudged in-app? | `user_notifications` |

Activity ≠ Notification ≠ Domain Event. Zero notifications with a visible Activity (or orphan Domain Event on customer-only scope) is valid.

## 8. Domain Event Model

`App\Models\DomainEvent` / `domain_events`: `event_type`, unique `idempotency_key`, `actor_kind`, optional `actor_user_id`, optional customer/brand/digital_asset, `subject_kind` + `subject_id`, optional `payload`, optional `correlation_id` / `causation_event_id`, `occurred_at`, `projection_status` (`pending` \| `projected` \| `failed`).

## 9. Bounded Domain Event Types

`DomainEventType` enum (facts, not commands):

| Type | Category |
|---|---|
| `FINDING_CREATED` | intelligence |
| `OPPORTUNITY_CREATED` | intelligence |
| `RECOMMENDATION_ACCEPTED` | commercial |
| `CLIENT_REQUEST_CREATED` | client_request |
| `TASK_ASSIGNED` | execution |
| `TASK_COMPLETED` | execution |
| `QA_PASSED` / `QA_FAILED` / `QA_NEEDS_CHANGES` | quality |
| `APPROVAL_APPROVED` / `APPROVAL_REJECTED` / `APPROVAL_CHANGES_REQUESTED` | approval |
| `RECURRING_REVIEW_COMPLETED` | review |

## 10. DomainEventEmitter

`App\Services\DomainEvents\DomainEventEmitter::emit` is the only production write boundary for Domain Events. Validates input, resolves idempotency key, inserts `projection_status=pending`, then `ensureProjected`. Safe inside `DB::transaction`. Unique races re-load existing row and project if needed.

## 11. Same-Transaction Durability

Emit + Activity + Notification projection run in the caller’s domain transaction (same DB). Commit keeps event + projections together. No outbox V2, no Kafka, no async fan-out job in Prompt 47.

## 12. Idempotency Key

Unique `idempotency_key`. Defaults are type-stable (e.g. `FINDING_CREATED:finding:{id}`, `TASK_COMPLETED:task:{id}`, `TASK_ASSIGNED:task:{id}:assignee:{id|none}`). Transition variants hash stable payload identity fields only (`assignee_id`, `from_status`, `to_status`, `status`, `result`, `decision`, `severity`, `transition`) — excludes volatile notes/timestamps. Caller may supply an explicit key.

## 13. Projection Status

Lifecycle: create as `pending` → projectors run → `projected`. Re-emit of an already-projected key returns the same row without re-writing Activity/Notification (unique constraints + projector existence checks). `failed` reserved in CHECK; Prompt 47 happy path uses pending→projected.

## 14. Crash Recovery / Re-emit

Crash after commit with `pending` (or partial projection): event row remains. Later `emit` with the same idempotency key calls `ensureProjected` and completes Activity/Notification projection. UniqueConstraint on `brand_context_activities.domain_event_id` and `(domain_event_id, recipient_user_id, notification_kind)` prevents duplicates.

## 15. MeaningfulEventProjector

`MeaningfulEventProjector` orchestrates `ActivityProjector` then `NotificationProjector` for one DomainEvent. Idempotent at each projector.

## 16. ActivityProjector

Projects DomainEvent → `BrandContextActivity` when policy says create Activity **and** `brand_id` is non-null. Skips (returns null) when `brand_id` is null — customer-scoped facts remain valid Domain Events with zero BCA rows. Never invents a “first brand” fallback.

## 17. No ActivityV2

`brand_context_activities` is the sole Activity store. Prompt 47 extends columns + unique `domain_event_id`; does not create ActivityV2 / TimelineEvent / FeedItem tables.

## 18. Activity Subject Kinds

`DomainEventSubjectKind`: `finding`, `opportunity`, `recommendation`, `client_request`, `task`, `qa_review`, `approval`, `playbook`, `recurring_review_run`. Mapped to Eloquent FQCN via `SubjectKindModelMap` for Activity `subject_type` / `subject_id`. Bounded enum — no open morph vocabulary on Domain Events.

## 19. Actor Kinds

`DomainEventActorKind`: `internal_user`, `system`, `client_contact`. Postgres CHECK on `domain_events.actor_kind`. Activity copies `actor_kind` + optional `actor_user_id`.

## 20. System Actor Rules

System Finding creation emits `actor_kind=system` with `actor_user_id=null`. Never fabricates an admin user as actor for system intelligence events. Self-suppression only applies when `actor_user_id` is set.

## 21. Activity Snapshot Safety

`ActivityProjector` stores a **safe presentation** payload only: event_type, subject ids, short title/summary, severity/status/priority, bounded counts. Never full confidential notes, request bodies, or secrets.

## 22. Activity Scope

Brand-scoped BCA requires `brand_id` (NOT NULL FK on existing table). Customer-only Domain Events (`brand_id` null) skip Activity write; `ActivityReadService` still surfaces orphan Domain Events so customer-scoped facts appear on the feed. Optional `customer_id` / `digital_asset_id` denormalized on Activity when present.

## 23. History Safety

Activity rows are historical projections. Subject morph is not cascade-deleted with subject lifecycle in Prompt 47 design — timeline remains history-safe. Domain Event ← Activity uses `domain_event_id` nullOnDelete for the FK; production writers do not delete Domain Events as part of subject delete.

## 24. Notification Canonical Table

`user_notifications`: `domain_event_id`, `recipient_user_id`, `notification_kind`, `subject_kind`/`subject_id`, optional customer/brand, `presentation` JSON, `read_at`, `archived_at`. Unique `(domain_event_id, recipient_user_id, notification_kind)`.

## 25. Not Laravel Database Notifications

Production path does **not** use `Notification::send`, database notification channels, or `User::notifications()` as canonical. `Notifiable` may remain on `User` for framework compatibility; Prompt 47 attention is `user_notifications` only.

## 26. NotificationPolicyRegistry

Decides: shouldCreateActivity (all registered DomainEventType → true when brand present), notificationKind mapping, preferenceKey mapping via `DomainEventType::preferenceKey()`.

## 27. NotificationRecipientResolver

Resolves recipients from **canonical relations only** (Task assignee, QA/Approval → task assignee, Recurring Review schedule owner, Client Request owner). No text inference, no role fan-out, no notify-all.

## 28. Self-Suppression

Default: actor (`actor_user_id`) is excluded from recipient list. Completing/assigning your own work creates Activity without self-notification.

## 29. Zero Recipients Valid

Empty recipient list is success. Finding / Opportunity / RecommendationAccepted default to `[]` — Domain Event + Activity may exist with **zero** `user_notifications`.

## 30. No Notify-All

No broadcast to all admins, all brand members, or all users. Preferences only gate already-resolved recipients; they never expand the audience.

## 31. NotificationProjector

For each resolved recipient with in-app preference enabled, creates `UserNotification` with presentation snapshot (`title_key`, `title`, `body_key`, `body_params`, `subject_label`). No `Mail::`, no HTTP, no Slack/WhatsApp/SMS.

## 32. Notification Kind Mapping

`NotificationKind` mirrors meaningful events (`finding_created`, `task_assigned`, `qa_passed`, …). One kind per projected notification row; unique with event + recipient.

## 33. Notification State

Unread: `read_at` null and `archived_at` null. Mark read sets `read_at` (idempotent). Mark all read scopes by `created_at <= before`. Archive soft-sets `archived_at` without mutating DomainEvent. Services: `NotificationReadService`, `NotificationWriteService`, `NotificationUiActions`.

## 34. Notification Preference Catalog

`NotificationPreferenceCatalog` frozen keys (12): `critical_finding`, `integration_failure`, `task_assigned`, `task_overdue`, `work_item_overdue`, `client_request_received`, `approval_waiting`, `qa_review_required`, `recurring_review_due`, `regression_observed`, `provider_authorization_issue`, `operation_failed`. Settings UI lists all; only wired Domain Events consume a subset today.

## 35. Preference Service

`NotificationPreferenceService`: default **in-app enabled = true** when no row; `setPreference` / `listForUser` merge catalog defaults with `notification_preferences`. Unknown keys rejected.

## 36. Email Flag Persisted Not Delivered

`email_enabled` is stored. Prompt 47 **never** delivers email (or any external channel). Flag is UI/persistence only for a future delivery prompt.

## 37. Spam Prevention

| Rule | Behavior |
|---|---|
| Idempotent Domain Event | One fact → one event key |
| Unique Activity per event | One BCA row per `domain_event_id` |
| Unique Notification per event/recipient/kind | No duplicate bells |
| Self-suppression | Actor not notified |
| Empty recipient policy | Finding/Opportunity/RecommendationAccepted → [] |
| Preference off | Skip insert for that user |
| Safe payload | No note/body spam in snapshots |
| Review completed | One notify to schedule owner (not per check outcome) |

## 38. Mandatory Event Wiring Inventory

| Event | Writer (emit site) |
|---|---|
| `FINDING_CREATED` | `FindingPersistenceService` (+ review check finding path) |
| `OPPORTUNITY_CREATED` | `OpportunityPersistenceService` |
| `RECOMMENDATION_ACCEPTED` | `UpdateRecommendation` |
| `CLIENT_REQUEST_CREATED` | `CreateClientRequest` |
| `TASK_ASSIGNED` | `CreateTask` (when assignee set) |
| `TASK_COMPLETED` | `TaskLifecycleService` |
| `QA_PASSED` / `FAILED` / `NEEDS_CHANGES` | `QaService` |
| `APPROVAL_APPROVED` / `REJECTED` / `CHANGES_REQUESTED` | `ApprovalService` |
| `RECURRING_REVIEW_COMPLETED` | `RecurringReviewRunService` |

## 39. Finding / Opportunity / RecommendationAccepted Policy

Recipients: **[]** by default. Activity still projects when `brand_id` present. System Finding uses `actor_kind=system`. RecommendationAccepted is a commercial Domain Event — **not** an Approval Domain Event.

## 40. Task Assigned / Completed Policy

Recipients: Task `assignee_id` (if set), minus actor. Preference key: `task_assigned`.

## 41. QA / Approval Policy

QA events: recipients = QA review’s Task assignee. Approval events: recipients = Approval’s Task assignee. Preference keys: `qa_review_required` / `approval_waiting`.

## 42. Recurring Review / Client Request Policy

`RECURRING_REVIEW_COMPLETED`: schedule `owner_user_id` once (not per finding/task outcome in summary). Preference: `recurring_review_due`. `CLIENT_REQUEST_CREATED`: request `owner_user_id`. Preference: `client_request_received`.

## 43. Activity Index UI

`App\Livewire\Demo\Operations\ActivityIndex` reads via `ActivityReadService` only — **no Demo fixtures fallback**. Filters: brand, customer, asset, actor (system/human), period. Empty list means genuinely empty.

## 44. Notification Bell UI

`App\Livewire\Demo\NotificationBell` reads/writes `user_notifications` via Read/UiActions — **no Demo fallback**. `demoItems` forced empty. Mark read / mark all read are production paths.

## 45. Settings Notifications UI

Settings notifications section persists via `NotificationPreferenceService` into `notification_preferences`. In-app toggles gate projection; email toggles persist without delivery.

## 46. Localization

Presentation stores `title_key` / `body_key` (`notifications.{kind}.title|body`) plus English `title` / `body_params` for immediate display. Full locale pack expansion is not required for Prompt 47 PASS; keys are stable for later i18n.

## 47. Privacy

Snapshots omit confidential Task/Client Request bodies, approval/QA free-text notes beyond short titles, and secrets. Recipient lists are relation-derived, never scraped from notes. No external channel leakage.

## 48. Legacy Activity Recorders

Domain `*ActivityRecorder` classes remain for local lifecycle audit strings not yet elevated to DomainEvent (or parallel history). Prompt 47 mandatory transitions use DomainEventEmitter. `ActivityReadService` unifies domain-event-backed rows and legacy rows (`domain_event_id` null).

## 49. Legacy Laravel / Demo Notification Path

`DemoNotificationFixtures` / DemoState mark-read helpers are retired from production bell path. Laravel `notifications` table is not the canonical attention store. Do not dual-write Demo + `user_notifications` on production emit.

## 50. Demo Retirement

| Surface | Before | After |
|---|---|---|
| Activity Index | Demo timeline when empty | `ActivityReadService` only |
| Notification Bell | Demo fixtures when DB empty | `user_notifications` only |
| Settings notifications | Session/demo toggles | `notification_preferences` |

## 51. External Delivery Boundary

**NOT IMPLEMENTED:** email, push, Slack, WhatsApp, SMS, webhooks, digest cron. In-app only. Email preference flag is persistence-only.

## 52. No Scheduler / No AI

No Laravel Schedule registration for notification cadence (Prompt 61 handoff elsewhere). No AI-generated activity copy, ranking, or recipient inference. No notify-all “ops digest” job.

## 53. Domain Boundary

In scope: Domain Event durability, Activity projection reuse, in-app Notifications, preferences, UI wiring for Activity/Bell/Settings, mandatory emit sites listed above.  
Out of scope: SaaS/client portal notify, external write actions, marketplace, Kafka/outbox V2, Business Outcomes causation, AI Skills execution, automatic Recurring Review scheduler (Prompt 61), collection scheduler (Prompt 62).

## 54. Tests

- `tests/Feature/ActivityNotifications/ActivityNotificationServicesTest.php` — tables, idempotency, brand-null Activity skip, self-suppression, preferences, read/write/UiActions, Activity read unification.  
- `tests/Feature/ActivityNotifications/ActivityNotificationDomainWiringTest.php` — domain transitions → DomainEvent → Activity/Notification; Mail/Http nothing sent; RecommendationAccepted ≠ Approval.

## 55. Reality Matrix

| Capability | State | Notes |
|---|---|---|
| Domain Events | **REAL** | `domain_events` + `DomainEventEmitter` |
| Activity timeline | **REAL / CONVERGED** | Reuses `brand_context_activities`; Activity Index DB-backed |
| In-app Notifications | **REAL** | `user_notifications`; Bell DB-backed |
| Notification preferences | **REAL** | `notification_preferences`; Settings persist |
| External delivery (email/push/Slack/WhatsApp/SMS) | **NOT IMPLEMENTED** | Email flag stored only |
| Laravel database notifications as canonical | **NO** | Unused for production path |
| ActivityV2 | **NO** | Not created |
| Kafka / Outbox V2 | **NO** | Same-transaction projection |
| Notify-all / role fan-out | **NO** | Relation recipients + self-suppression |
| Automatic notification scheduler | **NOT YET** | Prompt 61+ |
| AI in Activity/Notifications | **NO** | |
| Demo Activity/Bell fallback | **NONE** on production paths | |

See also Milestone 5 Capability Reality Matrix rows for Activity / Notifications (update to REAL / CONVERGED).

## 56. Prompt 48 Handoff

**External Skills Repo Audit.** Downstream may inventory external skill repositories / catalogs. Do not invent AI-authored Activity/Notification copy, skill-driven notify-all, or external delivery from Prompt 47 primitives unless a later prompt defines them. Domain Event / Activity / Notification separation remains load-bearing.

---

## Definition of Done

Key PASS criteria:

- Domain Event / Activity / Notification concepts are separate and durable as specified.
- Same-transaction emit + projection; idempotent keys; unique projection constraints; re-emit recovers pending.
- Mandatory events wired; Finding/Opportunity/RecommendationAccepted → [] recipients; Task/QA/Approval/Review/Client Request recipient rules hold; self-suppression default.
- Activity Index + Notification Bell + Settings preferences are production-backed with **no Demo fallback**.
- No external delivery, no notify-all, no scheduler, no AI, no ActivityV2, no Laravel DB notifications as canonical.
- Feature tests for services + domain wiring pass; Mail/Http assert nothing sent on emit paths.

---

## MANDATORY MATRICES

### Frozen Activity Audit Matrix

| Surface | Concept | Demo before | Production after | Decision |
|---|---|---|---|---|
| Operations → Activity | Human timeline | Demo/fixture rows | `ActivityReadService` | KEEP→REAL |
| Activity filters | Brand/customer/asset/actor/period | Demo filters | Same filters on DB | EVOLVE→REAL |
| Brand/module activity tabs | Local residual | Mixed Demo/module | Out of Prompt 47 rewrite unless already DB | LEAVE / non-authoritative if Demo |
| Filament Activity resource | CRUD | none | none | NOT ADDED |
| ActivityV2 | Parallel store | none | none | DO NOT CREATE |

### Frozen Notification Audit Matrix

| Surface | Concept | Demo before | Production after | Decision |
|---|---|---|---|---|
| NotificationBell | In-app attention | `DemoNotificationFixtures` when empty | `user_notifications` | KEEP→REAL |
| Mark read / mark all | Attention state | DemoState | `NotificationUiActions` | KEEP→REAL |
| Settings → Notifications | Preferences | Session/demo | `notification_preferences` | KEEP→REAL |
| Email delivery | External | none / fake | flag only | NOT DELIVERED |
| Push/Slack/WhatsApp/SMS | External | none | none | NOT IMPLEMENTED |

### Existing Activity Primitive Matrix

| Primitive | Location | Semantic | Decision |
|---|---|---|---|
| `brand_context_activities` | Core | CANONICAL timeline | REUSE + extend |
| `*ActivityRecorder` | Domain services | LEGACY/local audit | KEEP for non-elevated events; unify on read |
| ActivityV2 | — | NONE | DO NOT invent |
| Demo Activity fixtures | Demo | DEMO_ONLY | RETIRE from Activity Index production path |

### Existing Notification Primitive Matrix

| Primitive | Location | Semantic | Decision |
|---|---|---|---|
| `DemoNotificationFixtures` | Demo | DEMO_ONLY | RETIRE from Bell production path |
| DemoState notification helpers | Demo | DEMO_ONLY | RETIRE from Bell |
| Laravel `notifications` table | Framework | FRAMEWORK_ONLY | NOT canonical |
| `Notifiable` on User | Framework | UNUSED for prod path | DO NOT use as production attention |
| `user_notifications` | Prompt 47 | CANONICAL in-app | CANONICAL |
| `notification_preferences` | Prompt 47 | CANONICAL prefs | CANONICAL |

### Existing Event Path Matrix

| Path | Before P47 | After P47 | Decision |
|---|---|---|---|
| Domain writer → ActivityRecorder only | Common | Remains for some local events | LEGACY parallel |
| Domain writer → DomainEventEmitter | Missing | Mandatory transitions | CANONICAL |
| Demo event → Bell | Fallback | Removed | RETIRE |
| Laravel Notification::send | Unused/prod | Still unused | NO |

### Domain Event Matrix

| Field / concern | Rule |
|---|---|
| Immutability | Fact row; projections do not rewrite event payload |
| `idempotency_key` | Unique; type-stable defaults |
| `projection_status` | pending → projected |
| Actor | `internal_user` \| `system` \| `client_contact` |
| Subject | Bounded `DomainEventSubjectKind` + id |
| Scope | Optional customer/brand/digital_asset |
| Outbox/Kafka | NO |

### Event / Activity / Notification Scenario Matrix

| Scenario | DomainEvent | Activity | Notification |
|---|---|---|---|
| Task completed (other assignee) | 1 | 1 (if brand) | 1 to assignee |
| Task completed (self) | 1 | 1 | 0 (self-suppression) |
| Task assigned | 1 | 1 | 1 to assignee (if prefs on) |
| Finding created (system, brand) | 1 | 1 | 0 (policy []) |
| Finding created (no brand) | 1 | 0 | 0 |
| Opportunity created | 1 | 1 if brand | 0 |
| Recommendation accepted | 1 | 1 if brand | 0 |
| QA passed | 1 | 1 if brand | Task assignee |
| Approval approved | 1 | 1 if brand | Task assignee |
| Recurring review completed | 1 | 1 if brand | Schedule owner once |
| Client request created | 1 | 1 if brand | Owner |
| Preference in-app off | 1 | per Activity rules | 0 for that user |
| Duplicate emit same key | same row | no duplicate | no duplicate |

### Activity Subject Matrix

| subject_kind | Model | Activity subject_type | History-safe |
|---|---|---|---|
| finding | Finding | FQCN | YES |
| opportunity | Opportunity | FQCN | YES |
| recommendation | Recommendation | FQCN | YES |
| client_request | ClientRequest | FQCN | YES |
| task | Task | FQCN | YES |
| qa_review | QaReview | FQCN | YES |
| approval | Approval | FQCN | YES |
| playbook | Playbook | FQCN | YES |
| recurring_review_run | RecurringReviewRun | FQCN | YES |

### Actor Matrix

| actor_kind | actor_user_id | Example | Fabricate admin? |
|---|---|---|---|
| internal_user | User id | Operator completes Task | NO |
| system | null | Finding created by evaluation | NEVER |
| client_contact | optional / null | Reserved vocabulary | NO fake user |

### Activity Snapshot Matrix

| Included | Excluded |
|---|---|
| event_type, subject_kind/id | Full Client Request body |
| Short title/summary (≤240) | Confidential QA/Approval notes |
| severity / status / priority | Secrets / tokens |
| Bounded counts (finding_count, …) | Provider raw payloads |

### Notification Policy Matrix

| DomainEventType | Activity? | NotificationKind | Preference key | Default recipients |
|---|---|---|---|---|
| FINDING_CREATED | Y if brand | finding_created | critical_finding | [] |
| OPPORTUNITY_CREATED | Y if brand | opportunity_created | critical_finding | [] |
| RECOMMENDATION_ACCEPTED | Y if brand | recommendation_accepted | critical_finding | [] |
| TASK_ASSIGNED | Y if brand | task_assigned | task_assigned | assignee |
| TASK_COMPLETED | Y if brand | task_completed | task_assigned | assignee |
| QA_* | Y if brand | qa_* | qa_review_required | task assignee |
| APPROVAL_* | Y if brand | approval_* | approval_waiting | task assignee |
| RECURRING_REVIEW_COMPLETED | Y if brand | recurring_review_completed | recurring_review_due | schedule owner |
| CLIENT_REQUEST_CREATED | Y if brand | client_request_created | client_request_received | owner |

### Recipient Resolution Matrix

| Event | Resolution source | Fan-out | Self-suppress |
|---|---|---|---|
| Finding/Opportunity/RecommendationAccepted | none | none | N/A |
| TaskAssigned/Completed | `tasks.assignee_id` | single | YES |
| QA_* | `qa_reviews` → task.assignee_id | single | YES |
| Approval_* | `approvals` → task.assignee_id | single | YES |
| RecurringReviewCompleted | schedule.owner_user_id | single | YES |
| ClientRequestCreated | owner_user_id | single | YES |

### Notification State Matrix

| State | read_at | archived_at | Bell list | Unread count |
|---|---|---|---|---|
| Unread | null | null | YES | YES |
| Read | set | null | YES | NO |
| Archived | any | set | NO (default lists) | NO |

### Preference Matrix

| Key | In Settings | Wired to DomainEvent now | Default in-app | Email delivered |
|---|---|---|---|---|
| critical_finding | YES | Finding/Opportunity/RecommendationAccepted | true | NO |
| task_assigned | YES | TaskAssigned/Completed | true | NO |
| client_request_received | YES | ClientRequestCreated | true | NO |
| approval_waiting | YES | Approval_* | true | NO |
| qa_review_required | YES | QA_* | true | NO |
| recurring_review_due | YES | RecurringReviewCompleted | true | NO |
| integration_failure / task_overdue / work_item_overdue / regression_observed / provider_authorization_issue / operation_failed | YES | not wired in P47 emitters | true | NO |

### Spam Prevention Matrix

| Mechanism | In Prompt 47? |
|---|---|
| Idempotency key unique | YES |
| Unique Activity.domain_event_id | YES |
| Unique (event, recipient, kind) | YES |
| Self-suppression | YES |
| Empty recipient policies | YES |
| Preference gate | YES |
| No notify-all | YES |
| No external digests | YES |
| Review = one owner notify | YES |

### Durability Matrix

| Failure mode | Outcome |
|---|---|
| Rollback before commit | No event / no projections |
| Commit with projected | Durable event + projections |
| Commit pending / crash mid-project | Event exists; re-emit projects |
| Duplicate emit | Same event id; no duplicate projections |

### Idempotency Matrix

| Event | Default key shape |
|---|---|
| FINDING_CREATED | `FINDING_CREATED:finding:{id}` |
| OPPORTUNITY_CREATED | `OPPORTUNITY_CREATED:opportunity:{id}` |
| RECOMMENDATION_ACCEPTED | `RECOMMENDATION_ACCEPTED:recommendation:{id}` |
| TASK_COMPLETED | `TASK_COMPLETED:task:{id}` |
| TASK_ASSIGNED | `TASK_ASSIGNED:task:{id}:assignee:{id\|none}` |
| QA_PASSED | `QA_PASSED:qa_review:{id}` |
| APPROVAL_APPROVED | `APPROVAL_APPROVED:approval:{id}` |
| RECURRING_REVIEW_COMPLETED | `RECURRING_REVIEW_COMPLETED:recurring_review_run:{id}` |
| CLIENT_REQUEST_CREATED | `CLIENT_REQUEST_CREATED:client_request:{id}` |
| Other transitions | `{TYPE}:{subject_kind}:{id}:{transitionHash}` |

### Activity Scope Matrix

| DomainEvent brand_id | Activity row | Orphan event on Activity feed |
|---|---|---|
| set | YES (if policy) | via Activity row |
| null | NO | YES (`ActivityReadService` orphan DomainEvents) |

### Localization Matrix

| Field | Role |
|---|---|
| `title_key` / `body_key` | Stable i18n keys |
| `title` | English display string now |
| `body_params` | Interpolation inputs |
| Full lang files | Not required for P47 PASS |

### Privacy Matrix

| Data | Activity snapshot | Notification presentation | External channel |
|---|---|---|---|
| Short titles | YES | YES | N/A (none) |
| Full bodies/notes | NO | NO | NO |
| Secrets | NO | NO | NO |
| Recipient PII beyond user id | NO | recipient_user_id only | NO |

### Legacy Activity Matrix

| Writer | Role after P47 |
|---|---|
| FindingActivityRecorder et al. | May still write local audit events |
| ActivityProjector | Canonical for DomainEvent-backed timeline |
| ActivityReadService | Unifies both |

### Legacy Notification Matrix

| Primitive | Role after P47 |
|---|---|
| DemoNotificationFixtures | Retired from Bell |
| Laravel database notifications | Not canonical |
| user_notifications | Canonical |

### Demo Retirement Matrix

| Demo path | Production replacement | Status |
|---|---|---|
| Demo Activity feed | ActivityReadService | RETIRED on Index |
| Demo bell items | NotificationReadService | RETIRED on Bell |
| Demo notification prefs | NotificationPreferenceService | RETIRED on Settings |

### External Delivery Boundary Matrix

| Channel | Persist preference? | Deliver in P47? |
|---|---|---|
| In-app | YES (`in_app_enabled`) | YES |
| Email | YES (`email_enabled`) | NO |
| Push | NO | NO |
| Slack | NO | NO |
| WhatsApp | NO | NO |
| SMS | NO | NO |

### Domain Boundary Matrix

| Concern | In P47? |
|---|---|
| Domain Events + projectors | YES |
| Activity reuse + UI | YES |
| In-app notifications + prefs + UI | YES |
| Mandatory emit wiring | YES |
| External delivery | NO |
| Scheduler / digests | NO |
| AI | NO |
| ActivityV2 / Outbox V2 | NO |
| SaaS / client portal notify | NO |
| Prompt 48 External Skills Repo Audit | HANDOFF ONLY |
