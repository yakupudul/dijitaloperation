# META INTEGRATION PRODUCTION ARCHITECTURE

Prompt 21 — Meta Integration Backend / UI Convergence.

## 1. Purpose

Converge every existing Meta / Facebook Ads integration concept into **one**
canonical production Meta Integration foundation that implements the frozen
`/app/integrations` product contract — without productionizing Meta OAuth,
live Business/Ad Account discovery as a product action, human binding workflows,
or Meta Ads analytical collectors (Prompts 22–25).

## 2. Frozen Product Contract

Canonical operator surface:

```
/app/integrations
/app/integrations/meta
/app/integrations/connectors/meta-ads
```

Hard rule: legacy Filament `/system/settings/integrations` Meta configure /
manual token UI does **not** define product IA. It remains KEEP_INTERNAL until
Prompt 22 moves authorization/discovery onto the frozen journey.

## 3. Legacy Meta Backend Audit

| Area | Finding | Class |
| --- | --- | --- |
| Provider id `meta` (`ProviderRegistry::META`) | One authorization plane | CANONICAL |
| Capabilities `meta_ads`, `instagram` | Ads is production foundation; Instagram not expanded here | CANONICAL / DEFER |
| `CoreIntegration` (`provider=meta`, unique) | Agency authorization plane | CANONICAL — KEEP/EVOLVE |
| `CoreIntegrationCredential` TYPE_PROVIDER + `access_token` | Tenant authorization token (legacy manual / system-user style) | CANONICAL ownership; lifecycle Prompt 22 |
| `META_APP_ID` / `META_APP_SECRET` | Deployment application config | CANONICAL (added Prompt 21) |
| `META_ACCESS_TOKEN` env | Bootstrap fallback only | LEGACY COMPATIBILITY |
| `MetaApiConfig` Graph `v26.0` + fixed `graph.facebook.com` | Central API boundary | CANONICAL |
| `MetaApiClient` / `MetaCredentialResolver` / `MetaConnectionService` | HTTP + credential resolve + health | REUSABLE |
| `MetaResourceDiscoveryService` | Graph discovery + ExternalResource upsert | REUSABLE / PARTIAL (Prompt 22 productizes UX) |
| `CoreExternalResource` `resource_type=meta_ads` | Ad Account inventory (`act_*`) | CANONICAL META_AD_ACCOUNT |
| `CoreExternalResource` `resource_type=meta_business` | Business container | CANONICAL META_BUSINESS (Prompt 21) |
| `CoreAssetBinding` capability `meta_ads` | DigitalAsset ↔ Ad Account | CANONICAL foundation |
| Filament Integration Meta pages | Manual token + discover | KEEP_INTERNAL |
| Frozen Demo Meta hub/detail (pre-21) | Fixture import simulation | SUPERSEDED by `MetaIntegrationReadModel` |
| `CoreConnection` / `meta_ads_api` probes | Legacy site-scoped Graph probes (often v21) | LEGACY / DEPRECATE |
| `MetaAdsBoundCollector` | Evidence/Run Insights path (sync) | PARTIAL REAL — REPLACE IN PROMPT 24 for Collection Engine |
| Specialist `/app/assets/meta/*` | Demo Meta Ads workspace | DEMO — unchanged |

No competing `MetaIntegration` / `MetaAdAccount` Eloquent product roots.

## 4. Canonical Provider Identity

| Concept | Value |
| --- | --- |
| Authorization-plane provider ID | `meta` |
| Production advertising Connector | `meta_ads` |
| Compatibility aliases | API host/`graph.facebook.com`, Marketing API naming — **not** equal authorization planes |
| Rejected as peer authorization IDs | `facebook`, `facebook_ads`, `meta_ads_oauth`, `graph` |

One agency `CoreIntegration` row (`unique(provider)`).

## 5. Integration

Model: `CoreIntegration` where `provider = meta`.

Responsibilities: provider identity, status, non-secret config metadata,
credential references, discovery timestamps, connection test metadata,
Connector availability projection via read model.

Not: Meta Business, Ad Account, Campaign, Page, Instagram account, DigitalAsset,
Customer.

## 6. Meta Ads Connector

