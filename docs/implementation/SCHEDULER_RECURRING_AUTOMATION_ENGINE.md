# SCHEDULER / RECURRING AUTOMATION ENGINE

## STATUS: REAL (Prompt 61) — docs reflect code on branch

**Prompt:** 61  
**Canonical path:** `docs/implementation/SCHEDULER_RECURRING_AUTOMATION_ENGINE.md`  
**Contracts:** [`RECURRING_AUTOMATION_ENGINE_CONTRACT.md`](../architecture/RECURRING_AUTOMATION_ENGINE_CONTRACT.md) · [`BUSINESS_OUTCOME_RECHECK_CONTRACT.md`](../architecture/BUSINESS_OUTCOME_RECHECK_CONTRACT.md) · [`SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md`](../architecture/SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md)  
**Depends on:** Prompt 46 Recurring Reviews · Prompt 47 Activity/Notification · Prompt 57 Business Outcomes · Prompt 27 Freshness incremental start · Prompt 60 Report Delivery  
**Base HEAD:** Prompt 60 cumulative `5fafb46`  
**Branch:** `cursor/scheduler-recurring-automation-engine-ea01`

| Fact | Value |
| --- | --- |
| Shared ledger | `recurring_occurrences` / `RecurringOccurrence` |
| Spec / calculator | `RecurringScheduleSpec` · `RecurringOccurrenceCalculator` |
| Dispatcher | `RecurringAutomationDispatcher` |
| Command | `moxdop:dispatch-due-automations` every 5 min |
| Report compat | `reports:dispatch-due-deliveries` → shared dispatcher (`report_delivery` only) |
| Kinds | collection · recurring_review · business_outcome_recheck · internal_notification · report_delivery |
| Frequencies | hourly · daily · weekly · monthly (via `RecurringScheduleSpec`) |
| Misfire | `skip_missed` · `run_latest_missed` · `catch_up_bounded` |
| Workflow builder | **NO** |
| AI | **NO** |
| V2 entities | **NO** (`SchedulerV2` / `ReviewScheduleV2` / `ReportDeliveryScheduleV2` forbidden) |
| Domain ownership | Schedules remain domain-owned; engine is shared runtime only |

---

## 1. Purpose

Deliver a **shared recurring automation engine** that discovers due domain schedules, materializes idempotent **RecurringOccurrence** ledger rows, queues claim/execute jobs, and delegates execution to **bounded adapters** — without inventing a generic workflow platform, without AI, and without absorbing Prompt 46/60 domain schedules into V2 tables.

```text
Domain schedule (owned by domain)
  → Adapter discoverDue (RecurringScheduleSpec + misfire)
    → shared RecurringOccurrence ledger
      → ExecuteRecurringOccurrenceJob
        → Adapter execute → domain run bind
```

## 2. Scope

In scope:

- Shared occurrence ledger + status/claim lifecycle
- `RecurringScheduleSpec` / calculator / misfire policies
- Registry + dispatcher + console schedule (5 min)
- Adapters: collection, recurring review, BO recheck, internal notification, report delivery
- New domain schedules: `CollectionSchedule`, `BusinessOutcomeRecheckSchedule` (+ runs), `InternalNotificationSchedule`
- Prompt 60 command convergence onto shared dispatcher for report kind only

Out of scope:

- SaaS / client portal scheduling
- External write actions / CRM drip / marketing calendar
- Workflow builder / step graphs / marketplace ZIP automations
- AI schedule inference or AI execution
- Email/SMS/push/Slack for scheduled internal notifications
- Absorbing Prompt 46/60 tables into polymorphic V2 schedules

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Prompt 46 / 47 / 57 / 60 domain contracts |
| 3 | This implementation + three architecture contracts |
| 4 | Operator `web` guard / Filament `app` panel surfaces (UI wiring may lag) |

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | Shared ledger only — domain schedules stay domain-owned |
| R2 | No workflow builder / generic automation marketplace |
| R3 | No AI |
| R4 | Frequencies via `RecurringScheduleSpec` only (hourly/daily/weekly/monthly) |
| R5 | Explicit misfire policies — no unbounded catch-up storms |
| R6 | Idempotent occurrence keys — double dispatch ≠ double execute |
| R7 | `ReportDeliverySchedule` remains Prompt 60 truth; adapter converges runtime |
| R8 | Recurring Review materialize remains Prompt 46 service |
| R9 | Collection adapter never calls providers directly; never initial backfill |
| R10 | No `SchedulerV2` / `ReviewScheduleV2` / `ReportDeliveryScheduleV2` |

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Base | Prompt 60 `5fafb46` (report PDF/share/delivery) |
| Branch | `cursor/scheduler-recurring-automation-engine-ea01` |
| Migration | `database/migrations/2026_08_16_040000_create_recurring_automation_engine_tables.php` |
| Provider | `RecurringAutomationServiceProvider` registered in `bootstrap/providers.php` |

