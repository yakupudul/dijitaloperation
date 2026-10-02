# SECURITY & CREDENTIAL HARDENING

## STATUS: REAL (Prompt 64)

**Prompt:** 64  
**Canonical path:** `docs/implementation/SECURITY_CREDENTIAL_HARDENING.md`  
**Contracts:** [`CREDENTIAL_SECURITY_CONTRACT.md`](../architecture/CREDENTIAL_SECURITY_CONTRACT.md) · [`TENANT_ISOLATION_CONTRACT.md`](../architecture/TENANT_ISOLATION_CONTRACT.md) · [`PERMISSION_BOUNDARY_CONTRACT.md`](../architecture/PERMISSION_BOUNDARY_CONTRACT.md) · [`SECURITY_LOGGING_REDACTION_CONTRACT.md`](../architecture/SECURITY_LOGGING_REDACTION_CONTRACT.md)  
**Depends on:** Prompt 60 Authenticated Report Share · Google/Meta OAuth + credential brokers · Prompt 53 Sector Learning Privacy · existing `CoreIntegrationCredential` / `CoreConnectionCredential`  
**Branch:** `cursor/security-credential-hardening-ea01`  
**Base HEAD:** Prompt 63 `d8ac695` (`fix: include Finding-dependent Opportunity rules in Evidence plans`)

| Fact | Value |
| --- | --- |
| Credential stores | `CoreIntegrationCredential` + `CoreConnectionCredential` (`encrypted:array`) |
| CredentialV2 / RBACV2 / TenantV2 | **NONE** |
| Access boundary | `IntegrationCredentialAccessService` + `ConnectionCredentialAccessService` |
| Provider brokers | `GoogleCredentialBroker` + `MetaCredentialBroker` |
| Ephemeral plaintext holder | `EphemeralSecret` (never serializes value) |
| Log / audit redaction | `SecurityRedactor` |
| Tenant object guard | `TenantScopeGuard` |
| Share / OTP hashing | `SecretHasher` SHA-256 (non-recoverable) |
| Key rotation | `APP_PREVIOUS_KEYS` + `moxdop:security:reencrypt-credentials` |
| Audit table | `security_audit_events` (metadata only) |
| Config | `config/moxdop-security.php` |
| New top-level Security nav | **NONE** |
| Sector Learning cross-Brand | **Only** privileged privacy-qualified exception |
| AI credential access | **FORBIDDEN** |
| New secret-scanner SaaS | **NONE** (reuse CI gates) |

---

## 1. Purpose

Prompt 64 hardens how MoxDOP stores, classifies, reveals, rotates, logs, and scopes secrets and tenant objects. It reuses the existing encrypted credential rows and provider brokers, adds a purpose-tagged access boundary (`EphemeralSecret`), server-side tenant consistency checks, structured redaction, metadata-only security audit events, and a safe re-encryption command. It does not invent `CredentialV2`, a second RBAC system, a SaaS tenant product, or a new top-level Security navigation.

```text
Existing encrypted credential rows
  → Access services / Google|Meta brokers
    → EphemeralSecret (request-scoped plaintext)
      → Provider HTTP / probes only
        → SecurityRedactor + security_audit_events (metadata)
```

---

## 2. Scope

In scope:

- Secret taxonomy (`SecretClass`) and storage rules per class
- Reuse of `CoreIntegrationCredential` / `CoreConnectionCredential`
- Canonical access services + Meta/Google brokers
- `EphemeralSecret`, `SecurityRedactor`, `TenantScopeGuard`
- Share/OTP hashing vs recoverable OAuth encryption boundary
- `APP_PREVIOUS_KEYS` + batched re-encrypt command
- Google OAuth state cache keyed by `state_hash`
- Meta Graph client + WordPress probe credential wiring
- `security_audit_events` + `SecurityAuditRecorder`
- Config / `.env.example` placeholders
- PHPUnit feature coverage and four architecture contracts

Out of scope:

- `CredentialV2` / `ProviderCredentialV2` / `IntegrationSecretV2`
- Custom crypto primitives replacing Laravel `Crypt`
- New Filament top-level Security nav or Security SaaS product
- External write actions / marketplace secret vaults
- New commercial secret-scanning SaaS
- Changing Sector Learning privacy thresholds (Prompt 53 owns them)
- Prompt 65+ product surfaces that consume these contracts