Registry: `App\Support\Integrations\Meta\MetaConnectorRegistry`.

| Field | Value |
| --- | --- |
| Connector ID | `meta_ads` |
| Provider | `meta` |
| Resource type | `meta_ads` (semantic META_AD_ACCOUNT) |
| Credential duplication | None — shares Integration credential |
| Collection status (Prompt 21) | `NOT_YET` (Prompt 24) |

Instagram remains a registered capability id in `ProviderRegistry` but is **not**
a Prompt 21 production Connector expansion.

## 7. Application Configuration

| Key | Env | Owner |
| --- | --- | --- |
| App ID | `META_APP_ID` | Deployment (`config/moxdop.php` → `MetaApiConfig`) |
| App Secret | `META_APP_SECRET` | Deployment — never UI; never tenant credential rows |
| Graph API version | `META_API_VERSION` (default `v26.0`) | `MetaApiConfig` only |
| Timeout / pagination | `META_TIMEOUT`, `META_MAX_PAGINATION_PAGES` | Deployment |

Application configured ≠ tenant authorized.

## 8. Credential / Token Ownership

Canonical path:

```
Meta collector / discovery / connection
        ↓
MetaCredentialResolver / MetaProviderCredentialService
        ↓
CoreIntegrationCredential (TYPE_PROVIDER, encrypted access_token)
```

| Rule | Status |
| --- | --- |
| Integration-owned | YES |
| Business-owned | NO |
| Ad Account-owned | NO |
| DigitalAsset-owned | NO |
| App Secret in tenant payload | FORBIDDEN (`assertNoAppSecretInTenantPayload`) |
| UI / queue / checkpoint / logs secrets | Forbidden |
| LEGACY CREDENTIAL COMPATIBILITY ACTIVE | `META_ACCESS_TOKEN` env fallback read-through |

Prompt 22 owns OAuth exchange, long-lived extension, validation/debug_token,
and production authorization UX. Manual paste remains KEEP_INTERNAL only.

## 9. Meta Business Semantics

| Aspect | Decision |
| --- | --- |
| Canonical type | `meta_business` (`MetaResourceType::META_BUSINESS`) |
| Role | Provider-side Business / Business Portfolio **container / access context** |
| Container | YES |
| Selectable / bindable | NO |
| Auto DigitalAsset | NO |
| Auto Customer | NO |
| Identity | Stable provider Business id (not name) |
| Hierarchy | `parent_external_id` / metadata on Ad Accounts |

## 10. Meta Ad Account Semantics

| Aspect | Decision |
| --- | --- |
| Canonical type | Stored `meta_ads`; semantic META_AD_ACCOUNT |
| Identity | Canonical `act_{digits}` via `MetaAdAccountId` |
| `act_` vs digits | Normalized — cannot create duplicate ExternalResources |
| Selectable / bindable foundation | YES (Prompt 23 workflow) |
| Auto DigitalAsset | NO |
| Auto Customer | NO |
| Metadata support | display name, status, currency, timezone, business context |
| Collection root | YES for Prompt 24 (per Meta Ads Data Contract) |

## 11. ExternalResource

Model: `CoreExternalResource`.

Uniqueness: `(integration_id, resource_type, external_id)`.

Discovered ≠ selected ≠ DigitalAsset ≠ Binding.

## 12. Provider Resource Hierarchy

```
META_BUSINESS (container ExternalResource)
        ↓ parent_external_id / metadata.business_id
META_AD_ACCOUNT (ExternalResource)
```

Not represented as `CoreAssetBinding`.
Not represented as Digital Asset Relationship.

Same Ad Account under multiple Business paths: dedupe by canonical `act_*`
within the Integration; Prompt 22 may enrich multi-context metadata.

## 13. DigitalAsset

Meta Ads DigitalAsset type: `meta_ads`.

Prompt 21 does not auto-create DigitalAssets from Business or Ad Account
inventory.

## 14. CoreAssetBinding

Canonical technical map:

`Meta Ads DigitalAsset` ↔ `META_AD_ACCOUNT ExternalResource`
(capability `meta_ads`, Integration context).

Human confirmation productionized in **Prompt 23**. Prompt 21: no auto Binding.

