# EVENT_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Dayanak: ADR-009, ADR-013, ADR-021; `docs/foundation/EVENT_ARCHITECTURE.md`  
> İlgili: `MODULE_MANIFEST_SPEC.md`, `JOB_CONTRACT.md`, `ERROR_ISOLATION.md`  
> Taşıyıcı (MVP): Laravel events

## Amaç

Modüllerin event yayınlama / dinleme biçimini, isimlendirmeyi ve isteğe bağlı çapraz-modül kullanımı tanımlar.

## Kararlar

### 1. Event type isimlendirme

| Kural | Değer |
|-------|--------|
| Biçim | `{moduleId}.{eventName}` |
| `moduleId` | Manifest `id` (kebab-case) |
| `eventName` | kebab-case, fiil/geçmiş zaman tercih (`scan-completed`) |
| Regex (tam type) | `^[a-z][a-z0-9-]*\.[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)*$` |
| Çekirdek event’leri | `core.{eventName}` (ör. `core.brand.created`) |

Örnek: `sample-module.note-created`, `website-diagnosis.scan-completed`.

Bu isimlendirme `docs/foundation/EVENT_ARCHITECTURE.md` ile aynıdır. Snake_case event type kullanılmaz.

### 2. Envelope (zorunlu alanlar)

Her event şu zarfla taşınır:

| Alan | Tip | Açıklama |
|------|-----|----------|
| `id` | string (UUID) | Event örneği kimliği |
| `type` | string | Event type |
| `version` | number | Payload şema sürümü (integer, 1’den başlar) |
| `occurredAt` | string | ISO-8601 UTC |
| `moduleId` | string | Yayınlayan modül (`core` olabilir) |
| `correlationId` | string | İstek/job zinciri |
| `payload` | object | Type’a özel veri |

MVP’de `workspaceId` **yoktur** (Workspace/SaaS modeli yok). Kapsam entity id’leri `payload` içindedir (`customerId`, `brandId`, `digitalAssetId`, `connectionId`, …).

Exactly-once **vaat edilmez**. At-least-once varsayılır; dinleyiciler idempotent olmalıdır.

### 3. Manifest bildirimi

```json
"events": {
  "publishes": [
    { "type": "sample-module.note-created", "version": 1 }
  ],
  "subscribes": [
    { "type": "core.brand.updated", "version": 1 }
  ]
}
```

- Yayınlanmayan type için runtime publish → hata log + drop (veya dev’de assert)  
- Subscribe edilen type için handler kayıtlı değilse enable fail  
- Başka modülün event’ine subscribe **serbesttir** (gevşek bağlılık); private tablo/import yasaktır

### 4. Yayınlama kuralları

1. Yalnızca `enabled` modül publish eder  
2. Publish API çekirdek Event Bus üzerinden çağrılır  
3. Dinleyici hataları yayıncıyı düşürmez  
4. Ağır iş dinleyicide yapılmaz → job enqueue edilir  

### 5. Dinleme kuralları

1. Subscriber yalnız modül `enabled` iken aktiftir  
2. Handler kısa olmalı; timeout çekirdek tarafından uygulanır  
3. Hata → izolasyon (`ERROR_ISOLATION.md`); bus çökmez  
4. Idempotency anahtarı: en az `event.id`  

### 6. İsteğe bağlı (optional) modül özelliği kullanımı

Bir modül başka modülün yeteneğini **zorunlu olmadan** kullanacaksa:

1. Manifest `dependencies.modules.optional` içine hedef `moduleId` eklenir  
2. Runtime’da `ModuleRegistry.isEnabled("other-module")` (çekirdek API) kontrol edilir  
3. Enabled ise:  
   - o modülün **public event**’ine subscribe / publish zinciri, veya  
   - o modülün **versioned public contract** çağrısı (in-process port; private import değil)  
4. Enabled değilse: özellik sessizce kapanır; hata fırlatılmaz; log `level=info` + `degraded_feature`

**Yasak:** optional modül yok diye required bağımlılık gibi enable’ı bozmak.

### 7. Çekirdek platform event’leri (v1 minimum)

Kesin başlangıç seti (genişletilebilir):

| Type | Ne zaman |
|------|----------|
| `core.customer.created` / `updated` | Customer değişti |
| `core.brand.created` / `updated` | Brand değişti |
| `core.digital-asset.created` / `updated` | Asset değişti |
| `core.connection.created` / `updated` / `disabled` | Connection değişti |
| `core.module.enabled` / `disabled` / `failed` | Lifecycle |
| `core.run.started` / `completed` / `failed` | Run |
| `core.task.created` / `updated` | Görev |

`core.workspace.*` event’leri MVP’de **tanımlı değildir**.

## Gerekçe

Tek isimlendirme + envelope olmadan çapraz modül entegrasyonu kırılır. Optional bağımlılık, WordPress “plugin aktifse hook’a takıl” modelinin karşılığıdır.

## Sınırlar

- MVP taşıyıcı: Laravel events (database queue job’ları ayrı). Redis/broker yok.  
- Schema registry ürünü yok; `version` + manifest bildirimi yeterli.  
- Saga/orchestration ve MCP yok.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Event bus yok | Laravel Event / Listener + envelope sıfırdan |
| Eski `workspaceId` zarf alanı | Kaldırıldı; SaaS varsayımı yok |
| Event isimlendirme | `{kebab-module}.{kebab-action}` |
| Website Diagnosis adayları | `website-diagnosis.*` |

## Açık Sorular

Yok. Subscriber timeout varsayılanı uygulama config’inde (öneri 5000 ms). Dead-letter v1’de zorunlu değil.
