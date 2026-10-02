# Business Outcome Recheck Contract

> Prompt 61 — Brand-scoped recurring completeness recheck over Prompt 57 aggregates.  
> Implementation: `BusinessOutcomeRecheckSchedule`, `BusinessOutcomeRecheckScheduleRecipient`, `BusinessOutcomeRecheckRun`, `BusinessOutcomeRecheckScheduleService`, `BusinessOutcomeRecheckService`, `BusinessOutcomeRecheckScheduleAdapter`.  
> Engine: [`RECURRING_AUTOMATION_ENGINE_CONTRACT.md`](RECURRING_AUTOMATION_ENGINE_CONTRACT.md)  
> Outcomes truth: [`BUSINESS_OUTCOME_CONTRACT.md`](BUSINESS_OUTCOME_CONTRACT.md)

## Canonical rule

A **Business Outcome Recheck** is a **read-only completeness attention pass** over existing Brand Outcome definitions/aggregates for a resolved prior period. It does **not** invent Outcome values, does **not** write Observations, does **not** call providers, and does **not** attribute channel causality. When attention thresholds fire, it emits an in-app Domain Event / Notification to explicit recipients only.

---

## Schedule row

| Field | Contract |
| --- | --- |
| Table / model | `business_outcome_recheck_schedules` / `BusinessOutcomeRecheckSchedule` |
| Ownership | Required `customer_id` + `brand_id` (Brand must match Customer) |
| `frequency` | `weekly` \| `monthly` only |
| `timezone` / `delivery_time` | IANA + local `HH:MM` |
| `day_of_month` / `weekdays` | Required shape per frequency |
| `period_strategy` | `previous_calendar_month` \| `previous_calendar_week` |
| `misfire_policy` | Default `run_latest_missed` |
| Attention flags | `attention_on_no_data`, `attention_on_partial`, `attention_on_unknown` (default true) |
| `status` | `active` \| `paused` \| `archived` |
| Recipients | `business_outcome_recheck_schedule_recipients` (`user_id`); empty allowed (recheck runs, notify skipped) |

CRUD: `BusinessOutcomeRecheckScheduleService` (`create` / `pause` / `resume` / `archive`) with Brand/Customer authorization lists.

---

## Period resolution

| Strategy | Semantics |
| --- | --- |
| `previous_calendar_month` | Relative to scheduled local instant → prior month start/end dates |
| `previous_calendar_week` | Prior ISO week Mon–Sun dates |

Resolved by `RecurringOccurrenceCalculator::resolvePreviousCalendarMonth` / `resolvePreviousCalendarWeek`.

---

## Run row

| Field | Contract |
| --- | --- |
| Table / model | `business_outcome_recheck_runs` / `BusinessOutcomeRecheckRun` |
| Link | Unique nullable `recurring_occurrence_id` → shared ledger |
| `period_start` / `period_end` | Resolved dates |
| `status` | Recheck run status (`completed` on success path) |
| `results_payload` | JSON list of per-definition status/value/limitation snapshots |
| `notified` | True when attention Domain Event emitted |

Idempotency: one Run per `recurring_occurrence_id` (replay returns existing).

---

## Result statuses

Mapped from Prompt 57 aggregate status:

| Aggregate | Recheck result |
| --- | --- |
| `complete` | `complete` |
| `partial` | `partial` |
| `unknown_completeness` | `unknown_completeness` |
| `no_data` | `no_data` |
| integrity / currency / overlap / grain blocks | `integrity_blocked` |

No definition → synthetic `no_data` + `no_definition` limitation.

---

## Attention notification

| Rule | Contract |
| --- | --- |
| Trigger | Any mapped result matching enabled attention flags |
| Channel | Prompt 47 in-app only via `DomainEventType::BusinessOutcomeRecheckAttention` |
| Recipients | Explicit schedule recipient user ids only — **no notify-all** |
| Copy | Deterministic title/body; values not invented |
| Subject | `DomainEventSubjectKind::BusinessOutcomeRecheckRun` |

---

## Engine binding

| Piece | Contract |
| --- | --- |
| Kind | `business_outcome_recheck` |
| Adapter | `BusinessOutcomeRecheckScheduleAdapter` |
| Domain run type | `business_outcome_recheck_run` |
| Manual run | Supported at adapter flag level |
| Frequencies | Weekly / monthly |

---

## Forbidden

- Writing / correcting Outcome Observations from recheck
- Provider HTTP / AI inference of missing values
- Channel attribution / ROI / ROAS claims
- Email/SMS/Slack delivery of recheck attention
- `BusinessOutcomeRecheckV2` parallel entity