## 15. Asset Relationship Distinction

| Relation | Kind |
| --- | --- |
| Business → Ad Account | Provider resource hierarchy |
| Meta Ads DigitalAsset ↔ Ad Account | Binding |
| Meta Ads → Website (traffic) | Asset Relationship (semantic) |

Do not collapse.

## 16. Frozen Integration Read Model

- `OperatorIntegrationsHubQuery` — Meta hub card from real state (`provenance=real`)
- `MetaIntegrationReadModel` — hub + detail projection

Never decrypts secrets for UI. Never performs Graph HTTP on page render.
Actions `authorize` / `discover` / `bind` / `collect` remain **disabled** until
owning prompts (honest next-step labels).

## 17. Route Convergence

See Route Matrix below. Primary operator entry: `/app/integrations`.
Duplicate primary Meta operator journey after Prompt 21: **NONE**.

## 18. Existing Collector Audit

| Component | Class | Prompt 24 fate |
| --- | --- | --- |
| `MetaApiClient` | REUSABLE CLIENT | Keep behind credential broker |
| Discovery query builders | REUSABLE | Adapt |
| `MetaAdsBoundCollector` Insights/entity sync | PARTIAL REAL / LEGACY sync path | REPLACE with Collection Engine DatasetExecutor |
| Normalizers (`MetaActionNormalizer`, etc.) | REUSABLE NORMALIZER | Likely reuse |
| Synchronous Filament/UI collect | UNSAFE for frozen product | Must not define frozen workflow |

## 19. Legacy Data Convergence

| Data | Strategy |
| --- | --- |
| Encrypted provider credentials | KEEP — already Integration-owned |
| Existing `meta_ads` ExternalResources | KEEP — already canonical Ad Accounts |
| Business-only metadata on Ad Accounts | EVOLVE — also upsert `meta_business` containers on future discovery |
| Tokens on Ad Account rows | Not present as canonical schema; forbidden going forward |
| Explicit legacy selected-account flags | None requiring auto Binding migration — Prompt 23 confirmation |
| Dual writes | NO |

## 20. Security / Tenant Boundaries

- Only Admin may configure Meta credentials (existing service guard).
- ExternalResource belongs to Integration; UI cannot invent ownership.
- Cross-customer Binding of agency resources remains BindingScopeGuard-governed
  (same pattern as Google agency Integration).
- App Secret / access tokens never in read models, logs, or queue payloads.

## 21. Collection Engine Relationship

Future (not implemented here):

```
Meta Integration
        ↓
Meta Ads Connector
        ↓
META_AD_ACCOUNT ExternalResource
        ↓
CoreAssetBinding
        ↓
Meta Ads DigitalAsset
        ↓
Data Contract Registry (unchanged)
        ↓
CollectionPlan / StartCollectionService
        ↓
BoundCollectorRegistry / DatasetExecutors (Prompt 24)
```

Integration / ExternalResource / Binding do **not** store analytical facts.

## 22. Prompt 22–25 Handoff

| Prompt | Owns |
| --- | --- |
| 22 | Production Meta authorization + live Business/Ad Account discovery |
| 23 | Human selection & Binding workflow |
| 24 | Meta Ads Production Collector (Collection Engine) |
| 25 | Meta Initial Backfill Orchestrator |

## 23. Reality Matrix

See `docs/implementation/MILESTONE_5_PANEL_FREEZE.md` Capability Reality Matrix
(Prompt 21 rows). Summary:

| Capability | Classification |
| --- | --- |
| Frozen Meta Integration UI convergence | REAL (backend state) |
| Canonical META provider | REAL |
| Canonical Meta Integration | REAL |
| Application configuration architecture | REAL |
| Credential/token ownership | REAL |
| Meta Ads Connector foundation | REAL |
| Meta Business container architecture | REAL |
| Meta Ad Account ExternalResource | REAL |
| Binding foundation | REAL / existing |
| Production Meta authorization | NOT YET / Prompt 22 |
| Live Business / Ad Account discovery productization | NOT YET / Prompt 22 |
| Human selection / production Binding | NOT YET / Prompt 23 |
| Meta Ads Production Collector | NOT YET / Prompt 24 |
| Meta Initial Backfill | NOT YET / Prompt 25 |

