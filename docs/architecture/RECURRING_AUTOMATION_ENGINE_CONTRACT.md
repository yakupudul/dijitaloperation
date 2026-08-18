# Recurring Automation Engine Contract

> Prompt 61 — shared recurring automation runtime.  
> Implementation: `RecurringOccurrence`, `RecurringScheduleSpec`, `RecurringOccurrenceCalculator`, `RecurringAutomationRegistry`, `RecurringAutomationDispatcher`, `ExecuteRecurringOccurrenceService`, `ExecuteRecurringOccurrenceJob`, `DispatchDueAutomationsCommand`, adapters under `app/Services/RecurringAutomation/Adapters/`.  
> Related: [`BUSINESS_OUTCOME_RECHECK_CONTRACT.md`](BUSINESS_OUTCOME_RECHECK_CONTRACT.md), [`SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md`](SCHEDULED_INTERNAL_NOTIFICATION_CONTRACT.md), [`REPORT_DELIVERY_SCHEDULE_CONTRACT.md`](REPORT_DELIVERY_SCHEDULE_CONTRACT.md)

## Canonical rule

The **Recurring Automation Engine** is a **shared due-discovery + occurrence ledger + claim/execute runtime**. Domain schedules remain **domain-owned**. The engine does **not** replace `RecurringReviewSchedule`, `ReportDeliverySchedule`, `CollectionSchedule`, `BusinessOutcomeRecheckSchedule`, or `InternalNotificationSchedule`. There is **no** workflow builder, **no** AI, and **no** `SchedulerV2` / `ReviewScheduleV2` / `ReportDeliveryScheduleV2`.

---

## Shared ledger

| Field / concept | Contract |
| --- | --- |
| Table / model | `recurring_occurrences` / `RecurringOccurrence` |
| `schedule_kind` | `RecurringScheduleKind` |
| `domain_schedule_id` | FK-like id into the owning domain schedule table |
| `scheduled_for` | UTC planned instant |
| `timezone_snapshot` | IANA TZ at ensure time |
| `recurrence_spec_fingerprint` | SHA-256 of `RecurringScheduleSpec::fingerprint()` |
| `occurrence_key` | Unique `{kind}:{domain_schedule_id}:{Y-m-d\TH:i:s\Z}` |
| Unique | `(schedule_kind, domain_schedule_id, scheduled_for)` |
| Statuses | `pending` → `queued` → `running` → `completed` \| `failed` \| `skipped` \| `cancelled` (+ `cancel_requested`) |
| Domain bind | Optional `domain_run_type` + `domain_run_id` after execute |

Idempotency: double dispatcher → one occurrence row; terminal rows are not re-queued.

---

## Schedule kinds

| Kind | Domain schedule | Adapter | Default misfire |
| --- | --- | --- | --- |
| `collection` | `CollectionSchedule` | `CollectionScheduleAdapter` | `catch_up_bounded` |
| `recurring_review` | `RecurringReviewSchedule` (Prompt 46) | `RecurringReviewScheduleAdapter` | `run_latest_missed` |
| `business_outcome_recheck` | `BusinessOutcomeRecheckSchedule` | `BusinessOutcomeRecheckScheduleAdapter` | `run_latest_missed` |
| `internal_notification` | `InternalNotificationSchedule` | `InternalNotificationScheduleAdapter` | `skip_missed` |
| `report_delivery` | `ReportDeliverySchedule` (Prompt 60) | `ReportDeliveryScheduleAdapter` | `run_latest_missed` |

---

## RecurringScheduleSpec

| Field | Contract |
| --- | --- |
| `timezone` | Required valid IANA |
| `frequency` | `hourly` \| `daily` \| `weekly` \| `monthly` |
| `interval` | ≥ 1 |
| `localTime` | `HH:MM` required for daily/weekly/monthly |
| `weekdays` | ISO 1–7 required for weekly |
| `dayOfMonth` | 1–31 when monthly + `day_of_month` policy |
| `monthEndPolicy` | `day_of_month` \| `last_day_of_month` |
| `misfirePolicy` | `skip_missed` \| `run_latest_missed` \| `catch_up_bounded` |

Calculator: `RecurringOccurrenceCalculator::nextOccurrence` (timezone-aware; exclusive lower bound).

---

## Misfire policies

| Policy | Due selection |
| --- | --- |
| `skip_missed` | Latest due slot only if within ~30 minute lookback |
| `run_latest_missed` | Latest due slot only |
| `catch_up_bounded` | Last N missing slots (`maxCatchUp`, adapter-specific) |

---

## Adapter contract

`RecurringScheduleAdapter`:

- `kind()`, `discoverDue()`, `execute(RecurringOccurrence)`, `isScheduleActive()`
- `allowedFrequencies()`, `defaultMisfirePolicy()`, `supportsManualRun()`
- `execute` returns `RecurringScheduleAdapterResult` with terminal status + optional domain run bind

Registry: `RecurringAutomationServiceProvider` singleton wiring all five adapters.

---

## Dispatcher / commands

| Piece | Contract |
| --- | --- |
| Shared command | `moxdop:dispatch-due-automations {--kind=*}` |
| Laravel Schedule | `everyFiveMinutes()` in `routes/console.php` |
| Report compat | `reports:dispatch-due-deliveries` → `dispatchDue(onlyKinds: [ReportDelivery])` |
| Job | `ExecuteRecurringOccurrenceJob` (occurrence id only) |
| Claim | Lock + `pending`/`queued` (or stale `running` &lt; 2 attempts / 2h) → `running` |

Inactive non-manual schedules → occurrence `skipped` / `SCHEDULE_INACTIVE`.

---

## Forbidden

- Workflow builder / step graphs / marketplace automations
- AI schedule inference or AI execution
- Absorbing domain schedules into a single polymorphic schedule table
- `SchedulerV2`, `ReviewScheduleV2`, `ReportDeliveryScheduleV2`, `GenericAutomation`
- External write actions / client portal scheduling
