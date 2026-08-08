# INTEGRATIONS

> İnceleme tarihi: 2026-08-08  
> Dayanak: ADR-039, ADR-040

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

## Probe / connector services (legacy)

Existing read-only probe services under `app/Services/*ConnectionProbeService.php` remain for transitional `CoreConnection` rows. New Google collection does **not** use CoreConnection credentials. Site connections UI no longer offers new Google provider types (WordPress remains).

## Sonuç

Central Integration Architecture + Google authenticate/discover/catalog/bind + Binding-based live collection foundation mevcuttur. Performance Finding lifecycle is the next milestone.
