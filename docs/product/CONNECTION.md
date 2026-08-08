# Connection

## Purpose

Connection, bir Digital Asset hakkında veri okumamızı sağlayan bağlantı / kaynaktır. Digital Asset değildir.

Agency-level provider authentication artık **Integration** modelindedir (ADR-039). Connection kavramı ikiye ayrılır:

1. **Agency Integration** — Moximu’nun Google / Meta / DataForSEO / OpenAI gibi provider’lara tek merkezi bağlantısı  
2. **Asset-scoped Connection** — WordPress gibi site-specific credential’lar (mevcut `CoreConnection`)  
3. **External Resource + Binding** — Integration üzerinden discover edilen provider resource’larının Digital Asset’e eşlenmesi

**Authenticate once at agency level, bind many resources to Digital Assets.**

## User value

Ekip asset’e güvenli read-only veri kaynakları bağlar; diagnosis/confidence zenginleşir; secret’lar sızmaz; müşteri başına tekrar OAuth yapılmaz.

## Core concepts

**Digital Asset** = yönetilen gerçek dijital varlık (Website, GBP, Ads account, …).  
**Integration** = ajansın provider’a merkezi auth’u (credential ownership burada).  
**External Resource** = provider-side keşfedilmiş kaynak (GSC property, GA4 property, …).  
**Binding** = Digital Asset ↔ External Resource eşlemesi (credential taşımaz).  
**Site Connection (`CoreConnection`)** = asset-scoped credential/config (ör. WordPress).  
**Collector** = Integration + Binding kullanarak read-only veri çeken job/service; UI’dan “bağlanmaz”.

## MVP behavior

### Agency Integration (ADR-039)

* Settings → Integrations
* Provider select (finite: Google, Meta, DataForSEO, OpenAI)
* Credential encrypted store (`core_integration_credentials`)
* Resource discovery contract (`DiscoversProviderResources`) — live OAuth sonraki milestone
* Disabled integration: yeni discovery/collection durur; binding/resource purge edilmez

### Binding

* Digital Asset → Provider resources
* Searchable select over discovered External Resources
* Manual external ID yok
* `(digital_asset_id, capability)` unique

### Asset-scoped Connection (ADR-027, korunur)

* Connection bir Digital Asset’e bağlıdır
* type, display name, enabled/disabled
* configuration (non-secret)
* credential presence/state (secret değeri değil)
* connection test / read health
* Harici write yok
* Credentials ayrı encrypted credential store
* Secret UI/log/API response’a sızmaz
* WordPress bu modelde kalır

### Transitional note

Provider-type `CoreConnection` satırları (GA4, GSC, Ads, …) destructive migrate edilmez. Compatibility helper (`ConnectionScope`) asset-scoped vs provider-level type’ları sınıflandırır. Güvenli taşıma sonraki provider Integration milestone’larında yapılır.

## Important data / attributes

Integration: provider, name, status, config, health timestamps.  
Integration credential: encrypted_payload (+ optional expires/refreshed).  
External Resource: integration_id, resource_type, external_id, display_name, metadata (no secrets).  
Binding: digital_asset_id, external_resource_id, capability, status.  
Site Connection: asset_id, type, name, status/enabled, config, credential_ref/state.

## Relationships

```
Agency Integration → External Resources → Bindings → Digital Asset
Digital Asset → Site Connections (WordPress, …) → Runs/Evidence
```

## Main screens / workflows

* Settings → Integrations (Admin): list/add/edit agency integrations
* Asset detail → Provider resources: bind discovered resources
* Asset detail → Site connections: WordPress-style asset credentials
* Never show raw secrets

## Rules / invariants

* Read-only external access
* No customer/tenant repeated OAuth
* No marketplace/ZIP credential packs
* Secrets never copied onto External Resource or Binding
* Disabled ≠ purge
* Missing connection/binding ≠ invalid asset
* ADR-027 + ADR-039 uyumu zorunlu

## Derived information

Health / last success / last error Run veya connection/integration attempt kayıtlarından UI’da derive edilebilir.

## Later enhancements

Live Google/Meta OAuth + resource discovery, collectors/scheduling, richer health widgets.

## Explicit non-goals

External write; client portal; multi-tenant provider auth; treating GA4/GSC as separate Digital Assets; secret logging; fake live provider catalogs.

## Acceptance intent

Ajans provider’ı bir kez authorize eder; discover edilen resource’ları Digital Asset’lere bind eder; WordPress gibi site credential’lar asset-scoped kalır; secret sızmaz.
