# Tenant Isolation Contract

> Prompt 64 — Customer / Brand / DigitalAsset consistency and the sole cross-Brand exception.  
> Implementation: `App\Support\Security\TenantScopeGuard`  
> Related: [`SECURITY_CREDENTIAL_HARDENING.md`](../implementation/SECURITY_CREDENTIAL_HARDENING.md) · [`INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md`](INTELLIGENCE_MEMORY_PRIVACY_BOUNDARIES.md) · [`SECTOR_MEMORY_PRIVACY_POLICY.md`](SECTOR_MEMORY_PRIVACY_POLICY.md) · [`PERMISSION_BOUNDARY_CONTRACT.md`](PERMISSION_BOUNDARY_CONTRACT.md)

## Canonical rule

Authorization **never** trusts browser-supplied Customer, Brand, or DigitalAsset identifiers alone. Server code must prove Brand belongs to Customer and Asset belongs to Brand (and optional allowlists) before acting. MoxDOP remains internal agency operations — not a SaaS multi-workspace customer login product.

---

## Object hierarchy

```text
Customer
  └── Brand
        └── DigitalAsset
              └── Connection (credentials scoped here)
Integration (provider authorization; not a cross-tenant share bus)
```

| Object | Isolation |
| --- | --- |
| Customer | Tenant root for Brands |
| Brand | Isolated even under the same Customer unless explicitly authorized |
| DigitalAsset | Brand-bound |
| Integration / Connection credentials | Never exposed as cross-Brand portable secrets via UI |
| Brand Memory | Customer+Brand confidential (Prompt 51) |

---

## TenantScopeGuard API

| Method | Behavior |
| --- | --- |
| `assertBrandAuthorized` | Optional allowlists for Brand IDs and/or Customer IDs |
| `assertBrandBelongsToCustomer` | `brand.customer_id` must match |
| `assertAssetBelongsToBrand` | `asset.brand_id` must match |
| `resolveConsistentScope` | Loads Customer/Brand/(optional Asset); rejects forged combinations |

Failure mode: `Illuminate\Validation\ValidationException` with codes such as `BRAND_CUSTOMER_MISMATCH`, `ASSET_BRAND_MISMATCH`, `UNAUTHORIZED_BRAND`, `UNAUTHORIZED_CUSTOMER`, `SCOPE_REQUIRED`, `*_NOT_FOUND`.

---

## IDOR posture

| Attack pattern | Required defense |
| --- | --- |
| Swap `brand_id` to another Customer’s Brand | Relationship assert / `resolveConsistentScope` |
| Swap `digital_asset_id` across Brands | `assertAssetBelongsToBrand` |
| Supply allowlisted Brand outside actor scope | `assertBrandAuthorized` |
| Guess report share locator | Prompt 60 hash + OTP + session (not TenantScopeGuard) |

Policies and Filament resources must continue to authorize on server-loaded models, not on unchecked request IDs.

---

## Sector Learning — sole privileged cross-Brand exception

| Pattern | Status |
| --- | --- |
| Privacy-qualified Sector Learning aggregates (Prompt 53) | **ALLOWED** — cohort observations only |
| Raw Brand Memory / Experience / Evidence cross-Brand read | **FORBIDDEN** |
| Credential or Integration token cross-Brand reuse via Sector path | **FORBIDDEN** |
| Contribution lineage / privileged repositories for Agents | **FORBIDDEN** |
| “Similar customer” Brand Memory shortcuts | **FORBIDDEN** |

Sector consumer DTOs must not include contributor Customer/Brand IDs. Thresholds and gates remain Prompt 53-owned.

---

## Credentials and tenancy

Decrypting a credential does not grant cross-tenant data access. Adapters must still scope provider calls and persisted results to the Integration/Connection’s Customer/Brand/Asset chain. `EphemeralSecret` may carry `integrationId` / `connectionId` metadata for tracing — never as a tenancy bypass token.

---

## Forbidden

- Trusting independent `customer_id` + `brand_id` + `digital_asset_id` fields without consistency checks
- `TenantV2` product or SaaS workspace switcher
- Treating Sector Learning as a general cross-tenant data lake
- AI/Agents selecting arbitrary Customer/Brand scopes without operator authorization paths
