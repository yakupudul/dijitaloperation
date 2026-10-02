# Authenticated Report Share Contract

> Prompt 60 — recipient-specific authenticated share for Report Snapshots.  
> Implementation: `App\Models\ReportShareGrant`, `ReportShareVerificationChallenge`, `ReportShareSession`, `ReportShareAccessEvent`, `ReportShareService`, `ReportShareController`.  
> Support: `App\Support\ReportDelivery\SecretHasher`  
> Config: `config/report_delivery.php` → `share.*`  
> Related: [`REPORT_ARTIFACT_CONTRACT.md`](REPORT_ARTIFACT_CONTRACT.md), [`REPORT_DELIVERY_CONTRACT.md`](REPORT_DELIVERY_CONTRACT.md)

## Canonical rule

An **Authenticated Report Share** grants one named recipient time-bounded access to one Report Snapshot after **email OTP verification**. A locator token **locates** the grant only — it is **not** authorization. There is no public unauthenticated report URL, no client portal login, and no CRM contact graph.

---

## Share Grant

| Field | Contract |
| --- | --- |
| Table / model | `report_share_grants` / `ReportShareGrant` |
| `report_snapshot_id` | Required FK → Snapshot (`restrictOnDelete`) |
| `recipient_email` | Required, normalized lowercase |
| `recipient_name` | Optional sanitized display name |
| `permissions` | JSON: `html_view` (bool), `pdf_download` (bool) |
| `expires_at` | Required; must be future at create; capped by `share.max_ttl_hours` (default 720) |
| `revoked_at` / `revoked_by` | Soft revoke; revoke also marks sessions revoked |
| `created_by` | Optional operator FK |
| `locator_token_hash` | Unique SHA-256 of raw locator (raw never stored in DB) |
| `last_successful_access_at` | Updated on successful OTP verify |
| Active | `revoked_at IS NULL` AND `expires_at` in the future (`isActive()`) |

Default TTLs: `share.default_ttl_hours` = 72 (env `REPORT_SHARE_TTL_HOURS`).

---

## Locator Token

| Rule | Contract |
| --- | --- |
| Purpose | Locate grant via `/reports/share/{token}` |
| Generation | `SecretHasher::randomToken(32)` (URL-safe base64) |
| Storage | **Hash only** in `locator_token_hash` |
| Authorization | **Insufficient alone** — OTP + session required for view/PDF |
| Delivery handoff | Raw locator may be cached briefly for email send (`report-delivery-locator:{deliveryId}`) — **not** a DB plaintext column |

---

## Verification Challenge (OTP)

| Field | Contract |
| --- | --- |
| Table | `report_share_verification_challenges` |
| `code_hash` | SHA-256 of 6-digit OTP (`SecretHasher::otpCode`) |
| TTL | `share.otp_ttl_minutes` (default 15) |
| Attempts | `attempts` counter; lock at `otp_max_attempts` (default 5) → `OTP_LOCKED` |
| Consume | `consumed_at` set on success |
| Rate limit | Per-grant hourly max (`otp_request_max_per_hour`, default 10) + resend cooldown (`otp_resend_cooldown_seconds`, default 60) |
| Email body | Code only — **no report metrics / content** |

---

## Share Session

| Field | Contract |
| --- | --- |
| Table | `report_share_sessions` |
| `session_token_hash` | Unique SHA-256 of raw session token |
| Cookie | `moxdop_report_share_session` (config `share.cookie`), HttpOnly, SameSite=Lax, Secure when HTTPS |
| TTL | `share.session_ttl_minutes` (default 60), clamped to grant `expires_at` |
| Active | Not revoked and not expired |
| Resolve | Cookie → hash lookup → grant must still be active |

---

## Permissions

| Permission | Meaning |
| --- | --- |
| `html_view` | May render `reports.share-view` HTML from frozen Snapshot payload |
| `pdf_download` | May generate/stream PDF via Artifact path after session auth |

Denied permission → `SHARE_PERMISSION_DENIED` / unavailable view.

---

## Access Audit

Append-only `report_share_access_events` (`ReportShareAccessEventType`):

| Event | When |
| --- | --- |
| `verification_requested` | OTP requested |
| `verification_succeeded` | OTP accepted |
| `verification_failed` | Bad/locked OTP |
| `report_viewed` | HTML view |
| `pdf_downloaded` | PDF download |
| `access_denied` | Inactive grant path |
| `grant_revoked` | Operator revoke |

IP / User-Agent stored as **SHA-256 hashes only** (`ip_hash`, `user_agent_hash`).

---

## Routes (external, `web` middleware, no operator auth)

| Method | Path | Name |
| --- | --- | --- |
| GET | `/reports/share/{token}` | `reports.share.locator` |
| GET | `/reports/share/access/verify` | `reports.share.verify.form` |
| POST | `/reports/share/access/verify/request` | `reports.share.verify.request` |
| POST | `/reports/share/access/verify` | `reports.share.verify.submit` |
| GET | `/reports/share/access/view` | `reports.share.view` |
| GET | `/reports/share/access/pdf` | `reports.share.pdf` |

Security response headers: `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, restrictive CSP, `X-Robots-Tag: noindex, nofollow`.

---

## Forbidden

- Public share without OTP
- Storing plaintext locator / OTP / session in DB
- Delivered / Opened / Read tracking states on the grant
- Client portal accounts / SaaS recipient login
- Cross-grant session reuse
- Inventing `ReportShareGrantV2`
