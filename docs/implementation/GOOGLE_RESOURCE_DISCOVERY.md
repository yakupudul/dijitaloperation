# GOOGLE RESOURCE DISCOVERY

**Prompt:** 15  
**Status:** CODE READY (GBP / Ads external Google project access may still be MANUAL)  
**Verification date (official docs):** 2026-08-13  
**Canonical surface:** `/app/integrations`  
**Depends on:** Prompt 13 Integration ontology + Prompt 14 OAuth / Credential Broker

---

## 1. Purpose

Productionize real provider-side resource discovery for the canonical Google Integration.

Discovery answers: **what provider resources can this authorization currently access?**

It does **not** select, bind, create DigitalAssets, or collect analytical data.

---

## 2. Relationship to OAuth

| Workflow | Owner |
| --- | --- |
| Authorize Google | Prompt 14 (`GoogleOAuthService`) |
| Valid access token | `GoogleCredentialBroker` |
| Discover resources | Prompt 15 (`DiscoverGoogleResourcesService`) |
| Select & bind | Prompt 16 |

**AUTHORIZED ≠ DISCOVERED ≠ SELECTED ≠ BOUND ≠ DIGITAL ASSET.**

OAuth callback does **not** auto-run discovery.

---

## 3. Discovery Architecture

```
DiscoverGoogleResourcesService (orchestrator)
  ├── SearchConsoleDiscoverer
  ├── Ga4Discoverer
  ├── GoogleAdsDiscoverer
  └── GoogleBusinessProfileDiscoverer
        ↓
  ReconcileExternalResourcesService
        ↓
  CoreExternalResource + GoogleIntegrationDiscoveryAttempt
```

- Shared contract: `DiscoversProviderResources` / `DiscoveredExternalResource` (KEEP)
- Filament compat: `GoogleResourceRefreshService` → delegates to orchestrator
- Tokens: `GoogleApiClient` → `GoogleCredentialBroker`
- No second discovery framework

---

## 4. Canonical ExternalResource

Model: `CoreExternalResource`  
Unique: `(integration_id, resource_type, external_id)`  
IDs: opaque strings  
Hard delete on refresh: **NO**

---

## 5. Discovery Lifecycle

1. Operator authorized + app configured  
2. Explicit **Discover Resources** / **Refresh Resources**  
3. Per-Connector scope / app readiness check  
4. Provider list (paginated)  
5. Reconcile upsert + optional negative reconcile  
6. Persist discovery attempt + capability_health  
7. UI reads DB only on page render  

---

## 6. Reconciliation Rules

| Scenario | Action |
| --- | --- |
| New resource | Create ExternalResource |
| Same ID | Update metadata / last_seen |
| Renamed display | Update display_name; same row |
| Missing from **complete** success | Mark `unavailable` (not delete) |
| Missing from **partial**/failed | Preserve previous status |
| Auth/scope/external access error | Preserve inventory |

Negative reconciliation requires `complete_inventory = true`.

---

## 7. GA4 Discovery

- API: `GET https://analyticsadmin.googleapis.com/v1beta/accountSummaries`  
- Scope: `analytics.readonly`  
- Bindable resource: Property (`properties/{id}`)  
- Account context preserved as parent/metadata  
- Pagination: `nextPageToken`  
- Data Streams: not ExternalResources in Prompt 15  

Source: https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/accountSummaries/list

---

## 8. Search Console Discovery

- API: `GET https://www.googleapis.com/webmasters/v3/sites`  
- Scope: `webmasters.readonly`  
- Identity: exact `siteUrl` (domain vs URL-prefix preserved)  
- Permission: `permissionLevel` in metadata  
- Completeness: single list response (not page-token paginated)

---

## 9. Google Ads Discovery

- Roots: `customers:listAccessibleCustomers`  
- Hierarchy: GAQL `customer_client` with pagination  
- Identity: customer ID digits  
- Developer token: application config (Prompt 14)  
- login-customer-id: request header context only  

---

## 10. Google Ads Hierarchy

| Resource | Manager? | Selectable? | Notes |
| --- | --- | --- | --- |
| Seed accessible customer | maybe | if not manager | Root from listAccessibleCustomers |
| Client under MCC | no | YES | Performance bind candidate (Prompt 16) |
| Nested manager | yes | NO (inventory context) | Traversed; not auto DigitalAsset |
| Same customer via two paths | — | — | Deduped by customer ID |

Cycle safety: visited seed set + page bounds.

---

## 11. Google Business Profile Discovery

- Account Management: `mybusinessaccountmanagement.googleapis.com/v1/accounts`  
- Locations: `mybusinessbusinessinformation.googleapis.com/v1/{parent}/locations`  
- Prefer `accounts/-` wildcard for direct + indirect ownership  
- Required `readMask`: `name,title,storeCode,storefrontAddress,websiteUri,metadata`  
- Scope: `business.manage`  
- Primary resource: Location → `google_business_profile` ExternalResource  
- **Not deferred** — first-class Prompt 15 connector  

Sources (2026-08-13):  
https://developers.google.com/my-business/reference/businessinformation/rest/v1/accounts.locations/list  
https://developers.google.com/my-business/content/location-data

---

## 12. GBP API Access Boundary

