# Report Delivery Schedule Contract

> Prompt 60 — Brand-scoped, report-specific recurring delivery schedules.  
> Implementation: `ReportDeliverySchedule`, `ReportDeliveryScheduleRecipient`, `ReportDeliveryOccurrence`, `ReportDeliveryScheduleService`, `ReportDeliveryDispatcher`, `ExecuteReportDeliveryOccurrenceService`, `DispatchDueReportDeliveriesCommand`.  
> Config: `config/report_delivery.php` → `schedule.*`  
> Related: [`REPORT_DELIVERY_CONTRACT.md`](REPORT_DELIVERY_CONTRACT.md), [`REPORT_SNAPSHOT_CONTRACT.md`](REPORT_SNAPSHOT_CONTRACT.md)

## Canonical rule

A **Report Delivery Schedule** is a **report-specific** monthly plan for one Customer+Brand+`client_value_story` report type. It is **not** a generic automation platform, not Recurring Review auto-scheduling (Prompt 61), and not collection scheduling (Prompt 62). Each due run materializes one **Occurrence**, which creates **one Snapshot** for the resolved period, one PDF Artifact, then per-recipient Deliveries.

---

## Schedule Row

| Field | Contract |
| --- | --- |
| Table / model | `report_delivery_schedules` / `ReportDeliverySchedule` |
| `customer_id` / `brand_id` | Required FKs; Brand ownership must match |
| `report_type` | `ReportType` — create always sets `client_value_story` |
| `locale` | `en` \| `tr` |
| `timezone` | IANA TZ (default `Europe/Istanbul`) |
| `cadence` | `ReportDeliveryScheduleCadence::Monthly` **only** |
| `day_of_month` | 1–31 (clamped to month length at scheduling) |
| `delivery_time` | `HH:MM` or `HH:MM:SS` local to schedule timezone |
| `period_strategy` | `ReportPeriodStrategy::PreviousCalendarMonth` **only** |
| `share_ttl_hours` | Default from `schedule.default_share_ttl_hours` (168) |
| `status` | `active` \| `paused` \| `archived` |
| `created_by` | Optional operator |
| Timestamps | Eloquent `$timestamps = true` |

Recipients live in `report_delivery_schedule_recipients` (email unique per schedule, optional `locale_override`, `enabled`).

---

## Cadence (v1)

| Allowed | Forbidden in Prompt 60 |
| --- | --- |
| `monthly` | Weekly, daily, cron expressions, arbitrary RRULE, multi-Brand fan-out |

---

## Period Strategy (v1)

| Strategy | Semantics |
| --- | --- |
| `previous_calendar_month` | For `scheduled_for` local time, period = previous month start→end dates |

Any other strategy → `UNSUPPORTED_PERIOD_STRATEGY`.

---

## Occurrence

| Field | Contract |
| --- | --- |
| Table | `report_delivery_occurrences` |
| `occurrence_key` | Unique `schedule:{id}:{scheduled_for_utc ISO Z}` |
| `scheduled_for` | UTC timestamp of planned run |
| `period_start` / `period_end` | Resolved dates for Snapshot create |
| `report_snapshot_id` / `artifact_id` | Filled as execution progresses |
| Statuses | `pending` → `claimed` → `snapshot_ready` → `artifact_ready` → `distributing` → `completed` (or `failed` / `cancelled`) |

Idempotency: unique occurrence key + delivery idempotency keys `occurrence:{id}:recipient:{email}`.

---

## Dispatcher

| Piece | Contract |
| --- | --- |
| Command | `reports:dispatch-due-deliveries` |
| Laravel Schedule | `everyFiveMinutes()` in `routes/console.php` |
| Service | `ReportDeliveryDispatcher::dispatchDue` |
| Lookback | `schedule.dispatcher_lookback_minutes` (default 30) |
| Action | Ensure occurrence row; if `pending`, dispatch `ExecuteReportDeliveryOccurrenceJob` (occurrence ID only) |

Paused schedules do not create new Snapshot work once inactive (pending execution may cancel if schedule not active and Snapshot not yet created).

---

## Occurrence Execution

`ExecuteReportDeliveryOccurrenceService`:

1. Claim pending occurrence.
2. Create Snapshot via Prompt 59 `CreateReportSnapshotService` with occurrence period + idempotency `occurrence:{id}:snapshot`.
3. Generate PDF Artifact.
4. For each enabled recipient, `CreateReportDeliveryService::sendFromSnapshot` with schedule share TTL expiry.
5. Mark occurrence `completed` (or `failed` with failure category).

Actor: schedule `created_by` user when present, else lowest `users.id`.

---

## Pause / Activate

| Method | Effect |
| --- | --- |
| `pause` | `status = paused` |
| `activate` | `status = active` |

No generic workflow engine / CRM drip / marketing calendar.

---

## Prompt 61 Boundary

| Owner | Owns |
| --- | --- |
| Prompt 60 | Report delivery schedules + occurrences for Client Value Story PDF/share email |
| Prompt 61 | Automatic Recurring Review scheduler (and related ops automation) — **not** claimed here |
| Prompt 62 | Collection / data-freshness automatic schedulers — **not** claimed here |

---

## Forbidden

- Generic automation marketplace / ZIP plugins
- Non-monthly cadences in v1
- Period strategies other than `previous_calendar_month`
- Claiming Recurring Review auto-materialization as Prompt 60
- `ReportDeliveryScheduleV2`