---

## 3. Authority Model

| Rank | Source |
| --- | --- |
| 1 | `docs/MASTER_SPEC.md` + accepted ADRs |
| 2 | Existing Google/Meta/WordPress/Report Share credential paths |
| 3 | This implementation doc + four architecture contracts |
| 4 | `config/moxdop-security.php` + environment placeholders |
| 5 | Operator `web` guard + Spatie permissions (no parallel RBAC) |

---

## 4. Hard Product Rules

| # | Rule |
| --- | --- |
| R1 | Recoverable provider/connection secrets stay in existing encrypted credential tables — no CredentialV2 |
| R2 | Plaintext is request-scoped via `EphemeralSecret` / broker reveal — never UI dump, never queue payload |
| R3 | Share locator / OTP / share session secrets are SHA-256 hashed (`SecretHasher`) — non-recoverable |
| R4 | OAuth access/refresh tokens are Laravel `encrypted:array` — recoverable under `APP_KEY` |
| R5 | Agents / Assistants / AI routes cannot access credentials (`forbid_agent_credential_access`) |
| R6 | Browser/controllers must not reveal plaintext credentials (`forbid_plaintext_credential_view`) |
| R7 | Authorization never trusts forged Customer/Brand/Asset ID combinations alone (`TenantScopeGuard`) |
| R8 | Sector Learning remains the only privileged cross-Brand aggregate exception (privacy-gated) |
| R9 | Security audit stores metadata only; `SecurityRedactor` strips secret-shaped fields |
| R10 | Re-encryption never prints secrets/keys; keys never accepted as CLI args |
| R11 | No new top-level Security Filament navigation in Prompt 64 |
| R12 | Existing CI secret gates are reused — no new scanner SaaS |

---

## 5. Base HEAD and Branch

| Item | Value |
| --- | --- |
| Base | Prompt 63 `d8ac695` |
| Branch | `cursor/security-credential-hardening-ea01` |
| Primary config | `config/moxdop-security.php` |
| Feature tests | `tests/Feature/Security/SecurityCredentialHardeningTest.php` |

---

## 6. Prompt 63 Boundary / Input Audit

Prompt 63 Intelligence Scheduling must not become a credential consumer. Prompt 64 does not add CollectionRun→credential triggers, Agent swarm graphs, or automatic AI candidate promotion (Prompt 63 handoff constraint). Credential hardening owns secret storage/access/redaction only.

| Prompt 63 topic | Prompt 64 stance |
| --- | --- |
| Analyzer scheduling | Untouched |
| Agent planner | Still cannot call credential access |
| CollectionRun as intelligence trigger | Remains **FORBIDDEN**; unrelated to credential APIs |

---

## 7. Existing Credential Primitive Audit

| Primitive | Pre-64 | Prompt 64 |
| --- | --- | --- |
| `CoreIntegrationCredential` | Provider/authorization payloads via `encrypted:array` | **Reused** — canonical recoverable store |
| `CoreConnectionCredential` | WordPress/legacy connection payloads | **Reused** — connection recoverable store |
| Google/Meta resolvers | Direct decrypt for adapters | Still used; access mediated by brokers/access services |
| `SecretHasher` (Prompt 60) | Share locator/OTP/session hashes | Affirmed as non-recoverable class |
| CredentialV2 | Did not exist | Still **NONE** (test-locked) |

---

## 8. Existing Encryption Audit

| Mechanism | Role |
| --- | --- |
| Laravel `encrypted:array` cast | At-rest ciphertext for `encrypted_payload` |
| `APP_KEY` | Active encryption key |
| `APP_PREVIOUS_KEYS` | Decrypt during rotation windows (env only) |
| `Crypt` facade | Used by casts / re-encrypt path — no custom AES wrapper |
| SHA-256 `SecretHasher` | One-way for share/OTP/session — not encryption |

---

## 9. Existing OAuth / Broker Audit

| Component | Prompt 64 change |
| --- | --- |
| `GoogleCredentialBroker` | Remains sole Google access-token boundary (returns string for existing callers; access service wraps `EphemeralSecret`) |
| `MetaCredentialBroker` | **Added/affirmed** — returns `EphemeralSecret`; `MetaApiClient` depends on it |
| `GoogleOAuthService` state cache | Keys by `state_hash`, never raw state |
| Authorization attempt DB | Continues to store `state_hash` |

