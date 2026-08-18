# Security Logging & Redaction Contract

> Prompt 64 — structured redaction and metadata-only security audit events.  
> Implementation: `App\Support\Security\SecurityRedactor`, `App\Services\Security\SecurityAuditRecorder`, `App\Models\SecurityAuditEvent`, migration `2026_08_16_060000_create_security_audit_events_table.php`  
> Config: `config/moxdop-security.php` → `sensitive_field_names`, `sensitive_headers`  
> Related: [`SECURITY_CREDENTIAL_HARDENING.md`](../implementation/SECURITY_CREDENTIAL_HARDENING.md) · [`CREDENTIAL_SECURITY_CONTRACT.md`](CREDENTIAL_SECURITY_CONTRACT.md) · [`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`](AUTHENTICATED_REPORT_SHARE_CONTRACT.md)

## Canonical rule

Logs, exceptions contexts, and security audit rows may retain **metadata** (ids, kinds, reasons, counts, non-secret statuses). They must **never** retain recoverable credential plaintext, share OTP/locator raw values, or sensitive header values. Redaction is deterministic field/header based — not AI secret discovery.

---

## SecurityRedactor

| Method | Contract |
| --- | --- |
| `redactContext(array)` | Walk nested arrays; redact sensitive keys; convert `EphemeralSecret` to metadata |
| `redactHeaders(array)` | Redact configured sensitive headers |
| `redactString(string, knownSecret)` | Replace known secret substrings with `[REDACTED]` |
| Constant | `SecurityRedactor::REDACTED = '[REDACTED]'` |

Sensitive field examples (config): `access_token`, `refresh_token`, `token`, `api_key`, `api_secret`, `client_secret`, `password`, `application_password`, `authorization`, `cookie`, `otp`, `locator_token`, `session_token`, `developer_token`, `encrypted_payload`, …

Sensitive headers: `authorization`, `cookie`, `set-cookie`, `x-api-key`, `proxy-authorization`, …

False-positive guard: `token_count` is **not** treated as a credential.

---

## security_audit_events

| Column | Purpose |
| --- | --- |
| `kind` | `SecurityAuditEventKind` value |
| `actor_user_id` | Optional operator |
| `customer_id` / `brand_id` | Optional scope |
| `integration_id` / `provider` | Optional integration context |
| `reason` | Short machine reason |
| `metadata` | JSON — **pre-redacted** |
| `created_at` | Event time (no updated_at) |

Kinds include credential lifecycle, integration connect/disconnect, permission/user access changes, share revoke, security setting change, encryption reencrypt batch.

`SecurityAuditRecorder::record()` always runs `SecurityRedactor::redactContext` on metadata before insert and writes a `security.audit` log line without secret fields.

---

## Operational command logging

`moxdop:security:reencrypt-credentials` may print row **ids** on failure and aggregate counts on success. It must not print payload plaintext, ciphertext blobs, or key material.

---

## Relationship to Prompt 60 share audit

Report share access events remain on `report_share_access_events` with hashed IP/UA. That stream is complementary. Security audit covers operator/credential/permission mutations; it does not replace share access telemetry.

---

## CI secret gates (reuse)

Existing `.github/workflows/dop-pr-gate.yml` steps call `.automation/common.py`:

- `find_secret_like_paths`
- `scan_diff_for_credential_leaks`

Prompt 64 does **not** add a commercial scanner SaaS. Dummy `ya29.*` fixtures in tests are allowed; values must not be printed by discovery tooling as “found production secrets.”

---

## Forbidden

- Persisting raw tokens in `metadata` or log context
- AI-based secret scanning inside the redactor
- Emitting `EphemeralSecret` plaintext via `json_encode` / `__toString`
- New parallel audit product that stores recoverable secrets “for forensics”
- Committing real `.env` secrets or printing them in Artisan output