## 24. Definition of Done

See Prompt 21 §145 checklist. PASS requires frozen `/app/integrations` as
canonical Meta surface, one META authorization plane, Business ≠ Ad Account,
no auto DigitalAsset/Binding, no live Graph on page render, no production
collector, no IA redesign.

---

## Legacy Convergence Matrix

| Legacy Component | Current Responsibility | Canonical Equivalent | Action | Writes after P21? | Migration Required? | Risk | Tests |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `CoreIntegration` meta | Auth plane | itself | KEEP/EVOLVE | YES canonical | No | Low | Architecture |
| `CoreIntegrationCredential` provider token | Tenant auth | itself | KEEP | YES canonical | No | Medium | Credential tests |
| `META_ACCESS_TOKEN` env | Bootstrap fallback | resolver read-through | ADAPT / DEPRECATE later | Read only | No | Low | Resolver |
| Filament Meta token UI | Manual configure | Prompt 22 OAuth | KEEP_INTERNAL | Internal only | No | Medium | Central Meta tests |
| Frozen Demo Meta fixtures | Fake hub/detail | `MetaIntegrationReadModel` | DEPRECATE hub/detail | No | No | Low | Architecture UI |
| `MetaResourceDiscoveryService` | Inventory upsert | itself | KEEP (Prompt 22 UX) | Via services | No | Low | Discovery tests |
| `meta_business` resources | Container | `MetaResourceType` | KEEP | YES | Additive | Low | Architecture |
| `MetaAdsBoundCollector` | Evidence Insights | Prompt 24 executor | KEEP / REPLACE LATER | Legacy path | No | Medium | Existing collector tests |
| `CoreConnection` meta probes | Legacy | N/A | DEPRECATE / REMOVE LATER | No product | No | Low | Existing |
| Specialist Meta Demo UI | Analytics UX | unchanged Demo | KEEP Demo | No real pool claims | No | Low | Existing product specs |

## Source-of-Truth Matrix

| Concept | Canonical model/service | Legacy source | New writes canonical? | Legacy reads? | Legacy writes? | Removal milestone |
| --- | --- | --- | --- | --- | --- | --- |
| Meta Integration | `CoreIntegration` provider=`meta` | Demo fixtures | YES | Demo narrative may remain elsewhere | No for hub/detail | Fixture retire post Prompt 22+ |
| Application config | `config/moxdop.meta` + `MetaApiConfig` | scattered version strings | YES | Compat env names | Env only | — |
| Credential/token | `CoreIntegrationCredential` + `MetaCredentialResolver` | env `META_ACCESS_TOKEN` | YES (DB) | Env fallback | Env bootstrap only | Prompt 22 OAuth |
| Meta Business | `CoreExternalResource` `meta_business` | metadata-only previously | YES | metadata fields | No parallel table | — |
| Meta Ad Account | `CoreExternalResource` `meta_ads` | same | YES | — | No parallel table | — |
| Connector | `MetaConnectorRegistry` | Demo connector slug | YES | Demo connector page | No | Prompt 23/24 |
| ExternalResource | `CoreExternalResource` | — | YES | — | No | — |
| Binding | `CoreAssetBinding` | Demo bind | Foundation YES; product workflow Prompt 23 | Demo | No auto | Prompt 23 |
| Authorization state | Integration config + resolver | Filament | YES | Internal | Internal configure | Prompt 22 |
| Collection state | Collection Engine / materializations | Bound collector Runs | Not dual on Integration | Bound Runs may exist | No Integration fact columns | Prompt 24/25 |

## Meta Resource Matrix

| Provider Concept | Canonical Type | Container? | Selectable? | Bindable? | DigitalAsset automatically? | Collection root? | Future owner |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Meta Business / Portfolio | `meta_business` | YES | NO | NO | NO | NO | Prompt 22 discovery |
| Meta Ad Account | `meta_ads` (META_AD_ACCOUNT) | NO | YES | YES | NO | YES (Prompt 24) | Prompt 22–24 |
| Campaign / Ad Set / Ad / Creative | Analytical entities | NO | NO as Integration resources | NO | NO | Collected facts later | Prompt 24 |