---

## 10. Existing Share Secret Audit

Prompt 60 contract (`AUTHENTICATED_REPORT_SHARE_CONTRACT.md`):

- Locator, OTP, session tokens: hashed with `SecretHasher::hash` (SHA-256)
- Raw locator may exist briefly in `report-delivery-locator:{id}` cache for email send only
- DB never stores recoverable share secrets

Prompt 64 classifies these as `NON_RECOVERABLE_AUTH_SECRET` and leaves hashing unchanged (`MOXDOP_SHARE_SECRET_PEPPER_ENABLED=false` until dual-read migration exists).

---

## 11. Existing Permission Audit

| Primitive | Status |
| --- | --- |
| Spatie `spatie/laravel-permission` | **REAL** — `web` guard |
| `Permissions::ACCESS_APP` | Minimal core permission |
| Filament panel `app` | Single technical/admin panel path `/admin` (ADR-044) |
| RBACV2 | **NONE** |
| AI permission mutation | **FORBIDDEN** (`forbid_ai_permission_mutation`) |

Prompt 64 documents permission boundaries; it does not replace Spatie with a new ACL engine.

---

## 12. Existing Tenant Isolation Audit

MoxDOP is internal agency operations (not SaaS customer login). Isolation is Customer → Brand → DigitalAsset hierarchy plus Integration/Connection ownership. Prompt 64 adds `TenantScopeGuard` so request-supplied IDs cannot forge cross-customer/brand/asset combinations.

---

## 13. Existing Logging / Audit Trail Audit

Pre-64: ad-hoc logs; report share has its own access events; integration flows log statuses. Prompt 64 adds:

- `SecurityRedactor` for structured contexts/headers
- `security_audit_events` + `SecurityAuditRecorder` for security-significant mutations (metadata only)

---

## 14. Frozen Product Surface Audit

| Surface | Prompt 64 |
| --- | --- |
| New top-level Filament “Security” nav | **NONE** |
| Settings cluster “future Security pages” | Mentioned historically; **not** delivered here |
| Credential plaintext reveal UI | **FORBIDDEN** |
| Demo CredentialV2 screens | **NONE** |

---

## 15. Canonical Architecture Decision

Keep one recoverable credential persistence model (existing Core*Credential tables + Laravel encryption). Introduce a thin hardening layer:

1. Classification enum + config taxonomy  
2. Purpose-specific access services / brokers  
3. Ephemeral plaintext holder  
4. Tenant scope consistency  
5. Redaction + metadata audit  
6. Operational re-encrypt command  

Do not fork storage into a parallel credential product.

---

## 16. No CredentialV2 / No Custom Crypto

Feature tests assert absence of:

- `App\Models\CredentialV2`
- `App\Models\RBACV2`
- `App\Models\TenantV2`
- `App\Services\Security\CredentialV2`
- `App\Services\Security\ProviderCredentialV2`
- `App\Services\Security\IntegrationSecretV2`

Encryption remains Laravel Crypt / model casts.

---

## 17. Secret Classification Taxonomy

Enum `App\Enums\Security\SecretClass`:

| Case | Value |
| --- | --- |
| `RecoverableCredential` | `RECOVERABLE_CREDENTIAL` |
| `NonRecoverableAuthSecret` | `NON_RECOVERABLE_AUTH_SECRET` |
| `DeploymentSecret` | `DEPLOYMENT_SECRET` |
| `NonSecretSecurityMetadata` | `NON_SECRET_SECURITY_METADATA` |

Documented in `config/moxdop-security.php` comments — not an EAV store.

---

## 18. RECOVERABLE_CREDENTIAL

Examples: OAuth `access_token` / `refresh_token`, Meta tokens, API keys, client secrets, developer token (app config), WordPress application passwords, DataForSEO password.

Storage: `encrypted_payload` on Core*Credential (or env for deployment-bootstrapped app secrets). Access: brokers / access services → `EphemeralSecret` (or broker string then wrap). UI: presence/status only.

---

## 19. NON_RECOVERABLE_AUTH_SECRET

Examples: report share locator, OTP codes, share session tokens.

