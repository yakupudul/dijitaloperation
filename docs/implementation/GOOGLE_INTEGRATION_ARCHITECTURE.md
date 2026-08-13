# GOOGLE INTEGRATION PRODUCTION ARCHITECTURE

Prompt 13 — Google Integration Backend / UI Convergence.

## 1. Purpose

Converge the existing real Google integration backend with the frozen MoxDOP
`/app/integrations` product into **one** canonical production Google Integration
architecture — without productionizing OAuth lifecycle, live discovery, binding
workflows, or provider collectors (Prompts 14–19).

## 2. Frozen Product Contract

Canonical operator surface:

```
/app/integrations
/app/integrations/google
/app/integrations/connectors/{connector}
```

Hard rule: legacy Filament `/system/settings/integrations` does **not** define
product IA. It remains an internal configure/admin surface until Prompt 14 moves
remaining configure actions fully onto `/app`.

## 3. Legacy Backend Audit

| Area | Finding | Class |
| --- | --- | --- |
| `CoreIntegration` (`provider=google`, unique) | Agency authorization plane | CANONICAL |
| `CoreIntegrationCredential` (provider + authorization) | Encrypted credential ownership | CANONICAL |
| `CoreExternalResource` | Typed provider resources | CANONICAL |
| `CoreAssetBinding` | DigitalAsset ↔ ExternalResource | CANONICAL |
| `app/Services/Integrations/Google/*` | OAuth, credentials, discovery, refresh | REUSABLE / PARTIAL lifecycle |
| Filament `IntegrationResource` | Real configure / authorize / refresh UI | KEEP_INTERNAL |
| Livewire Demo Google fixtures | Fake counts presented as real | SUPERSEDED for Google hub/detail |
| `CoreConnection` Google probes | Legacy site-scoped probes | LEGACY |
| Bound collectors (GSC/GA4/Ads/GBP) | Evidence/Run path, not Collection Engine DatasetExecutors | PARTIAL_LEGACY_BOUND |
| Demo connector pages | Fixture control-plane UX | DEMO until Prompt 15/16 |

No dedicated `google_oauth_*` / `google_connections` Eloquent product tables.

## 4. Canonical Ontology

| Concept | Meaning |
| --- | --- |
| Integration | Provider authorization / connection plane (`CoreIntegration`) |
| Connector | Provider product/capability (`GoogleConnectorRegistry`) |
| Credential | Encrypted provider + authorization secrets (`CoreIntegrationCredential`) |
| ExternalResource | Provider-side selectable entity (`CoreExternalResource`) |
| Binding | Technical DigitalAsset ↔ ExternalResource map (`CoreAssetBinding`) |
| DigitalAsset | MoxDOP managed property |
| Asset Relationship | Semantic relation between Digital Assets (e.g. GA4 measures Website) — **not** Binding |

These must not be collapsed.

## 5. Google Provider Architecture

```
GOOGLE (provider id: google)
  ├── ga4
  ├── search_console
  ├── google_ads
  └── google_business_profile
```

One agency `CoreIntegration` row per provider (`unique(provider)`).
One authorization credential shared by all Google Connectors.
Multiple Integrations per Customer are not modeled — Integration is agency-scoped.

## 6. Integration

Model: `CoreIntegration` where `provider = google`.

Responsibilities: provider identity, status, non-secret config metadata,
credential references, discovery timestamps, capability health metadata.

Not: GA4 property, GSC property, Ads customer, Website, DigitalAsset.

## 7. Connector

Registry: `App\Support\Integrations\Google\GoogleConnectorRegistry`.

Frozen UI slugs (`ga4`, `gsc`, `google-ads`, `gbp`) map to capability IDs.
Connector ≠ credential ≠ ExternalResource ≠ Collector.

## 8. Credential

| Type | Purpose |
| --- | --- |
| `provider` | Client ID/Secret + Ads developer token (app config; may also come from env) |
| `authorization` | OAuth access/refresh tokens |

Ownership: Integration → Credential. Connectors do not duplicate secrets.
UI/read models receive status only. Queue payloads must never contain secrets.
Lifecycle productionization: **Prompt 14**.

## 9. ExternalResource

Model: `CoreExternalResource`.

Uniqueness: `(integration_id, resource_type, external_id)`.

Discovered ≠ selected ≠ DigitalAsset. Stale/unavailable resources are marked,
not hard-deleted in Prompt 13.

## 10. Binding

Model: `CoreAssetBinding`.

Uniqueness: `(digital_asset_id, external_resource_id)` and
`(digital_asset_id, capability)`.

Compatibility: `AssetBindingCompatibility` + `BindingScopeGuard`.
Binding ≠ Asset Relationship ≠ data availability / materialization freshness.

