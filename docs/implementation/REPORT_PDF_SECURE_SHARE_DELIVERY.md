# REPORT PDF / AUTHENTICATED SHARE / DELIVERY

## STATUS: REAL (Prompt 60) — docs reflect code on branch (UI wiring incomplete)

**Prompt:** 60  
**Canonical path:** `docs/implementation/REPORT_PDF_SECURE_SHARE_DELIVERY.md`  
**Contracts:** [`REPORT_ARTIFACT_CONTRACT.md`](../architecture/REPORT_ARTIFACT_CONTRACT.md) · [`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`](../architecture/AUTHENTICATED_REPORT_SHARE_CONTRACT.md) · [`REPORT_DELIVERY_CONTRACT.md`](../architecture/REPORT_DELIVERY_CONTRACT.md) · [`REPORT_DELIVERY_SCHEDULE_CONTRACT.md`](../architecture/REPORT_DELIVERY_SCHEDULE_CONTRACT.md)  
**Depends on:** Prompt 59 Report Snapshot (`211588349b523ce6cdf3c6724be493e38ce8108a`) · Prompt 58 Client Value Story  
**Base HEAD:** Prompt 59 `211588349b523ce6cdf3c6724be493e38ce8108a`  
**Branch:** `cursor/report-pdf-share-delivery-ea01`

| Fact | Value |
| --- | --- |
| PDF engine | `barryvdh/laravel-dompdf` `^3.1` |
| Renderer | `client_value_story_pdf_v1` |
| Artifact table | `report_artifacts` / `ReportArtifact` (immutable) |
| Share | Grant + OTP challenge + session + access events |
| Delivery default mode | `authenticated_secure_link_with_pdf_access` |
| PDF email attachment | **Not default** (secure link only) |
| Delivered / Opened / Read | **None** |
| Locator plaintext | Cache for send only — **not** DB |
| OTP / session secrets | SHA-256 hashed |
| PDF disk | `local` → `storage/app/private` |
| Schedule cadence | `monthly` only |
| Period strategy | `previous_calendar_month` only |
| Snapshot VOID | **N/A** (column/API absent); deny-new-when-added |
| Prompt 61 | Boundary clear — RR/collection auto-schedulers not owned |
| Feature tests | **Not yet** under `tests/Feature/ReportDelivery/` (unit model helpers only) |
| Composer UI flags | Snapshot detail still `delivery.* = false` + “unavailable” copy |

---

## 1. Purpose

Deliver Prompt 59 immutable Report Snapshots as **private PDF artifacts**, **recipient-authenticated secure shares**, and **email deliveries** (manual + Brand-scoped monthly schedules) — without public links, without inventing V2 entities, without generic automation, and without mutating Snapshot content.

```text
Prompt 59 immutable Report Snapshot
  → Prompt 60 PDF artifact + authenticated share + email delivery (+ monthly schedule)
    → Prompt 61+ other schedulers / surfaces (out of scope)
```

## 2. Scope

In scope:

- DomPDF rendering from Snapshot payload only
- Private artifact storage + operator download routes
- Authenticated share (locator ≠ auth; OTP; session cookie; audit)
- Delivery rows, attempts, mailables, queue jobs
- Report-specific monthly schedules + occurrences + dispatcher command
- Config TTLs/policies in `config/report_delivery.php`

Out of scope:

- SaaS / client portal / customer login product
- External write actions / CRM sync
- Generic automation platform / marketplace
- Email open/click tracking
- Snapshot VOID lifecycle (does not exist yet)
- Prompt 61 Recurring Review auto-scheduler / Prompt 62 collection scheduler
- `ReportArtifactV2` / `ReportShareGrantV2` / `ReportDeliveryV2`

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Prompt 59 Snapshot contracts |
| 3 | This implementation + four architecture contracts |
| 4 | Operator-authenticated Filament/`web` guard surfaces |

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | PDF/share/delivery consume Snapshot; never rebuild live Story as delivery truth |
| R2 | Locator token locates only — OTP + session authorize |
| R3 | Secrets hashed (locator/OTP/session); locator raw only in short-lived cache for send |
| R4 | Default delivery = authenticated secure link with PDF access; no PDF attachment default |
| R5 | No Delivered/Opened/Read states |
| R6 | Schedule = monthly + previous_calendar_month only |
| R7 | Private local disk for PDFs |
| R8 | No V2 parallel entities / generic automation claims |
| R9 | Snapshot VOID absent — treat as N/A; when added, deny share/delivery of VOID |
| R10 | Prompt 61/62 schedulers are not this feature |

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Base | Prompt 59 Snapshot persistence `211588349b523ce6cdf3c6724be493e38ce8108a` |
| Branch | `cursor/report-pdf-share-delivery-ea01` |
| Migration | `database/migrations/2026_08_16_030500_create_report_delivery_tables.php` |

