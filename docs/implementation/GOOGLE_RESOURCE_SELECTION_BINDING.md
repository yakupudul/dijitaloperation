# GOOGLE RESOURCE SELECTION & ASSET BINDING

**Prompt:** 16  
**Status:** CODE READY  
**Depends on:** Prompt 13–15  
**Canonical surface:** `/app/integrations` → Google → Resources & Bindings

---

## 1. Purpose

Allow authorized human operators to confirm which discovered Google
ExternalResources belong to which MoxDOP Customer/Brand Digital Assets.

Discovery never binds automatically.

---

## 2. Canonical types (verified)

| ExternalResource `resource_type` | Preferred DigitalAsset `type` | Also compatible |
| --- | --- | --- |
| `ga4` | `ga4` | `website`, aliases `google_analytics`/`analytics` |
| `search_console` | `gsc` | `website`, aliases `search_console`/`google_search_console` |
| `google_ads` | `google_ads` | `google_ads` only (non-managers) |
| `google_business_profile` | `google_business_profile` | aliases `gbp` |

**GBP:** Location ExternalResource → `google_business_profile` DigitalAsset.  
No competing GBP asset model. Account containers are not bind targets.

---

## 3. Architecture

```
Discovered CoreExternalResource
  → operator Select & bind (human confirm)
  → ResourceBindingPlan (DTO)
  → ConfirmGoogleResourceBindingService
  → BindingScopeGuard + ExternalResourceAssetCompatibility + BindingCardinalityRegistry
  → CoreAssetBinding (+ optional new DigitalAsset)
```

Shared Filament path: `AssetBindingsRelationManager` uses `BindingScopeGuard`
and resource-already-bound checks.

---

## 4. Modes

A. **Create new Digital Asset** then bind (default for Integration UI).  
B. **Bind existing compatible Digital Asset** in the selected Brand.

Ownership (Customer/Brand) comes from operator selection — never inferred
from Google account hierarchy.

---

## 5. Cardinality

| Rule | Enforcement |
| --- | --- |
| Max 1 active resource per asset+capability | DB unique `(digital_asset_id, capability)` + service |
| Max 1 active asset per ExternalResource | Service (+ Filament) |
| Exact pair uniqueness | DB unique `(digital_asset_id, external_resource_id)` |
| Ads managers not selectable | metadata `is_manager` / `selectable` |

---

## 6. Hard rules

- Human confirmation required  
- No name/URL auto-bind  
- No CollectionRun on bind  
- No semantic Asset Relationships invented  
- No provider writes  
- Frontend disabled buttons are not integrity guarantees  

---

## 7. Provenance

Stored on Binding `configuration`:

- `confirmed_by_user_id`
- `confirmed_at`
- `origin` (`google_integration_selection` | `filament_asset_bindings`)
- `mode` / `created_asset` when applicable  

---

## 8. UX

Frozen `/app/integrations/google` → Resources:

- Select & bind… opens confirmation modal  
- Brand + create/existing target  
- Confirm binding  
- Binding list updates from DB  

Page render does not call Google APIs.

---

## 9. Reality Matrix

| Capability | Status |
| --- | --- |
| Resource selection | REAL |
| Human-confirmed Binding | REAL |
| Create DigitalAsset on confirm | REAL |
| Bind existing DigitalAsset | REAL |
| GBP Location → GBP DigitalAsset | REAL |
| Auto-bind / auto-collect | NO |
| Production collectors | GSC REAL (Prompt 17); GA4/Ads NOT YET (18–19) |
| Initial backfill | NOT YET (20) |

---

## 10. Prompt 17 handoff

Bindings are production-real. Collectors must resolve:

DigitalAsset → active CoreAssetBinding → ExternalResource → Credential Broker  

without creating Bindings themselves.

Prompt 17 delivered `SearchConsoleDatasetExecutor` on that path for Search Console only.

---

## Binding matrix

| Connector | Resource | Asset | Auto? | Collect on bind? |
| --- | --- | --- | --- | --- |
| GA4 | ga4 property | ga4 / website | NO | NO |
| GSC | search_console site | gsc / website | NO | NO |
| Ads | customer (non-manager) | google_ads | NO | NO |
| GBP | location | google_business_profile | NO | NO |

---

*End of Prompt 16 document.*