## 6. Prior Prompt Input Audit

| Input | Use in Prompt 61 |
| --- | --- |
| Prompt 46 `MaterializeRecurringReviewOccurrence` | RR adapter execute |
| Prompt 46 `RecurringReviewDueCalculator` | RR occurrence key |
| Prompt 47 Domain Event / Notification | BO attention + scheduled internal notify |
| Prompt 57 Outcome aggregates | BO recheck read path |
| Prompt 27 `StartIncrementalCollectionService` | Collection adapter execute |
| Prompt 60 `ReportDeliverySchedule` + occurrence executor | Report adapter + command convergence |

## 7. Existing Scheduler Primitive Audit

| Primitive | Location | Decision |
| --- | --- | --- |
| Prompt 46 manual materialize | RR services | KEEP — auto via adapter |
| Prompt 60 `reports:dispatch-due-deliveries` | console | CONVERGE to shared dispatcher |
| Prompt 27 due/incremental start | Data Pool | KEEP — called by collection adapter |
| Prompt 47 notification prefs | Settings | KEEP — no email delivery here |
| Generic `automation_steps` / workflow nodes | — | **NOT CREATED** |
| Laravel `Schedule::daily` collection cron (P27) | — | Replaced by shared 5-min dispatch |

## 8. Frozen Product Surface Audit

| Surface | Prompt 61 owner |
| --- | --- |
| Shared due fan-out | `moxdop:dispatch-due-automations` |
| Report due fan-out compat | `reports:dispatch-due-deliveries` |
| RR auto materialize | `RecurringReviewScheduleAdapter` |
| Collection recurring incremental | `CollectionScheduleAdapter` |
| BO completeness attention | `BusinessOutcomeRecheck*` |
| Ops reminder schedules | `InternalNotificationSchedule*` |
| Filament schedule CRUD UI | Not required for engine REAL; services exist |

## 9. Canonical Decision

**CREATE** shared `recurring_occurrences` ledger + adapter registry/dispatcher/executor. **KEEP** domain schedule tables. **DO NOT CREATE** V2 parallel schedule entities or workflow graphs.

## 10. Shared Engine vs Domain Schedules

Engine owns: discovery coordination, occurrence ledger, queue/claim/execute, failure codes on the ledger.  
Domain owns: schedule CRUD fields, domain run rows, domain business rules (period, recipients, materialize, incremental start, PDF delivery).

## 11. RecurringOccurrence Ledger

Table `recurring_occurrences`: kind + domain_schedule_id + scheduled_for (UTC) + timezone snapshot + spec fingerprint + status timestamps + attempt_count + optional domain_run bind + failure fields + `is_manual` + unique `occurrence_key`. No Eloquent updated_at (created_at only).

## 12. No Workflow Builder / No AI

No `automation_steps`, `workflow_nodes`, visual builder, conditional graphs, or LLM/provider calls in the engine path. Adapters are code-registered only.

## 13. RecurringScheduleKind Registry

Closed enum: `collection`, `recurring_review`, `business_outcome_recheck`, `internal_notification`, `report_delivery`. Unknown kind → registry/`dispatchDue` invalid argument.

## 14. Adapter Contract

`App\Contracts\RecurringAutomation\RecurringScheduleAdapter` — `kind`, `discoverDue`, `execute`, `isScheduleActive`, `allowedFrequencies`, `defaultMisfirePolicy`, `supportsManualRun`. Result DTO: `RecurringScheduleAdapterResult`.

## 15. RecurringScheduleSpec

Readonly DTO with timezone, frequency, interval, localTime, weekdays, dayOfMonth, monthEndPolicy, starts/ends, misfire. `assertValid()` + SHA-256 `fingerprint()`.

## 16. Frequencies

`RecurringFrequency`: hourly, daily, weekly, monthly. Adapters further restrict allowlists (e.g. report monthly-only; collection hourly/daily).