## 6. Prompt 59 Input Audit

| Input | Use in Prompt 60 |
| --- | --- |
| `ReportSnapshot` immutable row | Sole content source for PDF + share HTML |
| `CreateReportSnapshotService` | Schedule occurrence Snapshot create |
| `content_checksum` / schema version | Copied onto Artifact |
| Detail `delivery.* = false` | Still false in read DTO until UI wiring flips flags |
| Handoff §60 | PDF / share / email / recipients owned here |

## 7. Existing Delivery Primitive Audit

| Primitive | Location | Decision |
| --- | --- | --- |
| Composer “delivery unavailable” | `_report-composer.blade.php` | Still present (UI not fully wired) |
| Demo future delivery note | fixtures | Unchanged demo preview |
| `report_artifacts` / share / delivery tables | migration `030500` | **CREATED** |
| Public magic-link without OTP | — | **FORBIDDEN** |
| Generic automation tables | — | **NOT CREATED** |

## 8. Frozen Product Surface Audit

| Surface | Prompt 60 owner |
| --- | --- |
| Operator PDF generate/download routes | `ReportArtifactDownloadController` |
| External share routes | `ReportShareController` |
| Email send | `CreateReportDeliveryService` / `SendReportDeliveryService` |
| Monthly schedule dispatch | `reports:dispatch-due-deliveries` every 5 minutes |
| Brand Reports composer delivery buttons | **Not yet** flipped from unavailable copy |

## 9. Canonical Decision

**CREATE** four cooperating domains (not one mega-table):

1. Artifact (PDF file + metadata)
2. Authenticated share (grant/OTP/session/audit)
3. Delivery (email transport + attempts)
4. Schedule (monthly plan + occurrences)

No CRM contact master. No public CDN URLs.

## 10. Report Artifact vs Report Snapshot

Snapshot = historical content pin. Artifact = binary rendering of that pin. Deleting/replacing Artifact metadata must not rewrite Snapshot payload. Unique `(snapshot_id, renderer_version)`.

## 11. Authenticated Share vs Public Link

Public permanent report URLs are forbidden. `/reports/share/{token}` only starts verification. HTML/PDF require active session cookie bound to grant.

## 12. Delivery vs Activity / Notification

Activity/Notification (Prompt 47) remain ops timeline/attention. Report Delivery is recipient email transport for reports. Delivery does not invent Activity rows by default in this prompt’s services.

## 13. Schedule vs Generic Automation / Prompt 61

Report schedules are Brand+report-type specific. They do **not** implement Recurring Review automatic materialization (Prompt 61) or collection schedulers (Prompt 62).

## 14. PDF Engine Decision (DomPDF)

Composer dependency `barryvdh/laravel-dompdf` `^3.1`. Facade `Barryvdh\DomPDF\Facade\Pdf`. A4 paper. Config published at `config/dompdf.php` when package installed.

## 15. Renderer Version (`client_value_story_pdf_v1`)

`ReportPdfRendererVersion::CLIENT_VALUE_STORY_PDF_V1` mirrors config `report_delivery.pdf.renderer_version`. Distinct from Snapshot schema `client_value_story_v1`.

## 16. ReportArtifact Model / Table

Fields: snapshot FK, `artifact_type=pdf`, schema + renderer versions, content + file checksums, disk/path/mime/size, generated_by/at, optional idempotency_key, `created_at` only.

## 17. Artifact Immutability