Storage: SHA-256 hashes only (`SecretHasher`). Optional pepper reserved (`share_secret_pepper_enabled`) — **off** by default. Transient raw locator cache allowed for send path only.

---

## 20. DEPLOYMENT_SECRET

Examples: `APP_KEY`, `APP_PREVIOUS_KEYS`, `GOOGLE_CLIENT_SECRET`, `META_APP_SECRET`, provider env API keys in `.env`.

Storage: environment / secrets manager — never committed. Re-encrypt command reads keys from environment only.

---

## 21. NON_SECRET_SECURITY_METADATA

Examples: `expires_at`, scopes lists, auth status enums, `provider_account_id`, configured/source labels, audit `kind` / reason codes, redacted metadata.

Safe for UI and audit rows when not secret-shaped.

---

## 22. CoreIntegrationCredential Persistence

Model: `App\Models\CoreIntegrationCredential`  
Table: `core_integration_credentials`  
Types: `provider`, `authorization`  
Cast: `encrypted_payload` → `encrypted:array`  
Hidden: `encrypted_payload` excluded from `toArray()` / serialization  

---

## 23. CoreConnectionCredential Persistence

Model: `App\Models\CoreConnectionCredential`  
Table: `core_connection_credentials`  
Cast: `encrypted_payload` → `encrypted:array`  
Hidden: `encrypted_payload`  
Typical WordPress payload keys: `username`, `application_password` (password is recoverable secret; username is operational identifier)

---

## 24. Laravel encrypted:array Cast

Ciphertext in DB must not contain plaintext substrings (covered by feature test). Decrypt happens only in PHP process memory when the attribute is read. Re-encrypt rewrites ciphertext under current `APP_KEY` after decrypt via previous keys.

---

## 25. Model Hidden / Serialization Guards

`#[Hidden(['encrypted_payload'])]` prevents accidental API/resource leakage. `EphemeralSecret::toArray()` / `__debugInfo()` / `__toString()` never include plaintext. Controllers must use status DTOs, not credential models for display.

---

## 26. IntegrationCredentialAccessService

Canonical purpose-specific readers:

- `googleAccessToken` / `googleClientSecret`
- `metaAccessToken`
- `openAiApiKey` / `anthropicApiKey` / `geminiApiKey`
- `dataForSeoBasicAuth`
- `statusFor` (presence only)
- `denyAgentAccess` (hard deny)
- `secretClassFor` field taxonomy helper

No `dumpAllCredentials()`.

---

## 27. ConnectionCredentialAccessService

- `wordpressApplicationPassword` → `EphemeralSecret`
- `wordpressUsername` → string (non-secret identifier)
- `accessTokenForProbe` → ephemeral
- `status` → booleans only
- `denyBrowserReveal` → hard deny

WordPress probe uses this service exclusively for password material.

---

## 28. GoogleCredentialBroker

Sole application boundary for usable Google access tokens (`accessTokenFor`). Checks auth status + optional capability scope coverage. Ads developer token remains application/env boundary (`adsDeveloperToken`). Does not put tokens in queues/UI/logs.

---

## 29. MetaCredentialBroker

Sole Meta access-token boundary. Returns `EphemeralSecret` with purpose `meta_provider_request`. Throws `MetaException` on wrong provider / missing token. `isConfigured` delegates to resolver tenant authorization presence.

---

## 30. MetaApiClient Wiring

`MetaApiClient` constructor-injects `MetaCredentialBroker` and reveals the ephemeral token only inside the HTTP request path (`withToken`). No alternate direct DB decrypt path in the client.

---

## 31. WordPress Probe Wiring

`WordPressConnectionProbeService` resolves username/password through `ConnectionCredentialAccessService`. Empty credentials yield empty probe result rather than logging secrets.

---

## 32. EphemeralSecret Contract

Fields: private `value`, public `purpose`, optional `provider`, `integrationId`, `connectionId`.

| Method | Behavior |
| --- | --- |
| `reveal()` | Returns plaintext for authorized caller |
| `toArray()` | Metadata + `present` only |
| `__toString()` | `[REDACTED_EPHEMERAL_SECRET]` |
| `__debugInfo()` | Same as `toArray()` |

---

## 33. Plaintext View Forbidden

