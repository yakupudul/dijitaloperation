# INTEGRATIONS

> İnceleme tarihi: 2026-08-08  
> Dayanak: ADR-039

## Mimari

MoxDOP SaaS değildir. Provider authentication **agency-owned / shared**’dir.

```
Agency Integration
  → External Resources (discovered)
    → Asset Bindings
      → Digital Assets
```

Site-specific credentials (WordPress) remain on `CoreConnection`.

## Mevcut foundation

| Parça | Durum |
|-------|--------|
| `core_integrations` | Schema + Filament Settings → Integrations |
| `core_integration_credentials` | Encrypted payload (Laravel cast) |
| `core_external_resources` | Schema + read-only Integration relation |
| `core_asset_bindings` | Schema + Digital Asset “Provider resources” UX |
| `DiscoversProviderResources` | Contract only — no live OAuth yet |
| `ProviderRegistry` | Google / Meta / DataForSEO / OpenAI + capabilities |
| Collectors / scheduling | Deferred |
| Google OAuth + discovery | Implemented (HTTP client; CI mocked) |
| Live Meta OAuth | Deferred |

## Google Integration

Settings → Integrations → Google supports authorize / test / refresh / disconnect.

Discovery capabilities:

* Search Console (`webmasters.readonly`)
* GA4 Admin accountSummaries (`analytics.readonly`)
* Google Ads listAccessibleCustomers (`adwords` + developer token; API version from `GOOGLE_ADS_API_VERSION`, default **v25**)
* GBP optional / setup-gated (`business.manage` + API access approval)

See `docs/product/GOOGLE_INTEGRATION_SETUP.md`.

## Probe / connector services

Existing read-only probe services under `app/Services/*ConnectionProbeService.php` remain. They still operate against transitional `CoreConnection` rows where applicable. Future collectors will consume Integration + Binding. Google Ads probe/collect REST paths share the same configured Ads API version as central discovery (no hard-coded v18 endpoints).

## Sonuç

Central Integration Architecture + Google authenticate/discover/catalog/bind foundation mevcuttur. Metric collectors are deferred.