`ReportArtifact::update()` throws `REPORT_ARTIFACT_IMMUTABLE` when persisted. Broken-file recovery may delete+recreate under uniqueness constraint inside `GenerateReportPdfService`.

## 18. Artifact Idempotency / Dedupe

Lookup by `(snapshot_id, renderer_version)` and optional `idempotency_key`. Returns existing row if storage file exists.

## 19. GenerateReportPdfService

Authorized generate → render → `Storage::disk($disk)->put` under `report-artifacts/{snapshotId}/{renderer}/…pdf` → create row. `streamBytes` verifies file checksum.

## 20. ReportPdfRenderer (Snapshot-Only)

Reads `content_payload` story observations/opportunities/completed_work/business_outcomes/limitations + Snapshot header fields. **Never** calls `ClientValueStoryReadService` or live domain queries.

## 21. Private Storage Disk

Default disk `local` roots at `storage/app/private`. Artifacts are not placed on `public` disk.

## 22. File Checksum / Content Checksum Binding

`content_checksum` copies Snapshot integrity. `file_checksum` hashes PDF bytes. Stream path enforces `hash_equals`.

## 23. Internal Operator Download

Auth routes:

- `POST reports/snapshots/{snapshotId}/pdf`
- `GET reports/artifacts/{artifactId}/download`

Require authenticated user. Brand list enforcement on generate service when caller passes auth lists; download controller currently streams after auth user presence (see gaps).

## 24. ReportShareGrant

Recipient-specific grant with permissions JSON, expiry, revoke, locator hash, last successful access.

## 25. Locator Token Semantics

Raw token in URL; DB stores hash. Resolving locator does not grant report content.

## 26. Locator Cache (Not DB)

`CreateReportDeliveryService` caches raw locator at `report-delivery-locator:{deliveryId}` for 7 days for the send job. No plaintext locator column on deliveries/grants.

## 27. OTP Verification Challenge

6-digit OTP emailed; hash stored; TTL/attempts/cooldown/rate-limit from config. Email contains code only.

## 28. Share Session

Post-verify session token hashed; HttpOnly cookie `moxdop_report_share_session`; TTL clamped to grant expiry.

## 29. Permissions (`html_view` / `pdf_download`)

Grant helpers `allowsHtml()` / `allowsPdf()`. View and PDF endpoints enforce separately.

## 30. TTL / Expiry / Revocation

Share create rejects past expiry and TTL above `max_ttl_hours`. `revokeGrant` sets `revoked_at` and revokes sessions; audits `grant_revoked`.

## 31. Access Audit Events

Append-only hashed IP/UA events for verify/view/pdf/deny/revoke paths.

## 32. ReportShareService

Owns createGrant, resolveGrantByLocator, requestVerification, verifyCode, resolveSession, revokeGrant, audit, maskEmail.

## 33. External Share Routes / Controllers

`ReportShareController` implements locator → verify form → request/submit OTP → view → PDF with security headers and noindex.

## 34. Security Headers / Cookie

Private no-store cache, nosniff, DENY framing, CSP, no-referrer, robots noindex. Session regenerate after verify.

## 35. ReportDelivery Model

One row per recipient send attempt lineage; links Snapshot, grant, artifact, optional occurrence; frozen recipient snapshots; template versions; status + failure fields.

## 36. Delivery Modes

Enum: `authenticated_secure_link`, `authenticated_secure_link_with_pdf_access`.

## 37. Default Mode `AUTHENTICATED_SECURE_LINK_WITH_PDF_ACCESS`

Config `delivery.default_mode`. Create service falls back to this mode.

## 38. No PDF Email Attachment Default

`ReportDeliveryMail` markdown button to share URL only — no `attach()` of PDF bytes.

## 39. CreateReportDeliveryService

Mail guard → PDF → grant → delivery queued → cache locator → dispatch job. Idempotent on key and occurrence+email unique.

## 40. SendReportDeliveryService

Idempotent sent/cancelled short-circuit; requires active grant + locator cache; records attempts; maps transport failures.

## 41. Delivery Attempts / Retries

`report_delivery_attempts` unique per attempt number; max attempts from config; job tries = 5.

## 42. Failure Categories

Closed enum covering Snapshot/PDF/share/mail/auth/recipient failures (see Delivery contract).

