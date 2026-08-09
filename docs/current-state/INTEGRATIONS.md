# INTEGRATIONS

> İnceleme tarihi: 2026-08-08  
> Dayanak: ADR-039, ADR-040

## Operator workspace (V2)

Settings → Integrations is a **service control center** (card hub), not a generic CRUD table.

Canonical product UX: [`docs/product/integrations/WORKSPACE.md`](../product/integrations/WORKSPACE.md).

| Provider | Shape | Operator status source |
|----------|--------|-------------------------|
| Google | OAuth + resource discovery | App config + authorization + persisted health |
| DataForSEO | API login/password | Credential presence + last `connection_status` |
| OpenAI | Secret API key | Credential presence + last `connection_status` |

Meta remains in `ProviderRegistry` but is not shown until an operator-ready workspace exists.

Index rendering never calls Google / DataForSEO / OpenAI.

Future AI providers / routing / failover are **out of scope** for this workspace UX and belong to **AI PROVIDER ROUTING & FAILOVER V1** (`docs/product/AI_CONTROL_PLANE.md`, **NOT IMPLEMENTED**).

## Mimari

MoxDOP SaaS değildir. Provider authentication **agency-owned / shared**’dir.

```
Agency Integration
  ├── Provider/Application Credential (Admin-managed, encrypted)
  ├── Authorization Credential (OAuth tokens, encrypted)
  → External Resources (discovered)
    → Asset Bindings
      → Digital Assets
        → Bound collectors → Run + Evidence
```

Site-specific credentials (WordPress) remain on `CoreConnection`.

## Mevcut foundation

| Parça | Durum |
|-------|--------|
| `core_integrations` | Schema + Filament Settings → Integrations |
| `core_integration_credentials` | Encrypted payload; `credential_type` = `provider` \| `authorization` |
| `core_external_resources` | Schema + read-only Integration relation |
| `core_asset_bindings` | Schema + Digital Asset “Provider resources” UX |
| `DiscoversProviderResources` | Contract + Google discoverers |
| `ProviderRegistry` | Google / Meta / DataForSEO / OpenAI + capabilities |
| Bound collectors | **Implemented (manual Collect live data)** |
| Scheduling | Deferred (manual first) |
| Google OAuth + discovery | Implemented (live SPA OAuth launch; CI mocked) |
| Google Admin application config UI | Implemented (Client ID/Secret/Ads developer token; Stored securely UX) |
| Live Meta OAuth | Deferred |

## Google Integration

Settings → Integrations → Google is the **only** Google workspace:

* **Configure** application credentials (only path for Client ID / Secret / Ads token)
* Copyable **OAuth Redirect URI** derived from `APP_URL` + named callback route
* authorize / test / refresh / disconnect (authorization tokens only)
* Filament SPA excludes `/integrations/google/*/authorize` for full-browser OAuth
* optional **Remove provider configuration** (destructive; Admin-only)

Generic Integration Edit KeyValue/JSON is **not** used for Google secrets.  
Credential resolution: DB provider credential → env fallback → missing.  
Authorize / Test / Refresh / discovery / collectors all use `GoogleCredentialResolver`.

Discovery capabilities:

* Search Console (`webmasters.readonly`)
* GA4 Admin accountSummaries (`analytics.readonly`)
* Google Ads ListAccessibleCustomers + MCC `customer_client` hierarchy (`adwords` + developer token; API **v25**; `login-customer-id` metadata)
* GBP optional / setup-gated (`business.manage` + API access approval)

## AUTH / DISCOVERY / BINDING

**COMPLETE** for Google agency path (OAuth, discovery, External Resources, Asset Bindings).

## REAL GOOGLE COLLECTION

**COMPLETE / PARTIAL** for Binding-based collectors:

| Capability | Module | Evidence |
|------------|--------|----------|
| Search Console | `app-modules/website` | `gsc_performance_summary`, `gsc_daily_performance`, `gsc_query_performance`, `gsc_page_performance` |
| GA4 | `app-modules/website` | `ga4_performance_summary`, `ga4_landing_page_performance`, `ga4_acquisition_summary` |
| Google Ads | `app-modules/google-ads` | `google_ads_account_summary`, `google_ads_campaign_performance`, `google_ads_landing_final_urls` (compat) |
| GBP | `app-modules/google-business-profile` | `gbp_location_access` (compat) when API access works; otherwise setup_required |

Operator entry: Digital Asset → **Collect live data** (active bindings only).  
Run provenance: nullable `runs.core_asset_binding_id` (agency) alongside existing `core_connection_id` (asset-scoped).

No nightly scheduler yet. No Finding rules engine in this milestone.

## DataForSEO Integration

Settings → Integrations → DataForSEO is the **agency** DataForSEO workspace:

* **Configure** API Login + API Password (encrypted provider credential; password write-only)
* **Test connection** via free `GET /v3/appendix/user_data` (HTTP + DataForSEO `status_code=20000`)
* Account snapshot: connection status, account login, timezone, last-fetched balance, last issue
* Shared client + envelope normalization + safe retry policy (no blind paid POST retries)
* Endpoint allowlist: `appendix/user_data`, Labs `locations_and_languages` (free), `ranked_keywords/live`, `keywords_for_site/live` (paid Website SEO Intelligence)
* Provider-agnostic Evidence cost guard: `request_fingerprint` + `fresh_until` + `PaidRequestExecutor` lock
* Optional env fallback: `DATAFORSEO_API_LOGIN` / `DATAFORSEO_API_PASSWORD` (values never shown in UI)

Canonical product docs: `docs/product/integrations/DATAFORSEO.md`, `docs/product/website/SEO_INTELLIGENCE.md`.

Website SEO Intelligence Light V1 uses agency Integration + Website Search market config. No fake ExternalResources/AssetBindings for DataForSEO. No backlink/OnPage/SERP Live collectors.

## OpenAI Integration

Settings → Integrations → OpenAI is the **agency** OpenAI workspace (ADR-041):

* **Configure** API Key (encrypted provider credential; write-only; blank preserves; clear/remove explicit)
* **Test connection** via non-generative `GET /v1/models` (no completion tokens)
* Status / Stored securely / Last checked / Last successful connection / Last issue
* Resolution: DB provider credential → env/config fallback → missing
* Runtime prepares `laravel/ai` with resolved key and `store = false`
* Explicit recommendation model via `moxdop.openai.recommendation_model` (default `gpt-5-mini`)
* No External Resources / Google-style discovery section (API-key agency provider)

Canonical product docs: `docs/product/website/AI_INSIGHTS.md`.

## Probe / connector services (legacy)

Existing read-only probe services under `app/Services/*ConnectionProbeService.php` remain for transitional `CoreConnection` rows. New Google collection does **not** use CoreConnection credentials. Site connections UI no longer offers new Google / Meta / DataForSEO provider types (WordPress remains).

`DataForSeoConnectionProbeService` is retained as **compatibility technical debt** for historical site-scoped rows. Normal new UX is Settings → Integrations → DataForSEO.

## Sonuç

Central Integration Architecture + Google authenticate/discover/catalog/bind + Binding-based live collection + DataForSEO agency credentials/test/cost-guard + Website SEO Intelligence Light V1 + Brand Intelligence Context V1 + OpenAI agency Integration + Website AI Recommendation Intelligence V1 (grounded advisory drafts + human acceptance) mevcuttur. Rank tracking / backlinks / competitor fetching / cross-channel AI are not included.
