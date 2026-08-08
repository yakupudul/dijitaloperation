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
| Live Google / Meta OAuth | Deferred |

## Probe / connector services

Existing read-only probe services under `app/Services/*ConnectionProbeService.php` remain. They still operate against transitional `CoreConnection` rows where applicable. Future collectors will consume Integration + Binding.

## Sonuç

Central Integration Architecture foundation mevcuttur. Live provider API calls and OAuth are intentionally not wired in this milestone.