## 43. Mail Config Guard

`ReportMailConfigGuard` blocks empty SMTP host; allows `array` mailer for tests/local truthfulness without fake Sent when misconfigured SMTP.

## 44. ReportDeliveryMail (Minimal Body)

Subject localized ready-notice with brand name. Body: brand, title, period, secure link, verification + no-metrics notices. (Lang keys referenced by blade may still need operator.php entries — see gaps.)

## 45. Status Model (No Delivered/Opened/Read)

Statuses: queued / preparing / sending / sent / failed / cancelled only.

## 46. ReportDeliverySchedule

Brand-scoped monthly Client Value Story schedule with timezone, day_of_month, delivery_time, share_ttl_hours, status.

## 47. Cadence Monthly Only

`ReportDeliveryScheduleCadence` has solely `monthly`. Create always sets Monthly.

## 48. Period Strategy `previous_calendar_month` Only

`ReportPeriodStrategy` sole case. `resolvePeriod` rejects others.

## 49. Schedule Recipients

Child rows with email uniqueness, optional display name / locale override, enabled flag.

## 50. ReportDeliveryOccurrence

Unique `occurrence_key`; period bounds; Snapshot/artifact FKs; status machine through completed/failed/cancelled.

## 51. Occurrence Key Idempotency

`schedule:{id}:{Y-m-d\TH:i:s\Z}` plus delivery keys `occurrence:{id}:recipient:{email}` and Snapshot/PDF keys under occurrence id.

## 52. ReportDeliveryScheduleService

create / previewNextOccurrence / nextMonthlyOccurrence / resolvePeriod / occurrenceKey / pause / activate.

## 53. ReportDeliveryDispatcher

Scans active schedules, ensures due occurrence, dispatches execute job for pending.

## 54. ExecuteReportDeliveryOccurrenceService

Claim → Snapshot → PDF → per-recipient deliveries → completed/failed.

## 55. Console Schedule Registration

`routes/console.php`: `Schedule::command('reports:dispatch-due-deliveries')->everyFiveMinutes();`  
Command: `DispatchDueReportDeliveriesCommand`.

## 56. VOID / Snapshot Status Boundary

`ReportSnapshot` has **no** void/status void column or API. Documented as **N/A**. When VOID is added later, share create / delivery create / schedule execution **must deny** VOID snapshots (deny-new-when-added). Do not pretend VOID exists today.

## 57. Authorization / Tenancy

Services accept optional `authorizedCustomerIds` / `authorizedBrandIds` and throw `UNAUTHORIZED_*`. Cross-Brand share/delivery of another Brand’s Snapshot is forbidden when lists are supplied. External share auth is grant+session, not operator tenancy.

## 58. No AI / Provider Calls

PDF/share/delivery paths do not call LLM providers or ads/analytics provider APIs.

## 59. No V2 Models / Generic Automation

No `*V2` entities. No marketplace ZIP. No generic workflow engine tables.

## 60. Privacy

OTP/session/locator secrets hashed. Access audit hashes IP/UA. Email avoids metrics dump. Share pages noindex.

## 61. Security

Private disk; security headers; HttpOnly cookie; rate limits; max OTP attempts; revoke path; job payloads are IDs only.

## 62. Performance

Artifact dedupe by Snapshot+renderer; occurrence uniqueness; delivery idempotency; dispatcher lookback window; queue fan-out per delivery/occurrence.

## 63. Localization

Locales `en`/`tr` on Snapshot, delivery, schedule; mail subject branches on locale; share UI uses Snapshot locale where applicable.

## 64. UI Surface Status

Operator composer still shows `operator.reports.delivery_unavailable`. Snapshot detail DTO still reports `delivery.pdf|download|share|email = false` with `owner: prompt_60`. Backend routes/services exist; UI enablement incomplete.

## 65. Tests

| Suite | Status |
| --- | --- |
| `tests/Unit/ReportDelivery/ReportDeliveryModelsTest.php` | Present (immutability, grant/session helpers, enum casts, timestamps) |
| `tests/Feature/ReportDelivery/*` | **Empty / not present** |

## 66. Code Map

