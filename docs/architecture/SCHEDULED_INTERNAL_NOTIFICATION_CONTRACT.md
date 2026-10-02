# Scheduled Internal Notification Contract

> Prompt 61 — operator in-app reminder schedules over Prompt 47 Notification primitives.  
> Implementation: `InternalNotificationSchedule`, `InternalNotificationScheduleRecipient`, `InternalNotificationScheduleService`, `ScheduledInternalNotificationService`, `InternalNotificationScheduleAdapter`.  
> Engine: [`RECURRING_AUTOMATION_ENGINE_CONTRACT.md`](RECURRING_AUTOMATION_ENGINE_CONTRACT.md)  
> Notification truth: Prompt 47 Activity / Notification production persistence

## Canonical rule

A **Scheduled Internal Notification** is a **recurring, recipient-explicit, in-app operator reminder**. Content is operator-authored (sanitized title/message). Delivery uses Prompt 47 Domain Event → Notification projection. It is **not** email/push/Slack/WhatsApp, **not** notify-all, **not** a workflow builder, and **not** client-portal messaging.

---

## Schedule row

| Field | Contract |
| --- | --- |
| Table / model | `internal_notification_schedules` / `InternalNotificationSchedule` |
| Scope | Optional `customer_id` / `brand_id` (nullable for ops-global reminders) |
| `timezone` / `local_time` | IANA + `HH:MM` |
| `frequency` | `daily` \| `weekly` \| `monthly` |
| `interval` | ≥ 1 |
| `day_of_month` / `weekdays` | Shape per frequency |
| `title` / `message` | Required; strip tags; length-capped |
| `safe_route_name` | Optional allowlisted internal route name |
| `misfire_policy` | Default `skip_missed` |
| `status` | `active` \| `paused` \| `archived` |
| Recipients | `internal_notification_schedule_recipients` — **at least one** valid user required on create |

CRUD: `InternalNotificationScheduleService` (`create` / `pause` / `resume` / `archive`).

---

## Safe routes

Allowlist in `ScheduledInternalNotificationService` (v1):

| Route name |
| --- |
| `demo.work` |
| `demo.work.show` |
| `demo.findings` |
| `demo.tasks` |
| `demo.notifications` |
| `demo.portfolio.brand` |
| `demo.portfolio.customer` |

Any other `safe_route_name` → `UNSAFE_ROUTE` at deliver time.

---

## Delivery

| Step | Contract |
| --- | --- |
| Entry | `ScheduledInternalNotificationService::deliver(schedule, occurrence)` |
| Recipients | Distinct existing users on schedule only |
| Empty recipients | No event; adapter may complete with “No valid recipients” message |
| Event | `DomainEventType::ScheduledInternalNotification` |
| Actor | `system` |
| Subject | `InternalNotificationSchedule` + schedule id |
| Payload | title, body, safe_route_name, recipient_user_ids, recurring_occurrence_id |
| Idempotency key | `internal-notification:{occurrence_key}` |
| Channel | In-app NotificationKind `scheduled_internal_notification` only |

---

## Engine binding

| Piece | Contract |
| --- | --- |
| Kind | `internal_notification` |
| Adapter | `InternalNotificationScheduleAdapter` |
| Domain run type | `notification_batch` (binds first domain event id when present) |
| Default misfire | `skip_missed` |
| Manual run | Supported at adapter flag level |

---

## Forbidden

- Email / SMS / push / Slack / WhatsApp delivery
- Notify-all operators when recipients empty or omitted
- Arbitrary URL / open redirect in `safe_route_name`
- AI-generated reminder copy
- Client portal / customer login notifications
- `InternalNotificationScheduleV2`