## 17. RecurringOccurrenceCalculator

Timezone-aware `nextOccurrence` (exclusive after). Helpers: `resolvePreviousCalendarMonth`, `resolvePreviousCalendarWeek`. Monthly clamps day to month length; supports `last_day_of_month`.

## 18. Misfire Policies

| Policy | Behavior |
| --- | --- |
| `skip_missed` | Latest slot only if within ~30 min of now |
| `run_latest_missed` | Latest missed slot only |
| `catch_up_bounded` | Last N slots (`maxCatchUp`) |

Implemented in `DiscoversDueOccurrences` trait.

## 19. DiscoversDueOccurrences

Walks calculator from lookback cursor (default 45 days), collects missing slots, applies misfire filter. Used by all five adapters.

## 20. RecurringAutomationRegistry

Singleton map kind → adapter. Registered in `RecurringAutomationServiceProvider` with all five adapters.

## 21. RecurringAutomationDispatcher

For each adapter (optional kind filter): discoverDue → ensureOccurrence → if pending + schedule active → mark queued → dispatch job. Returns queued occurrence ids.

## 22. Occurrence Key Idempotency

`{kind}:{domain_schedule_id}:{Y-m-d\TH:i:s\Z}` unique. Also unique `(schedule_kind, domain_schedule_id, scheduled_for)`. Double dispatch returns existing row; second pass finds non-pending and skips.

## 23. Occurrence Status Lifecycle

`pending` → `queued` → `running` → terminal `completed` \| `failed` \| `skipped` \| `cancelled`. `cancel_requested` collapses to `cancelled` on claim. Terminal = `isTerminal()`.

## 24. ExecuteRecurringOccurrenceService / Claim

DB lock claim from pending/queued (or stale running &lt; 2h with attempt_count &lt; 2). Inactive non-manual → skipped `SCHEDULE_INACTIVE`. Adapter exceptions → failed `ADAPTER_EXCEPTION`. Invalid adapter status coerced to failed.

## 25. ExecuteRecurringOccurrenceJob

Queued job carries occurrence id only; calls execute service.

## 26. Console: moxdop:dispatch-due-automations

Signature supports `--kind=*`. Invokes `dispatchDue(onlyKinds: …)`.

## 27. Laravel Schedule Registration

`routes/console.php`: `Schedule::command('moxdop:dispatch-due-automations')->everyFiveMinutes();` alongside report compat + async stale marker.

## 28. reports:dispatch-due-deliveries Convergence

Prompt 60 command now injects `RecurringAutomationDispatcher` and dispatches **only** `RecurringScheduleKind::ReportDelivery`. Domain truth remains `ReportDeliverySchedule` / `ReportDeliveryOccurrence`.

## 29. ReportDeliveryScheduleAdapter

Maps Prompt 60 monthly schedule → `RecurringScheduleSpec` (monthly, day_of_month, delivery_time, `run_latest_missed`, maxCatchUp 1). Execute ensures domain occurrence then `ExecuteReportDeliveryOccurrenceService`. Manual run: false. Allowed frequency: monthly only.

## 30. RecurringReviewScheduleAdapter

Active schedules with `next_due_at <= now`. Spec derived from cadence (weekly/monthly; quarterly → monthly interval 3). Execute calls `MaterializeRecurringReviewOccurrence` with scheduled kind + due calculator key. Default misfire `run_latest_missed`. Manual: true.

## 31. CollectionScheduleAdapter

Active `CollectionSchedule` rows. Allowed hourly/daily; default `catch_up_bounded` (maxCatchUp 2). Execute → `StartIncrementalCollectionService::startForDigitalAsset` with occurrence idempotency suffix. Never initial backfill; never direct provider collectors. Outcomes `started` / `active_equivalent` / `data_current` → completed.

## 32. BusinessOutcomeRecheckScheduleAdapter

Active BO recheck schedules; weekly/monthly; `run_latest_missed`. Execute → `BusinessOutcomeRecheckService::executeForOccurrence`.

## 33. InternalNotificationScheduleAdapter

Active internal schedules; daily/weekly/monthly; `skip_missed`. Execute → `ScheduledInternalNotificationService::deliver`.

## 34. CollectionSchedule Domain

New table/model + `CollectionScheduleService` create/pause/resume/archive. Asset+Brand+Customer scoped; auth lists enforced on create.

## 35. RecurringReviewSchedule Domain Ownership

