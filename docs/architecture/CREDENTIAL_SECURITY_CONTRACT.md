# Credential Security Contract

> Prompt 64 — recoverable vs non-recoverable secrets, storage, access brokers, rotation.  
> Implementation: `CoreIntegrationCredential`, `CoreConnectionCredential`, `IntegrationCredentialAccessService`, `ConnectionCredentialAccessService`, `GoogleCredentialBroker`, `MetaCredentialBroker`, `EphemeralSecret`, `ReencryptCredentialsCommand`  
> Config: `config/moxdop-security.php`  
> Related: [`SECURITY_CREDENTIAL_HARDENING.md`](../implementation/SECURITY_CREDENTIAL_HARDENING.md) · [`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`](AUTHENTICATED_REPORT_SHARE_CONTRACT.md) · [`TENANT_ISOLATION_CONTRACT.md`](TENANT_ISOLATION_CONTRACT.md)

## Canonical rule

Recoverable provider and connection secrets persist only in existing encrypted Core credential rows (or deployment env). Plaintext exists solely inside authorized server adapters via purpose-tagged `EphemeralSecret` (or equivalent broker reveal). Share/OTP/session secrets are one-way hashed. There is **no** `CredentialV2`.

---

## Secret classes

| Class | Storage | Recoverable |
| --- | --- | --- |
| `RECOVERABLE_CREDENTIAL` | `encrypted:array` payload or env | Yes (under `APP_KEY`) |
| `NON_RECOVERABLE_AUTH_SECRET` | SHA-256 hash (+ optional future pepper) | No |
| `DEPLOYMENT_SECRET` | Environment / host secrets | Ops only |
| `NON_SECRET_SECURITY_METADATA` | Plain columns / safe JSON | N/A |

---

## Persistence

| Model | Table | Payload cast | Hidden |
| --- | --- | --- | --- |
| `CoreIntegrationCredential` | `core_integration_credentials` | `encrypted_payload` → `encrypted:array` | `encrypted_payload` |
| `CoreConnectionCredential` | `core_connection_credentials` | `encrypted_payload` → `encrypted:array` | `encrypted_payload` |

Credential types on integration rows: `provider`, `authorization`.

---

## Access boundaries

| Boundary | Responsibility |
| --- | --- |
| `IntegrationCredentialAccessService` | Purpose-specific Google/Meta/AI/DataForSEO reads + status + agent deny |
| `ConnectionCredentialAccessService` | WordPress / connection probe secrets + status + browser deny |
| `GoogleCredentialBroker` | Usable Google access token (+ Ads readiness helpers) |
| `MetaCredentialBroker` | Usable Meta access token as `EphemeralSecret` |

Rules:

- No `dumpAllCredentials()`
- UI receives presence/source/status only
- `MetaApiClient` obtains tokens only through `MetaCredentialBroker`
- WordPress probe obtains application passwords only through `ConnectionCredentialAccessService`

---

## EphemeralSecret

| API | Contract |
| --- | --- |
| `reveal()` | Authorized plaintext |
| `toArray()` / `__debugInfo()` | `purpose`, `provider`, ids, `present` — **never** `value` |
| `__toString()` | `[REDACTED_EPHEMERAL_SECRET]` |
| Queue / cache serialization of plaintext | **FORBIDDEN** |

---

## Share / OTP vs OAuth tokens

| Material | Mechanism |
| --- | --- |
| Report locator / OTP / share session | `SecretHasher` SHA-256 (`NON_RECOVERABLE_AUTH_SECRET`) |
| OAuth access/refresh | Laravel encryption (`RECOVERABLE_CREDENTIAL`) |
| Transient locator cache | Allowed for email send only (`report-delivery-locator:{id}`) |
| Share pepper | Disabled until dual-read migration (`MOXDOP_SHARE_SECRET_PEPPER_ENABLED`) |

---

## Rotation

1. Set new `APP_KEY`; keep prior values in `APP_PREVIOUS_KEYS`.  
2. Run `php artisan moxdop:security:reencrypt-credentials --dry-run`.  
3. Run without `--dry-run` (batched).  
4. Clear `APP_PREVIOUS_KEYS` after success.  

Command never accepts keys as CLI args and never prints secret or key material. Optional audit kind: `ENCRYPTION_REENCRYPT_BATCH`.

---

## OAuth state

Google compatibility cache keys **must** use `state_hash`, never raw OAuth `state`. Authorization attempts persist `state_hash`.

---

## Forbidden

- `CredentialV2` / `ProviderCredentialV2` / `IntegrationSecretV2`
- Custom crypto replacing Laravel Crypt
- Plaintext credential browser/UI
- Agent/AI credential decrypt
- Logging recoverable tokens
- Storing share locator/OTP in recoverable encrypted columns as primary auth secret