## 11. DigitalAsset Relationship

Google-related asset types include `website`, `ga4`/`gsc` (first-class),
`google_ads`, `google_business_profile`. Prompt 13 does not auto-create assets
from discovery.

## 12. OAuth Ownership Boundary

Routes:

| Route | Status |
| --- | --- |
| `integrations.google.authorize` | CANONICAL launch (Admin) |
| `integrations.google.callback` | CALLBACK_ONLY |
| Success redirect → `demo.integrations.google` | Frozen `/app` surface (now real-backed) |

OAuth belongs to canonical Google Integration. Full lifecycle: **Prompt 14**.
No live OAuth executed by Prompt 13.

## 13. Resource Type Taxonomy

| Constant | Stored `resource_type` | Entity |
| --- | --- | --- |
| `GA4_PROPERTY` | `ga4` | GA4 Property |
| `GSC_PROPERTY` | `search_console` | Search Console Property |
| `GOOGLE_ADS_CUSTOMER` | `google_ads` | Ads Customer |
| `GBP_LOCATION` | `google_business_profile` | GBP Location |

Class: `GoogleResourceType`.

## 14. Route Convergence

| Route | Audience | Purpose | Canonical destination | Status after Prompt 13 | Compatibility |
| --- | --- | --- | --- | --- | --- |
| `/app/integrations` | Operator | Hub | itself | CANONICAL | — |
| `/app/integrations/google` | Operator | Google detail | itself | CANONICAL | Real read model |
| `/app/integrations/connectors/*` | Operator | Connector UX | itself | DEMO / PARTIAL | Prompt 15/16 |
| `/system/settings/integrations` | Admin | Filament hub + monitoring | keep for admin configure | KEEP_INTERNAL | Not primary operator journey |
| `/system/settings/integrations/{id}` | Admin | Configure / authorize / refresh | keep until Prompt 14 | KEEP_INTERNAL | Manage link for Google → `/app` |
| `integrations.google.authorize` | Admin | OAuth start | Google | CANONICAL | — |
| `integrations.google.callback` | Admin | OAuth callback | process then `/app/integrations/google` | CALLBACK_ONLY | Never redirect before process |

Duplicate primary operator Google journey: **NONE**.

## 15. Frozen UI Read Model

- `OperatorIntegrationsHubQuery` — hub groups; Google card from real state
- `GoogleIntegrationReadModel` — Google detail projection (no secrets, no provider HTTP)

States distinguished: configured / authorized / discovered / bound / collected / fresh.

## 16. Collection Engine Relationship

Integration owns auth/connectors/resources/bindings.
Collection Engine owns execution (`StartCollectionService` → `CollectionRun`).
Canonical frozen Google UI does **not** synchronously invoke provider collectors.
Production DatasetExecutors: Prompts 17–19.

```
/app/integrations
        ↓
Integration Read Model
        ↓
CoreIntegration (Google)
        ↓
CoreIntegrationCredential
        ↓
Google Connectors (GA4 / GSC / Ads / GBP)
        ↓
Resource Discovery (Prompt 15)
        ↓
CoreExternalResource
        ↓
Selection / Binding (Prompt 16)
        ↓
CoreAssetBinding → DigitalAsset
        ↓
Collection Planner → CollectionRun → Data Pool
```

## 17. Legacy Compatibility

See Legacy Convergence Matrix below. New writes target Core* models only.
No dual-write. No destructive credential/resource/binding deletion.

## 18. Security / Tenant Boundaries

- Agency Google Integration is provider-unique (not SaaS multi-tenant Integration).
- DigitalAssets remain Customer/Brand scoped; agency auth may bind across customers.
- ExternalResource must belong to the selected Integration.
- BindingScopeGuard + AssetBindingCompatibility enforce type/provider consistency.
- Secrets never serialized to UI/read models/logs/queue payloads.

## 19. Prompt 14–19 Handoff

| Prompt | Owns |
| --- | --- |
| 14 | OAuth & credential lifecycle |
| 15 | Live resource discovery |
| 16 | Selection & binding UX/workflow |
| 17–19 | Production GSC / GA4 / Google Ads collectors |

## 20. Reality Matrix

| Capability | Classification |
| --- | --- |
| Frozen Google Integration UI convergence | REAL |
| Canonical Google Integration domain | REAL |
| Credential ownership architecture | REAL |
| Connector architecture | REAL |
| ExternalResource architecture | REAL |
| Binding architecture | REAL foundation |
| Google OAuth lifecycle | PARTIAL / NEXT (Prompt 14) |
| Google live resource discovery | PARTIAL / NEXT (Prompt 15) |
| Resource selection/binding workflow | PARTIAL / NEXT (Prompt 16) |
| GSC / GA4 / Ads / GBP production collectors | NOT YET |