Prompt 46 tables unchanged. Engine only auto-materializes via adapter; no `ReviewScheduleV2`.

## 36. ReportDeliverySchedule Domain Ownership

Prompt 60 tables unchanged. Adapter + command converge runtime; schedule cadence remains monthly + previous_calendar_month period strategy in domain services.

## 37. Business Outcome Recheck Schedule

New Brand-scoped schedule + recipients. Flags for attention on no_data/partial/unknown. Empty recipients allowed.

## 38. Business Outcome Recheck Run

Unique bind to recurring occurrence. Stores period + `results_payload` + notified flag. Idempotent per occurrence.

## 39. Recheck Period Strategy

`previous_calendar_month` (default) or `previous_calendar_week` via calculator helpers.

## 40. Recheck Attention / Notification

Maps aggregate completeness → recheck result statuses. On attention: emit `BUSINESS_OUTCOME_RECHECK_ATTENTION` with explicit recipient_user_ids. No Outcome writes. No provider calls. No inventing values.

## 41. Internal Notification Schedule

Operator title/message schedules with required recipients, optional Customer/Brand, safe route allowlist, default `skip_missed`.

## 42. Scheduled Internal Notification Delivery

Strip tags, cap lengths, validate safe route, emit `SCHEDULED_INTERNAL_NOTIFICATION` idempotent on occurrence key. In-app only.

## 43. Safe Routes

Closed allowlist (`demo.work`, `demo.work.show`, `demo.findings`, `demo.tasks`, `demo.notifications`, `demo.portfolio.brand`, `demo.portfolio.customer`). Others → `UNSAFE_ROUTE`.

## 44. Domain Events Integration

| Event | Kind |
| --- | --- |
| `SCHEDULED_INTERNAL_NOTIFICATION` | NotificationKind `scheduled_internal_notification` |
| `BUSINESS_OUTCOME_RECHECK_ATTENTION` | NotificationKind `business_outcome_recheck_attention` |

Category group `automation` on DomainEventType.

## 45. Authorization / Tenancy

Collection + BO recheck create services accept authorized Customer/Brand id lists. Engine itself does not widen Brand access. Internal notification recipients must be real users.

## 46. Pause / Resume / Archive

Domain schedule status enums: active/paused/archived (RR uses ended historically). Inactive schedules are not newly queued; in-flight may skip on execute if inactive and not manual.

## 47. Manual Run Support

Adapter flag `supportsManualRun`: true for collection, RR, BO recheck, internal notify; false for report delivery. Occurrence `is_manual` bypasses inactive skip.

## 48. No SchedulerV2 / ReviewScheduleV2 / ReportDeliveryScheduleV2

Tests assert these classes and workflow tables do not exist. Do not invent them in docs or code.

## 49. No External Write / Provider Direct Calls

Collection uses incremental orchestrator only. BO recheck reads aggregates only. Notifications are in-app. Report adapter uses Prompt 60 secure delivery path (no new external write class).

## 50. Privacy

Recipient lists explicit. No cross-Brand fan-out. BO recheck payload stores aggregate statuses/values already authorized for Brand operators — not client portal.

## 51. Security

Safe route allowlist. Content strip_tags. Occurrence claim locking. Secrets: none new beyond existing Prompt 60 share path for report kind.

## 52. Performance

Dispatcher scans active schedules per kind; RR limited to 200 due rows per pass. Catch-up bounded. Five-minute cadence — not per-second cron.

## 53. Localization

BO recheck schedule has `locale` column (default `en`). Internal notification content is operator-authored. Engine itself is locale-agnostic.

## 54. Tests

| Suite | Coverage |
| --- | --- |
| `tests/Unit/RecurringAutomation/RecurringScheduleSpecAndCalculatorTest.php` | Spec validation + next occurrence math |
| `tests/Feature/RecurringAutomation/RecurringAutomationDispatcherTest.php` | Registry / queue / claim |
| `tests/Feature/RecurringAutomation/RecurringAutomationEngineProductionTest.php` | Boundaries, idempotency, adapters, no V2 |

## 55. Code Map

