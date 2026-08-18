# Report Delivery Contract

> Prompt 60 — one logical email delivery of a Report Snapshot to one recipient.  
> Implementation: `ReportDelivery`, `ReportDeliveryAttempt`, `CreateReportDeliveryService`, `SendReportDeliveryService`, `ReportDeliveryMail`, `ReportMailConfigGuard`, `SendReportDeliveryJob`.  
> Config: `config/report_delivery.php` → `delivery.*`  
> Related: [`REPORT_ARTIFACT_CONTRACT.md`](REPORT_ARTIFACT_CONTRACT.md), [`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`](AUTHENTICATED_REPORT_SHARE_CONTRACT.md), [`REPORT_DELIVERY_SCHEDULE_CONTRACT.md`](REPORT_DELIVERY_SCHEDULE_CONTRACT.md)

## Canonical rule

A **Report Delivery** is a queued→sent (or failed) **email transport record** that points a recipient at an **authenticated secure share** for an existing Report Snapshot. Delivery always **pins** a Snapshot (and typically an Artifact + Share Grant). Delivery never rebuilds Client Value Story from live data. Delivery status stops at transport **Sent** — there is no Delivered / Opened / Read.

---

## Delivery Row

| Field | Contract |
| --- | --- |
| Table / model | `report_deliveries` / `ReportDelivery` |
| `report_snapshot_id` | Required FK (restrict) |
| `recipient_email_snapshot` / `recipient_name_snapshot` | Frozen at create |
| `delivery_mode` | `ReportDeliveryMode` enum |
| `share_grant_id` | Nullable FK to grant created for this send |
| `artifact_id` | Nullable FK to PDF artifact prepared for the Snapshot |
| `locale` | `en` \| `tr` |
| `subject_template_version` | Config `report_delivery_subject_v1` |
| `email_template_version` | Config `report_delivery_email_v1` |
| `status` | `ReportDeliveryStatus` |
| `schedule_occurrence_id` | Optional link to schedule occurrence |
| `idempotency_key` | Optional unique; create returns existing row |
| Occurrence uniqueness | Unique `(schedule_occurrence_id, recipient_email_snapshot)` when occurrence set |
| Timestamps | `created_at` only (`$timestamps = false`); `sent_at` / `failed_at` |

---

## Delivery Modes

| Mode | Value | Meaning |
| --- | --- | --- |
| Authenticated secure link | `authenticated_secure_link` | Email contains share locator URL; HTML view after OTP |
| Authenticated secure link + PDF access | `authenticated_secure_link_with_pdf_access` | Same + PDF download permission after OTP |

**Default:** `authenticated_secure_link_with_pdf_access` (`report_delivery.delivery.default_mode`).

**Default email:** no PDF attachment. Body is transactional (brand, title, period, secure link, verification notice) via `emails.reports.delivery` — no metrics dump.

---

## Status Model

| Status | Meaning |
| --- | --- |
| `queued` | Created; job dispatched / retryable |
| `preparing` | Reserved in enum; not the primary happy path today |
| `sending` | Transport attempt in flight |
| `sent` | Mail accepted by transport for this attempt path |
| `failed` | Terminal failure with `failure_category` / `failure_message` |
| `cancelled` | Cancelled; send short-circuits |

**Not present:** `delivered`, `opened`, `read`, `clicked` (email engagement tracking is out of scope).

---

## Attempts

| Field | Contract |
| --- | --- |
| Table | `report_delivery_attempts` |
| Unique | `(delivery_id, attempt_number)` |
| Results | `sent`, `failed_transient`, `failed_permanent`, `skipped_already_sent` |
| Max attempts | `delivery.max_attempts` (default 5); job `$tries = 5` |
| Idempotent send | If status already `sent` / `cancelled`, return without re-mail |

---

## Failure Categories

`ReportDeliveryFailureCategory`:

| Code | Typical use |
| --- | --- |
| `snapshot_generation_failed` | Schedule occurrence Snapshot create failure |
| `pdf_generation_failed` | PDF path failure |
| `share_creation_failed` | Grant / locator unavailable (incl. missing locator cache) |
| `email_configuration_missing` | Mail guard / SMTP host missing |
| `email_transport_transient` | Retryable transport exception |
| `email_transport_permanent` | Exhausted retries / permanent |
| `recipient_invalid` | Bad email |
| `share_expired_before_send` | Grant inactive at send |
| `authorization_invalidated` | Missing Snapshot/grant/schedule/brand/actor |

---

## Create → Send Flow

1. `CreateReportDeliveryService::sendFromSnapshot` asserts Brand/Customer authorization lists when provided.
2. `ReportMailConfigGuard::assertConfigured` (SMTP without host fails; `array` mailer allowed for tests).
3. Generate Artifact (`GenerateReportPdfService`).
4. Create Share Grant + raw locator; store locator in **cache** key `report-delivery-locator:{deliveryId}` (7-day TTL) — not DB plaintext.
5. Insert Delivery `queued`; dispatch `SendReportDeliveryJob` with **delivery ID only**.
6. `SendReportDeliveryService::send` loads locator from cache, builds `url('/reports/share/'.$locator)`, sends `ReportDeliveryMail`.

---

## Job Payload Rule

Queue jobs carry **IDs only** (`deliveryId`). No Snapshot JSON, PDF bytes, or secrets in the job payload.

---

## Forbidden

- Claiming email open/read receipts
- Default PDF attachments
- Rebuilding live Story inside delivery
- Generic multi-channel marketing automation
- `ReportDeliveryV2`
- Mutating Snapshot to “attach” delivery metadata