| OAuth | business.manage | GBP API access | Discovery enabled | Result |
| --- | --- | --- | --- | --- |
| NO | — | — | — | AUTHENTICATION_REQUIRED / not authorized |
| YES | NO | — | — | SCOPE_REQUIRED |
| YES | YES | NO / 403 | YES | EXTERNAL_ACCESS_REQUIRED |
| YES | YES | — | NO (`GOOGLE_GBP_DISCOVERY_ENABLED=false`) | EXTERNAL_ACCESS_REQUIRED |
| YES | YES | YES | YES | COMPLETED · N or 0 |

GBP failure never fails GA4/GSC/Ads inventory.

**CODE READY** vs **GOOGLE PROJECT ACCESS READY** reported separately.

---

## 13. Discovery Completeness / Partial Results

Statuses: `ok`/`completed`, `partial`, `error`/`failed`, `scope_required`, `external_access_required`, `setup_required`, `authentication_required`, `never_run`.

Zero resources + complete = success (distinct from failure).

---

## 14. Persistent Inventory

- ExternalResources in DB  
- Lightweight `google_integration_discovery_attempts` history  
- Integration `config.capability_health` + `config.discovery` summary  

---

## 15. Refresh Discovery

Same orchestrator as initial discovery. Idempotent. Does not collect data or delete bindings.

---

## 16. Resource Access Loss

Unavailable status preserves identity + bindings. Collection later fails honestly (Prompt 16/17+).

---

## 17. Frozen Integration UX

- Canonical: `/app/integrations` / Google detail  
- Actions: Discover Resources / Refresh Resources  
- Connector cards show counts + discovery status (incl. GBP API access required)  
- Selection controls do not write bindings  
- Page render: **0** live Google discovery calls  

---

## 18. Security / Credential Boundary

- Admin-only discovery trigger  
- Tokens only via Credential Broker  
- No tokens in queue/attempts/UI/logs  
- Synthetic fixtures in tests only  

---

## 19. Tests

`tests/Feature/GoogleResourceDiscoveryTest.php` + existing central/MCC suites.  
Automated live Google calls: **0**.

---

## 20. Reality Matrix

| Capability | Classification |
| --- | --- |
| Google OAuth | REAL |
| Credential lifecycle | REAL |
| GA4 Resource Discovery | REAL |
| GSC Resource Discovery | REAL |
| Google Ads Resource Discovery | REAL |
| Google Ads Manager Hierarchy | REAL |
| GBP Location Discovery | REAL CODE PATH |
| GBP external provider access | UNKNOWN / MANUAL |
| Persistent ExternalResource inventory | REAL |
| Refresh Discovery | REAL |
| Resource Selection | NOT YET |
| Binding workflow | NOT YET |
| Production collectors | NOT YET |

---

## 21. Prompt 16 Handoff

Operator selects from persisted inventory → `CoreAssetBinding` → DigitalAsset.  
No auto-create/bind in Prompt 15.

---

## 22. Definition of Done

See Prompt 15 §162. All discovery invariants must be YES for PASS.

---

## Resource Type Matrix

| Connector | Provider type | Stable ID | Parent | Selectable? | Auto DigitalAsset? | Auto Binding? | Method | Pagination | Scope |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| GA4 | ga4 | `properties/{id}` | account | YES | NO | NO | accountSummaries.list | nextPageToken | analytics.readonly |
| GSC | search_console | siteUrl | — | YES | NO | NO | sites.list | single list | webmasters.readonly |
| Ads | google_ads | customer digits | manager id | non-managers | NO | NO | listAccessibleCustomers + customer_client | search pageToken | adwords |
| GBP | google_business_profile | location name | account | YES | NO | NO | accounts + locations.list | nextPageToken | business.manage |

---

## Discovery State Matrix

| State | Provider call? | Inventory preserved? | Negative reconcile? | Operator action |
| --- | --- | --- | --- | --- |
| NEVER_RUN | NO | N/A | NO | Discover Resources |
| COMPLETED/ok | YES | YES | YES | Review / Refresh |
| PARTIAL | YES | YES | NO | Retry refresh |
| FAILED/error | maybe | YES | NO | Retry / check API |
| SCOPE_REQUIRED | NO | YES | NO | Incremental reauth |
| EXTERNAL_ACCESS_REQUIRED | blocked/403 | YES | NO | Google Cloud / GBP approval |
| AUTHENTICATION_REQUIRED | blocked | YES | NO | Re-authorize |

---

## Reconciliation Matrix

| Scenario | Existing | Returned | Complete? | Action |
| --- | --- | --- | --- | --- |
| New | — | YES | — | Create |
| Same | YES | YES | — | Update last_seen/meta |
| Renamed | YES | YES (new name) | — | Update display_name |
| Missing | YES | NO | YES | Mark unavailable |
| Missing | YES | NO | NO (partial) | Keep available |
| Provider error | YES | — | NO | Keep |
| Auth error | YES | — | NO | Keep |
| Scope error | YES | — | NO | Keep |
| GBP external access | YES | — | NO | Keep |

---

## Google Ads Hierarchy Matrix

See §10.

---

## GBP Matrix

See §12.

---

## External readiness checklist

### GBP
- Enable Business Profile Account Management + Business Information APIs  
- Request GBP API access / non-zero quota when Google requires it  
- Set `GOOGLE_INCLUDE_GBP_SCOPE=true` and re-authorize  
- Set `GOOGLE_GBP_DISCOVERY_ENABLED=true` after access confirmed  

### Ads
- Developer token configured  
- Developer token access level (test/production) approved externally  
- Google Ads API enabled on Cloud project  

---

*End of Prompt 15 discovery document.*