Config: `forbid_plaintext_credential_view = true`.  
`ConnectionCredentialAccessService::denyBrowserReveal()` throws `PLAINTEXT_CREDENTIAL_VIEW_FORBIDDEN`. Integration UI shows configured/source labels and “tokens stored securely” style states — not raw tokens.

---

## 34. AI / Agent Credential Access Forbidden

Config: `forbid_agent_credential_access = true`, `forbid_ai_permission_mutation = true`.  
`IntegrationCredentialAccessService::denyAgentAccess($caller)` throws `AI_CREDENTIAL_ACCESS_FORBIDDEN:{caller}`. Agents may use Evidence/tools per Prompt 50 rules but never credential decrypt APIs.

---

## 35. Access Token / Refresh Token Handling

Recoverable tokens live in authorization/provider credential payloads. Google refresh path remains `GoogleOAuthService` with refresh lock semantics. Access services expose purpose-tagged reads; refresh tokens are not returned to UI. Expiry metadata is non-secret.

---

## 36. Key Rotation (APP_KEY / APP_PREVIOUS_KEYS)

`.env.example` documents comma-separated previous keys during migration. Operators rotate `APP_KEY`, keep previous values in `APP_PREVIOUS_KEYS`, run re-encrypt, then clear previous keys. Keys never appear as Artisan arguments.

---

## 37. moxdop:security:reencrypt-credentials

Command: `App\Console\Commands\Security\ReencryptCredentialsCommand`

| Option | Meaning |
| --- | --- |
| `--dry-run` | Decrypt/count only — no writes |
| `--batch=` | Chunk size (default 50) |
| `--limit=` | Max rows (0 = all) |

Writes re-assign `encrypted_payload` to force re-encrypt under current key. Failures log row IDs only. Successful write batches record `ENCRYPTION_REENCRYPT_BATCH` audit metadata (counts + crypt driver name — never key material).

---

## 38. Google OAuth State Hash Cache

Compatibility cache prefix `google_oauth_state:` now suffixes **state_hash**, not raw state. DB attempts already store `state_hash`. Config flag `oauth_state_cache_uses_hash = true` documents the invariant.

---

## 39. Share / OTP SecretHasher Boundary

`App\Support\ReportDelivery\SecretHasher`: `hash`, `equals` (hash_equals), `randomToken`, `otpCode`. Hashes are not Laravel ciphertext (tests assert no `eyJ` prefix). Distinct from recoverable OAuth encryption.

---

## 40. Transient Locator Cache

`CreateReportDeliveryService` may `cache()->put('report-delivery-locator:'.$deliveryId, $rawLocator, …)` for send. Config `allow_transient_locator_cache = true`. Not a durable secret store; DB keeps hash only.

---

## 41. TenantScopeGuard

`assertBrandAuthorized`, `assertBrandBelongsToCustomer`, `assertAssetBelongsToBrand`, `resolveConsistentScope`.

Forged Customer A + Brand B → `BRAND_CUSTOMER_MISMATCH` / `UNAUTHORIZED_*` via `ValidationException`. Consistent triples resolve models for server use.

---

## 42. Permission Boundaries (Spatie + flags)

Operator access continues via Filament + `Permissions::ACCESS_APP` (+ module permissions). Prompt 64 does not add Security-admin role matrix UI. AI cannot mutate permissions. Credential access is server-side capability, not a browser permission string that reveals secrets.

---

## 43. Sector Learning Cross-Brand Exception

Only privacy-qualified Sector Learning aggregates (Prompt 53) may cross Brand boundaries — as **cohort observations**, never raw Brand Memory or credentials. Contribution repositories / lineage remain privileged infrastructure. Prompt 64 does not widen this exception to credentials or Evidence dumps.

---

## 44. SecurityRedactor

Redacts configured sensitive field names and headers; walks nested arrays; converts `EphemeralSecret` to metadata arrays; supports `redactString` for known secret scrubbing. Avoids false positives on `token_count`. Does **not** perform AI-based secret discovery.

---

## 45. security_audit_events + SecurityAuditRecorder

Migration `2026_08_16_060000_create_security_audit_events_table.php`.  
Model `SecurityAuditEvent`.  
Kinds: `SecurityAuditEventKind` (credential create/rotate/revoke, integration connect/disconnect, permission/user access changes, share revoke, security setting change, encryption reencrypt batch).