## Token Ownership Matrix

| Type | Legacy storage | Canonical owner | App / tenant | Secret? | Encrypted? | UI visible? | Queue allowed? | Future lifecycle |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Meta App ID | env / config | Deployment config | Application | No (public id) | No | Configured/missing only | N/A | Prompt 22 OAuth app |
| Meta App Secret | env / config | Deployment config | Application | YES | Env/secrets store | NO | NO | Prompt 22 |
| Tenant access token (manual / system-user / long-lived style) | `CoreIntegrationCredential` TYPE_PROVIDER | Integration | Tenant | YES | YES (cast) | Status only | IDs only | Prompt 22 validation/OAuth |
| Env `META_ACCESS_TOKEN` bootstrap | env | Resolver fallback | Tenant bootstrap | YES | Env | NO | NO | Deprecate after OAuth |
| Graph API version | config | `MetaApiConfig` | Application | No | No | Version label OK | N/A | Prompt 22/24 verify |
| Page / IG resource-scoped tokens | Not implemented as product stores | N/A | N/A | — | — | — | — | Out of Prompt 21 scope |

Do not invent unverified token product types.

## Route Matrix

| Route | Audience | Current purpose | Canonical destination | State after Prompt 21 | Compatibility |
| --- | --- | --- | --- | --- | --- |
| `/app/integrations` | Operator | Hub | itself | CANONICAL_APP | Real Meta card |
| `/app/integrations/meta` | Operator | Meta detail | itself | CANONICAL_APP | Real read model |
| `/app/integrations/connectors/meta-ads` | Operator | Connector UX | itself | DEMO / PARTIAL | Prompt 23 |
| `/app/assets/meta/*` | Operator | Specialist Demo analytics | itself | DEMO | Unchanged |
| `/system/settings/integrations` | Admin | Filament hub | KEEP_INTERNAL | KEEP_INTERNAL | Not primary journey |
| `/system/settings/integrations/{id}` Meta | Admin | Token configure / discover | KEEP_INTERNAL | KEEP_INTERNAL / DEPRECATE primary | Prompt 22 moves auth |
| Meta OAuth callback | — | Not present | Prompt 22 | N/A | — |

Duplicate primary operator Meta journey: **NONE**.

## State Matrix

| App configured? | Authorized? | Businesses? | Ad Accounts? | Binding? | Collection? | Materialization? | Operator state |
| --- | --- | --- | --- | --- | --- | --- | --- |
| No | No | No | No | No | No | No | Not configured |
| Yes | No | No | No | No | No | No | Authorization required |
| Yes/No | Yes (token stored) | No | No | No | No | No | Authorized — inventory absent |
| Yes | Yes | Yes | Yes | No | No | No | Inventory — no Binding |
| Yes | Yes | * | Yes | Yes | No | No | Bound — no collection |
| Yes | Yes | * | Yes | Yes | Succeeded | Available | Data available |
| Yes | Yes | * | Yes | Yes | Failed | Stale/Partial available | Failed collection ≠ no historical data |
| Yes | Yes | * | Yes | Yes | Any | Stale | Refresh required |

configured ≠ authorized ≠ discovered ≠ bound ≠ collected ≠ fresh.
token exists ≠ token valid (Prompt 22).

## Collection Handoff Matrix

| Meta concept | Prompt 21 state | Prompt 22 | Prompt 23 | Prompt 24 | Prompt 25 |
| --- | --- | --- | --- | --- | --- |
| META provider / Integration | REAL | — | — | — | — |
| App config vs tenant credential | REAL ownership | OAuth lifecycle | — | — | — |
| Meta Ads Connector | REAL foundation | — | — | production collect | backfill |
| Business container ER | REAL architecture | live discover | — | — | — |
| Ad Account ER + `act_` identity | REAL | live discover | select | collect root | backfill |
| Binding | Foundation only | — | human confirm | uses binding | uses binding |
| Collection Engine Meta executor | NOT YET | — | — | implement | orchestrate |
| Frozen UI actions discover/bind/collect | Disabled honest labels | enable auth/discover | enable bind | enable collect | backfill UX |