| Area | Path |
| --- | --- |
| Config | `config/report_delivery.php` |
| Migration | `database/migrations/2026_08_16_030500_create_report_delivery_tables.php` |
| Models | `app/Models/ReportArtifact.php`, `ReportShare*`, `ReportDelivery*` |
| Services | `app/Services/ReportDelivery/*` |
| Support | `SecretHasher`, `ReportPdfRendererVersion` |
| Controllers | `app/Http/Controllers/Reports/*` |
| Jobs | `app/Jobs/Reports/*` |
| Mail | `app/Mail/ReportDeliveryMail.php` |
| Views | `resources/views/reports/*`, `resources/views/emails/reports/delivery.blade.php` |
| Routes | `routes/web.php`, `routes/console.php` |
| Command | `app/Console/Commands/DispatchDueReportDeliveriesCommand.php` |

## 67. Explicit Non-Goals

- Public share without verification
- PDF attachment as default channel
- Open/read tracking
- Generic automation / Prompt 61 RR auto-scheduler / Prompt 62 collectors
- Snapshot VOID semantics (absent)
- Client portal
- Inventing parallel V2 models

## 68. Architecture Contracts

Four contracts are canonical for reviewers:

1. Artifact  
2. Authenticated Share  
3. Delivery  
4. Delivery Schedule  

## 69. Definition of Done

| Gate | Status |
| --- | --- |
| Base Prompt 59 HEAD recorded | YES (`2115883…`) |
| Branch `cursor/report-pdf-share-delivery-ea01` | YES |
| DomPDF `^3.1` + renderer `client_value_story_pdf_v1` | YES |
| Artifact / share / delivery / schedule tables + models | YES |
| Services for PDF, share, delivery, schedule, dispatcher | YES |
| Locator cache-not-DB; OTP/session hashed; private disk | YES |
| Default mode authenticated secure link with PDF access; no PDF attach default | YES |
| No Delivered/Opened/Read | YES |
| Monthly + previous_calendar_month only | YES |
| VOID documented N/A + deny-when-added | YES |
| Prompt 61 boundary documented | YES |
| Architecture contracts §417–420 written | YES |
| Sections 1–69 + matrices 421–440 + Reality 441 | YES |
| Feature test suite for delivery flows | **NO (gap)** |
| Operator UI flags / i18n email keys fully wired | **NO (gap)** |

---

## MANDATORY MATRICES (421–440)

## 421. Existing Primitive Matrix

| Primitive | Decision |
| --- | --- |
| Prompt 59 Snapshot | CONSUME immutable |
| DomPDF package | CREATE dependency |
| Public unauthenticated report URL | FORBIDDEN |
| Generic automation bus | FORBIDDEN |
| ReportArtifactV2 | DO NOT CREATE |

## 422. Frozen Surface Matrix

| Surface | Backend | UI |
| --- | --- | --- |
| Operator PDF | Routes+service REAL | Composer still “unavailable” |
| Share OTP flow | Controller+views REAL | External pages REAL |
| Email delivery | Services+mail REAL | Composer still “unavailable” |
| Monthly schedule | Dispatcher+command REAL | No dedicated Filament CRUD surfaced in this pass |

## 423. Artifact Matrix

| Field | Rule |
| --- | --- |
| Type | `pdf` |
| Uniqueness | snapshot + renderer |
| Immutability | update throws |
| Storage | private local |

## 424. PDF Renderer Matrix

| Input | Allowed |
| --- | --- |
| Snapshot content_payload | YES |
| Live ClientValueStoryReadService | NO |
| Live Findings/Outcomes queries | NO |
| Browser-uploaded PDF | NO |

## 425. Share Grant Matrix

| State | Access |
| --- | --- |
| Active | OTP/session may proceed |
| Expired | Deny |
| Revoked | Deny + sessions revoked |

## 426. Token / Secret Matrix

| Secret | DB storage |
| --- | --- |
| Locator | hash only (+ cache raw for send) |
| OTP | hash only |
| Session | hash only |
| IP / UA | hash only on audit |

## 427. OTP / Session Matrix

| Control | Default |
| --- | --- |
| OTP TTL | 15 min |
| Max attempts | 5 |
| Resend cooldown | 60 s |
| Request/hour | 10 |
| Session TTL | 60 min |