Recorder always runs metadata through `SecurityRedactor` before persist and emits `security.audit` log without secret fields.

---

## 46. Config and Environment Placeholders

`config/moxdop-security.php` toggles + sensitive field/header lists.  
`.env.example` adds: `APP_PREVIOUS_KEYS`, AI/DataForSEO placeholders, `MOXDOP_SECURITY_HARDENING_ENABLED`, `MOXDOP_SHARE_SECRET_PEPPER_ENABLED`. No real secrets committed.

---

## 47. Tests

Canonical: `tests/Feature/Security/SecurityCredentialHardeningTest.php`.

Covers: at-rest encryption + hidden arrays; ephemeral serialization; redactor; Meta broker ephemeral; WordPress status hiding; tenant forge reject/accept; share hash non-recoverable; audit redaction; agent deny; SecretClass taxonomy + no V2 classes; reencrypt dry-run silent; no custom CredentialV2 services.

Related fixture posture: Google probe tests use dummy `ya29.*` tokens and assert they do not appear in encoded/log outputs.

---

## 48. Explicit Non-Goals / UI

| Non-goal | Status |
| --- | --- |
| Top-level Security nav | **NONE** |
| Credential plaintext viewer | **FORBIDDEN** |
| CredentialV2 product | **NONE** |
| New secret-scanner SaaS | **NONE** |
| SaaS customer login / workspace switcher | **NONE** |
| External write / vault marketplace | **NONE** |
| Enabling share pepper without migration | **FORBIDDEN** by default flag |

---

## 49. Credential Inventory Matrix

| Secret / material | Owner store | Access path |
| --- | --- | --- |
| Google access/refresh tokens | `CoreIntegrationCredential` authorization | `GoogleCredentialBroker` / `IntegrationCredentialAccessService` |
| Google client secret (optional DB) | Provider credential / env | `googleClientSecret` / resolver |
| Google Ads developer token | Env / app config | `adsDeveloperToken` |
| Meta access token | Provider credential | `MetaCredentialBroker` |
| Meta app secret | Env | Meta config / appsecret_proof |
| OpenAI / Anthropic / Gemini API keys | Integration credential / env | Access service wrappers |
| DataForSEO login/password | Integration credential / env | `dataForSeoBasicAuth` |
| WordPress application password | `CoreConnectionCredential` | `ConnectionCredentialAccessService` |
| Report share locator/OTP/session | Hash columns + optional cache | `SecretHasher` / Prompt 60 services |
| `APP_KEY` / previous keys | Environment | Laravel Crypt / reencrypt command |

---

## 50. Classification Matrix

| Material | SecretClass |
| --- | --- |
| OAuth tokens, API keys, app passwords, client secrets | `RECOVERABLE_CREDENTIAL` |
| Share locator, OTP, share session token | `NON_RECOVERABLE_AUTH_SECRET` |
| `APP_KEY`, provider env secrets | `DEPLOYMENT_SECRET` |
| expires_at, scopes, status, configured flags | `NON_SECRET_SECURITY_METADATA` |

---

## 51. Storage Matrix

| Class | Durable storage |
| --- | --- |
| Recoverable | DB ciphertext `encrypted_payload` or env |
| Non-recoverable auth | SHA-256 hash columns only |
| Deployment | Host/env secrets — never git |
| Metadata | Plain columns / JSON metadata (redacted if secret-shaped) |

---

## 52. Encryption Matrix

| Concern | Mechanism | Status |
| --- | --- | --- |
| Recoverable payload encryption | Laravel `encrypted:array` | **REAL** |
| Custom CredentialV2 crypto | — | **NONE** |
| Share secret encryption | — | **FORBIDDEN** (hash instead) |
| Key rotation assist | `APP_PREVIOUS_KEYS` + reencrypt command | **REAL** |
| Reencrypt prints secrets | — | **FORBIDDEN** |

---

## 53. Token Matrix

| Token kind | Recoverable? | Reveal path |
| --- | --- | --- |
| Google/Meta access token | Yes (encrypted) | Broker / access service → ephemeral |
| Google refresh token | Yes (encrypted) | OAuth service only |
| Share locator | No (hash) | Raw only at create/send cache |
| OTP | No (hash) | Email body once; DB hash |
| Share session | No (hash) | Cookie raw; DB hash |