| Area | Path |
| --- | --- |
| Contract | `app/Contracts/RecurringAutomation/RecurringScheduleAdapter.php` |
| Spec / calculator | `app/Support/RecurringAutomation/*` |
| Dispatcher / registry / execute | `app/Services/RecurringAutomation/*` |
| Adapters | `app/Services/RecurringAutomation/Adapters/*` |
| Discovery trait | `app/Services/RecurringAutomation/Concerns/DiscoversDueOccurrences.php` |
| Job | `app/Jobs/RecurringAutomation/ExecuteRecurringOccurrenceJob.php` |
| Commands | `DispatchDueAutomationsCommand`, `DispatchDueReportDeliveriesCommand` |
| Domain services | `CollectionScheduleService`, `BusinessOutcomeRecheck*`, `InternalNotificationScheduleService`, `ScheduledInternalNotificationService` |
| Migration | `2026_08_16_040000_create_recurring_automation_engine_tables.php` |
| Schedule | `routes/console.php` |

## 56. Explicit Non-Goals

- Workflow builder / marketplace automations
- AI scheduling or AI execution
- Email/SMS/push/Slack for internal schedules
- Client portal / SaaS tenant schedules
- Claiming Prompt 60 schedule schema redesign
- Unbounded historical catch-up
- Initial collection backfill via recurring engine
- `SchedulerV2` / `ReviewScheduleV2` / `ReportDeliveryScheduleV2`

## 57. Definition of Done / Architecture Contracts

| Gate | Status |
| --- | --- |
| Shared ledger + dispatcher + 5-min command | YES |
| Five adapters registered | YES |
| Prompt 60 report command delegates report kind only | YES |
| Domain schedules remain domain-owned | YES |
| No workflow builder / no AI / no V2 models | YES |
| Contracts written (§334–336) | YES |
| Feature/unit tests present | YES |

Architecture contracts:

1. [`RECURRING_AUTOMATION_ENGINE_CONTRACT.md`](../architecture/RECURRING_AUTOMATION_ENGINE_CONTRACT.md)
2. [`BUSINESS_OUTCOME_RECHECK_CONTRACT.md`](../architecture/BUSINESS_OUTCOME_RECHECK_CONTRACT.md)
3. [`SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md`](../architecture/SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md)

---

## 337. Kind / Adapter Matrix

| Kind | Adapter | Domain schedule | Domain run type |
| --- | --- | --- | --- |
| `collection` | `CollectionScheduleAdapter` | `collection_schedules` | `collection_run` |
| `recurring_review` | `RecurringReviewScheduleAdapter` | `recurring_review_schedules` | `recurring_review_run` |
| `business_outcome_recheck` | `BusinessOutcomeRecheckScheduleAdapter` | `business_outcome_recheck_schedules` | `business_outcome_recheck_run` |
| `internal_notification` | `InternalNotificationScheduleAdapter` | `internal_notification_schedules` | `notification_batch` |
| `report_delivery` | `ReportDeliveryScheduleAdapter` | `report_delivery_schedules` | `report_delivery_occurrence` |

## 338. Frequency Allowlist Matrix

| Kind | Hourly | Daily | Weekly | Monthly |
| --- | --- | --- | --- | --- |
| collection | YES | YES | NO | NO |
| recurring_review | NO | NO | YES | YES (quarterly→interval 3) |
| business_outcome_recheck | NO | NO | YES | YES |
| internal_notification | NO | YES | YES | YES |
| report_delivery | NO | NO | NO | YES only |

## 339. Misfire Policy Matrix

| Policy | Latest only | Lookback gate | Bounded multi |
| --- | --- | --- | --- |
| `skip_missed` | YES | ~30 min | NO |
| `run_latest_missed` | YES | NO | NO |
| `catch_up_bounded` | NO | NO | YES (`maxCatchUp`) |

Defaults: collection=`catch_up_bounded`; RR/BO/report=`run_latest_missed`; internal=`skip_missed`.

## 340. Occurrence Status Matrix

| Status | Terminal? | Queues job? |
| --- | --- | --- |
| `pending` | NO | YES (if active) |
| `queued` | NO | already queued |
| `running` | NO | claimable if stale |
| `completed` / `failed` / `skipped` / `cancelled` | YES | NO |
| `cancel_requested` | NO | claim → cancelled |

## 341. Domain Ownership Matrix

| Concern | Shared engine | Domain |
| --- | --- | --- |
| Schedule CRUD fields | NO | YES |
| Occurrence ledger | YES | optional bind |
| Due discovery coordination | YES | adapter maps spec |
| Business side effects | NO | YES (materialize / incremental / PDF / notify / recheck) |

## 342. Spec Field Matrix