## 428. Access Event Matrix

| Event | Audited |
| --- | --- |
| verification_* | YES |
| report_viewed / pdf_downloaded | YES |
| access_denied / grant_revoked | YES |
| Email opened | NO |

## 429. Delivery Mode Matrix

| Mode | Email content | PDF permission after OTP |
| --- | --- | --- |
| `authenticated_secure_link` | Secure link | Intended link-only; create currently also sets `pdf_download` true (see gaps) |
| `authenticated_secure_link_with_pdf_access` | Secure link | YES (default) |

## 430. Delivery Status Matrix

| Status | Exists |
| --- | --- |
| queued/sending/sent/failed/cancelled(/preparing) | YES |
| delivered/opened/read | NO |

## 431. Failure Category Matrix

| Category | Exists |
| --- | --- |
| snapshot/pdf/share/mail/auth/recipient classes | YES (enum) |
| Soft “maybe sent” without attempt row | NO |

## 432. Schedule Cadence / Period Matrix

| Dimension | V1 |
| --- | --- |
| Cadence | `monthly` only |
| Period | `previous_calendar_month` only |
| Weekly/daily/RRULE | NO |

## 433. Occurrence Status Matrix

| Status | Role |
| --- | --- |
| pending→claimed→snapshot_ready→artifact_ready→distributing→completed | Happy path |
| failed / cancelled | Terminal |

## 434. Authorization Matrix

| Actor | Mechanism |
| --- | --- |
| Operator PDF/delivery create | `auth` + optional Brand/Customer lists |
| Recipient share | Grant + OTP + session cookie |
| Cross-Brand | Forbidden when auth lists enforced |

## 435. Storage Matrix

| Item | Location |
| --- | --- |
| PDF bytes | `storage/app/private/report-artifacts/...` |
| Locator raw | Cache key only |
| Secrets | Hashes in DB |

## 436. Email Content Matrix

| Included | Excluded |
| --- | --- |
| Brand, title, period, secure button | Metrics tables, PDF attachment (default), OTP in delivery mail |

## 437. Boundary vs Prompt 59 Matrix

| Concern | Owner |
| --- | --- |
| Snapshot immutability / content | Prompt 59 |
| PDF/share/email/schedules | Prompt 60 |
| Mutate Snapshot to attach PDF | FORBIDDEN |

## 438. Boundary vs Prompt 61 Matrix

| Concern | Owner |
| --- | --- |
| Report delivery monthly dispatcher | Prompt 60 |
| Recurring Review automatic scheduler | Prompt 61 |
| Collection automatic scheduler | Prompt 62 |

## 439. Forbidden Claims Matrix

| Claim | Allowed? |
| --- | --- |
| Generic automation platform | NO |
| VOID Snapshot handling exists | NO (N/A) |
| Delivered/Opened/Read | NO |
| Public share without OTP | NO |
| V2 models | NO |

## 440. UI Affordance Matrix

| Affordance | Reality |
| --- | --- |
| External share pages | REAL |
| Operator download routes | REAL |
| Composer delivery controls enabled | NOT YET (unavailable copy) |
| Snapshot detail delivery flags true | NOT YET (`false` + owner prompt_60) |

---

## 441. Reality Matrix

| Capability | State |
| --- | --- |
| DomPDF `^3.1` + `client_value_story_pdf_v1` | **REAL** |
| `report_artifacts` immutable + private disk | **REAL** |
| Authenticated share OTP/session/audit | **REAL** |
| Locator plaintext in DB | **NONE** (cache for send) |
| Delivery default secure link + PDF access | **REAL** |
| PDF attachment default | **NONE** |
| Delivered/Opened/Read | **NONE** |
| Monthly + previous_calendar_month schedules | **REAL** |
| `reports:dispatch-due-deliveries` every 5 min | **REAL** |
| Snapshot VOID | **N/A** |
| Prompt 61 RR auto-scheduler claimed | **NO** |
| Feature tests for end-to-end delivery | **NOT YET** |
| Operator composer delivery UX enabled | **NOT YET** |
| Architecture + implementation docs | **REAL** (this set) |