---

## 54. Rotation Matrix

| Action | Tooling | Notes |
| --- | --- | --- |
| APP_KEY rotate | Env + `moxdop:security:reencrypt-credentials` | Dry-run first; batched |
| OAuth reconnect | Existing Google/Meta OAuth flows | Audit kinds available |
| Share revoke | Prompt 60 revoke | `SHARE_REVOKED` kind reserved |
| Pepper enable | Dual-read migration required | Default **off** |

---

## 55. OAuth Matrix

| Rule | Status |
| --- | --- |
| State stored/looked up by hash | **REAL** |
| Cache keyed by raw state | **FORBIDDEN** |
| Meta client uses MetaCredentialBroker | **REAL** |
| Tokens in logs | **FORBIDDEN** (redactor + broker discipline) |
| DPoP / secondary OAuth product | **NONE** (unchanged prior decisions) |

---

## 56. Permission Matrix

| Actor | Credential decrypt | Permission mutate | Tenant forge |
| --- | --- | --- | --- |
| Operator UI | Status only | Via Spatie admin flows (human) | Guard rejects inconsistent IDs |
| Server adapters | Purpose-specific yes | N/A | Server resolves scope |
| Agent / AI | **FORBIDDEN** | **FORBIDDEN** | N/A |
| Report share recipient | No integration creds | N/A | Grant-scoped only |

---

## 57. Tenant Object Matrix

| Object | Isolation rule |
| --- | --- |
| Customer | Root tenant for Brands |
| Brand | Must belong to Customer (`TenantScopeGuard`) |
| DigitalAsset | Must belong to Brand |
| Integration | Provider-scoped; tokens not cross-tenant portable via UI |
| Connection | Asset-scoped credentials |
| Sector aggregate | Privacy-gated cross-Brand **exception only** |

---

## 58. Background Job Matrix

| Rule | Status |
| --- | --- |
| Serialize `EphemeralSecret` plaintext into job payload | **FORBIDDEN** |
| Pass integration/connection IDs; decrypt inside job | **REAL** pattern |
| Reencrypt command as sync Artisan (batched) | **REAL** |
| Queue logs include tokens | **FORBIDDEN** |

---

## 59. Cache Matrix

| Cache entry | Allowed content |
| --- | --- |
| `google_oauth_state:{state_hash}` | Integration/user/scopes metadata — not raw state key |
| `report-delivery-locator:{id}` | Short-lived raw locator for send only |
| Durable recoverable tokens in cache | **FORBIDDEN** as primary store |

---

## 60. Log Redaction Matrix

| Input | Output |
| --- | --- |
| `access_token` / `refresh_token` / `api_key` / `password` / `otp` / … | `[REDACTED]` |
| Sensitive headers (`Authorization`, `Cookie`, …) | `[REDACTED]` |
| `token_count` | Preserved (non-secret) |
| `EphemeralSecret` in context | Metadata array |
| Audit metadata | Pre-redacted before DB write |

---

## 61. Sector Exception Matrix

| Cross-Brand pattern | Status |
| --- | --- |
| Privacy-qualified Sector Learning consumer DTO | **ALLOWED** (Prompt 53) |
| Raw Brand Memory share | **FORBIDDEN** |
| Credential reuse across Brands via Sector path | **FORBIDDEN** |
| Agent reading contribution lineage | **FORBIDDEN** |
| Other “global insights” bypassing privacy gate | **FORBIDDEN** |

---

## 62. File Matrix

| Concern | Rule |
| --- | --- |
| Operator files (`OperatorFilePolicy`) | `ACCESS_APP`; not a secret vault |
| PDF artifacts (Prompt 60) | Private disk; not credential store |
| Writing tokens into uploaded files | **FORBIDDEN** |
| Encryption of arbitrary file blobs as CredentialV2 | **NONE** |

---

## 63. IDOR Matrix

| Attack | Defense |
| --- | --- |
| Customer A + Brand B IDs in one request | `TenantScopeGuard::resolveConsistentScope` |
| Brand not in allowlist | `assertBrandAuthorized` |
| Asset from another Brand | `assertAssetBelongsToBrand` |
| Guess share locator | Hash lookup + OTP + session (Prompt 60) |
| Direct credential row ID in UI | Hidden payload + no plaintext view |