## 21. Definition of Done

See Prompt 13 §130 checklist. Prompt 13 PASS requires frozen `/app/integrations`
as canonical surface, one Google Integration architecture, no fake real Google
state, no production collectors, no IA redesign.

---

## Legacy Convergence Matrix

| Legacy Component | Current Responsibility | Canonical Equivalent | Action | Migration Required? | Risk | Tests |
| --- | --- | --- | --- | --- | --- | --- |
| `CoreIntegration` | Agency Google auth plane | itself | KEEP / EVOLVE | No | Low | Architecture + central integration |
| `CoreIntegrationCredential` | Secrets | itself | KEEP | No | Medium (secrets) | Secret serialization |
| `CoreExternalResource` | Discovered resources | itself | KEEP | No | Low | Dedup / types |
| `CoreAssetBinding` | Bindings | itself | KEEP | No | Low | Uniqueness / scope |
| Filament Integrations hub | Admin configure + monitoring | `/app/integrations` primary | KEEP_INTERNAL | No | Medium (dual shells) | Workspace V2 |
| Demo Google fixtures on hub/detail | Fake state | `GoogleIntegrationReadModel` | DEPRECATE (hub/detail) | No | Low | Hub/detail tests |
| Demo connector pages | Control-plane UX | later real connector read models | ADAPT later | No | Low | Onboarding tests |
| `CoreConnection` Google probes | Legacy probes | N/A for Integrations | DEPRECATE / REMOVE LATER | No | Low | Existing probe tests |
| Bound collectors | Evidence collect | Collection Engine executors later | KEEP / REPLACE LATER | No | Medium | Bound collector tests |
| OAuth controller redirects | Post-auth landing | `/app/integrations/google` | KEEP (now real-backed) | No | Low | GoogleCentralIntegrationTest |

## Domain Source-of-Truth Matrix

| Concept | Canonical model/service | Legacy source | Legacy writes after Prompt 13? | Read compatibility? | Removal milestone |
| --- | --- | --- | --- | --- | --- |
| Integration | `CoreIntegration` | Demo fixtures | No (Demo narrative only) | Demo helpers may remain | Fixture retire after Meta/etc. convergence |
| Credential | `CoreIntegrationCredential` + resolvers | env fallback | Env fallback OK | Status-only UI | Prompt 14 harden |
| Connector | `GoogleConnectorRegistry` | Demo connector IDs | No | UI slug mapping | — |
| ExternalResource | `CoreExternalResource` | Demo unbound lists | No | Connector pages still Demo | Prompt 15 |
| Binding | `CoreAssetBinding` | Demo bind session | No | Connector pages still Demo | Prompt 16 |
| OAuth state | Integration config + auth credential | — | Canonical only | — | Prompt 14 |
| Collection state | Collection Engine / materializations | Integration `last_*` metadata | No duplicate collection state on Integration | Read model projects | Prompts 17–19 |

## Google Connector Matrix

| Connector ID | Provider | ExternalResource type | DigitalAsset compatibility | Auth dependency | Discovery | Binding | Collection | Next |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ga4` | google | `ga4` | website, ga4 | Google OAuth | PARTIAL | PARTIAL | PARTIAL_LEGACY_BOUND | 15/16/18 |
| `search_console` | google | `search_console` | website, gsc | Google OAuth | PARTIAL | PARTIAL | PARTIAL_LEGACY_BOUND | 15/16/17 |
| `google_ads` | google | `google_ads` | google_ads | Google OAuth | PARTIAL | PARTIAL | PARTIAL_LEGACY_BOUND | 15/16/19 |
| `google_business_profile` | google | `google_business_profile` | google_business_profile | OAuth + GBP scope (gated) | PARTIAL_GATED | PARTIAL | PARTIAL_LEGACY_BOUND | 15/16 |

## State Matrix

| Configured? | Authorized? | Resources discovered? | Bindings? | Collection? | Materialization? | Operator state |
| --- | --- | --- | --- | --- | --- | --- |
| No | No | No | No | No | No | Not configured |
| Yes | No | No | No | No | No | Configured / authorization required |
| Yes | Yes | No | No | No | No | Connected — discovery not run |
| Yes | Yes | Yes | No | No | No | Resources available — none selected |
| Yes | Yes | Yes | Yes | No | No | Selected — collection not run |
| Yes | Yes | Yes | Yes | Yes | Available | Data available |
| Yes | Yes | Yes | Yes | Failed/stale | Stale/Partial | Connected + needs attention / stale data |

Connected ≠ discovered ≠ bound ≠ collected ≠ fresh.
