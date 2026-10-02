# Permission Boundary Contract

> Prompt 64 — operator permissions vs server credential capability vs AI denial.  
> Implementation: Spatie `laravel-permission`, `App\Support\Permissions`, Filament panel `app`, `config/moxdop-security.php` flags, `IntegrationCredentialAccessService::denyAgentAccess`  
> Related: [`SECURITY_CREDENTIAL_HARDENING.md`](../implementation/SECURITY_CREDENTIAL_HARDENING.md) · [`TENANT_ISOLATION_CONTRACT.md`](TENANT_ISOLATION_CONTRACT.md) · [`CREDENTIAL_SECURITY_CONTRACT.md`](CREDENTIAL_SECURITY_CONTRACT.md)

## Canonical rule

Human operators authenticate on the operator product (`/login`, root Livewire routes) using the `web` guard and Spatie permissions. The single Filament panel (`id: app`, path `/admin`) is technical/admin tooling only (ADR-044). **Credential decryption is not a UI permission that returns plaintext** — it is a server-side adapter capability. AI Agents and Assistants cannot access credentials or mutate permissions.

---

## Operator permission model

| Primitive | Contract |
| --- | --- |
| Guard | `web` |
| Package | `spatie/laravel-permission` |
| Core permission | `Permissions::ACCESS_APP` (`access.app`) |
| Panel | One Filament panel — no parallel admin product |
| RBACV2 | **NONE** |

Modules may register additional permissions; Prompt 64 does not replace Spatie with a custom ACL engine.

---

## Capability planes

| Plane | Who | May decrypt secrets? | May mutate roles/permissions? |
| --- | --- | --- | --- |
| Operator UI | Authenticated staff | **No** plaintext — status/configured only | Human admin flows only |
| Server adapters / brokers | PHP services | Purpose-specific yes | N/A |
| Background jobs | Workers | Decrypt inside job from IDs only | N/A |
| AI Agent / Assistant | Prompt 50 runtimes | **FORBIDDEN** | **FORBIDDEN** |
| Report share recipient | External OTP session | No integration credentials | N/A |

Config flags:

- `forbid_plaintext_credential_view = true`
- `forbid_agent_credential_access = true`
- `forbid_ai_permission_mutation = true`

Hard denies:

- `IntegrationCredentialAccessService::denyAgentAccess` → `AI_CREDENTIAL_ACCESS_FORBIDDEN:{caller}`
- `ConnectionCredentialAccessService::denyBrowserReveal` → `PLAINTEXT_CREDENTIAL_VIEW_FORBIDDEN`

---

## UI surface

| Surface | Prompt 64 |
| --- | --- |
| Top-level Filament “Security” navigation | **NONE** |
| Integration pages showing connected/configured labels | Allowed |
| Credential plaintext reveal control | **FORBIDDEN** |
| Settings “future Security pages” | Not delivered by Prompt 64 |

---

## Audit kinds (permission-adjacent)

`SecurityAuditEventKind` includes `PERMISSION_CHANGED` and `USER_ACCESS_CHANGED` for metadata-only recording via `SecurityAuditRecorder`. Events must not store secret values.

---

## Interaction with tenancy

Passing a permission check does **not** waive `TenantScopeGuard` consistency. Operators authorized for the app still cannot act on forged Customer/Brand/Asset combinations.

---

## Forbidden

- Granting Agents a “credential.read” tool or skill that decrypts tokens
- AI creating/changing Spatie roles or permissions
- Second RBAC product (`RBACV2`)
- Equating Filament resource visibility with plaintext secret access
- New top-level Security nav as a permission workaround