---

## 64. Security Automation Matrix

| Automation | Status |
| --- | --- |
| `dop-pr-gate` secret path + diff leak scan | **REAL** (reused) |
| `find_secret_like_paths` / `scan_diff_for_credential_leaks` | **REAL** |
| New commercial scanner SaaS | **NONE** |
| AI secret discovery in redactor | **NONE** |
| Reencrypt scheduled job | **NONE** (manual/ops command) |

---

## 65. Demo / Secret Audit Matrix

| Check | Result |
| --- | --- |
| Dummy `ya29.*` fixtures in Google tests | Present; asserted absent from encoded outputs |
| Real production tokens in repo | Must remain absent (CI gates) |
| Reencrypt / audit output prints secrets | **FORBIDDEN** / tested |
| CredentialV2 demo entities | **NONE** |
| Values printed by discovery tooling in Prompt 64 docs/tests | Never |

---

## 66. Prompt65 Handoff Matrix

| Topic | Prompt 64 delivers | Prompt 65+ may own |
| --- | --- | --- |
| Credential storage/access contracts | **YES** | Must reuse Core*Credential + access services |
| Security audit metadata stream | **YES** | Optional operator views under Settings — no CredentialV2 |
| TenantScopeGuard | **YES** | Broader form request adoption |
| Redaction helpers | **YES** | Wider log pipeline wiring |
| Sector privacy thresholds | Prompt 53 | Do not reopen via “security” prompt |
| Intelligence scheduling triggers | Prompt 63 | Do not add credential-triggered swarms |
| Top-level Security nav / vault product | **NONE** | Only if product blueprint explicitly adds it |

Prompt 65 must not introduce CredentialV2, plaintext credential browsers, or AI credential tools.

---

## 67. Reality Matrix

| Capability | Status |
| --- | --- |
| `CoreIntegrationCredential` + `CoreConnectionCredential` reused | **REAL** |
| `encrypted:array` at-rest encryption | **REAL** |
| `CredentialV2` / parallel crypto | **NONE** / **FORBIDDEN** |
| `IntegrationCredentialAccessService` | **REAL** |
| `ConnectionCredentialAccessService` | **REAL** |
| `GoogleCredentialBroker` | **REAL** |
| `MetaCredentialBroker` + `MetaApiClient` wiring | **REAL** |
| WordPress probe via connection access service | **REAL** |
| `EphemeralSecret` non-serializing plaintext | **REAL** |
| `SecurityRedactor` | **REAL** |
| `TenantScopeGuard` | **REAL** |
| `SecretHasher` SHA-256 share/OTP | **REAL** |
| Share secrets reversibly encrypted | **FORBIDDEN** |
| `APP_PREVIOUS_KEYS` documented | **REAL** |
| `moxdop:security:reencrypt-credentials` dry-run/batched | **REAL** |
| Google OAuth cache by `state_hash` | **REAL** |
| `security_audit_events` metadata-only | **REAL** |
| AI/Agent credential access | **FORBIDDEN** |
| AI permission mutation | **FORBIDDEN** |
| Plaintext credential UI view | **FORBIDDEN** |
| Sector Learning as only privileged cross-Brand exception | **REAL** |
| New top-level Security nav | **NONE** |
| New secret-scanner SaaS | **NONE** |
| CI secret gates reused | **REAL** |
| Feature test suite Prompt 64 | **REAL** |

---

## 68. Definition of Done

Prompt 64 is **DONE** when Reality Matrix statuses match the implemented code on base Prompt 63 HEAD `d8ac695`: existing Core credential tables remain the only recoverable stores (no CredentialV2); access services + Google/Meta brokers + WordPress probe paths mediate secrets via `EphemeralSecret` where required; share/OTP stay SHA-256 hashed; OAuth state cache uses hashes; redaction + metadata-only `security_audit_events` exist; re-encrypt command supports dry-run without printing secrets; tenant forge combinations fail closed; AI credential access is denied; Sector Learning remains the sole privileged cross-Brand exception; no new top-level Security nav or scanner SaaS; `.env.example` / `config/moxdop-security.php` document toggles; PHPUnit `SecurityCredentialHardeningTest` passes; and the four architecture contracts in `docs/architecture/` reflect this reality.