| Field | Required when |
| --- | --- |
| `timezone` | always |
| `frequency` / `interval` | always |
| `localTime` | daily/weekly/monthly |
| `weekdays` | weekly |
| `dayOfMonth` | monthly + `day_of_month` policy |
| `misfirePolicy` | always (defaulted per adapter) |

## 343. Dispatcher / Command Matrix

| Entry | Kinds | Cadence |
| --- | --- | --- |
| `moxdop:dispatch-due-automations` | all or `--kind` | every 5 min |
| `reports:dispatch-due-deliveries` | `report_delivery` only | every 5 min |
| Direct `dispatchDue()` | optional filter | tests / ops |

## 344. Report Delivery Convergence Matrix

| Item | Prompt 60 truth | Prompt 61 runtime |
| --- | --- | --- |
| `ReportDeliverySchedule` table | YES | consumed via adapter |
| Domain occurrence / snapshot / PDF / share | YES | execute path |
| Shared `RecurringOccurrence` | N/A historically | YES ledger |
| `ReportDeliveryScheduleV2` | FORBIDDEN | FORBIDDEN |
| Compat command | existed | delegates to shared dispatcher |

## 345. Recurring Review Boundary Matrix

| Capability | Owner |
| --- | --- |
| Schedule/check/run domain | Prompt 46 |
| Manual materialize | Prompt 46 |
| Automatic due fan-out | Prompt 61 adapter |
| `ReviewScheduleV2` | FORBIDDEN |

## 346. Collection Boundary Matrix

| Capability | Allowed |
| --- | --- |
| Incremental start via orchestrator | YES |
| Direct provider collector calls | NO |
| Initial backfill via engine | NO |
| Hourly/daily schedules | YES |

## 347. Business Outcome Recheck Matrix

| Action | Allowed |
| --- | --- |
| Read aggregates | YES |
| Write observations | NO |
| Provider HTTP | NO |
| Invent missing values | NO |
| Attention notify (explicit recipients) | YES |
| Notify-all | NO |

## 348. Internal Notification Matrix

| Action | Allowed |
| --- | --- |
| In-app Domain Event → Notification | YES |
| Email/SMS/push/Slack | NO |
| Empty recipients on create | NO |
| Unsafe routes | NO |
| Operator-authored copy | YES |
| AI-authored copy | NO |

## 349. Forbidden Claims Matrix

| Claim | Status |
| --- | --- |
| Generic workflow platform shipped | **NO** |
| AI scheduler | **NO** |
| SchedulerV2 / ReviewScheduleV2 / ReportDeliveryScheduleV2 | **NO** |
| Domain schedules absorbed into one table | **NO** |
| External write automation | **NO** |

## 350. Authorization Matrix

| Check | Where |
| --- | --- |
| Brand/Customer allowlists | Collection + BO recheck create |
| Recipient user existence | Internal + BO recipient rows |
| Schedule active gate | Dispatcher + execute |
| Safe route allowlist | Scheduled internal deliver |

## 351. Domain Event Matrix

| Event type | Subject | Notification kind |
| --- | --- | --- |
| `SCHEDULED_INTERNAL_NOTIFICATION` | `InternalNotificationSchedule` | `scheduled_internal_notification` |
| `BUSINESS_OUTCOME_RECHECK_ATTENTION` | `BusinessOutcomeRecheckRun` | `business_outcome_recheck_attention` |

## 352. Reality Matrix

| Capability | Status |
| --- | --- |
| Shared `RecurringOccurrence` ledger | **REAL** |
| `RecurringScheduleSpec` + calculator + misfire policies | **REAL** |
| Registry + dispatcher + claim/execute + job | **REAL** |
| `moxdop:dispatch-due-automations` every 5 min | **REAL** |
| `reports:dispatch-due-deliveries` → shared dispatcher (report kind) | **REAL** |
| Collection / RR / BO recheck / internal / report adapters | **REAL** |
| Domain schedules remain domain-owned | **REAL** |
| BO recheck read-only + attention notify | **REAL** |
| Scheduled internal in-app notifications | **REAL** |
| Workflow builder | **NONE** |
| AI in engine path | **NONE** |
| SchedulerV2 / ReviewScheduleV2 / ReportDeliveryScheduleV2 | **NONE** |
| Filament schedule CRUD UI completeness | **PARTIAL / NOT REQUIRED for engine REAL** |
