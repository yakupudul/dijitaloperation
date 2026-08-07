# ERROR_ISOLATION

> Dayanak: ADR-009 (modül hatası uygulamayı düşürmez)  
> İlgili: `MODULE_LIFECYCLE.md`, `EVENT_CONTRACT.md`, `JOB_CONTRACT.md`

## Amaç

Modül hatalarının sınırlandırılması, yüklenememe davranışı, health check ve log korelasyonu.

## Kararlar

### 1. İzolasyon ilkeleri

1. Modül exception’ı process’i kill etmez  
2. Modül boot hatası → o modül `failed`; çekirdek + diğer modüller devam  
3. Request handler hatası → o istek 5xx/modül hata yanıtı; diğer route’lar sağlıklı  
4. Event subscriber hatası → yayıncı ve bus etkilenmez  
5. Job hatası → retry politikası; worker process ayakta kalır  
6. Shared mutable global state yasak (diğer modülü bozacak static/singleton yan etki)

### 2. Yüklenemediğinde sistem

| Senaryo | Sonuç |
|---------|--------|
| Manifest JSON bozuk | Modül `failed`; sistem ayakta |
| Core uyumsuz | Modül `failed`; sistem ayakta |
| Required bağımlılık yok | Enable reddi / `failed` |
| Migration hata | Enable reddi; önceki enabled modüller etkilenmez |
| Constructor / boot throw | Catch + `failed` |
| Bilinmeyen extension type | Skip + warning (veya politika gereği fail-modül) |

Kullanıcı etkisi: ilgili menü/sekme görünmez; admin “Module failed” görür.

### 3. Health check yanıtı

Manifest `health` kaydı için çekirdek periyodik veya on-demand çağrı yapar.

**Zorunlu dönüş şekli:**

```json
{
  "moduleId": "sample-module",
  "version": "0.1.0",
  "status": "ok",
  "checkedAt": "2026-08-06T22:00:00.000Z",
  "timeoutMs": 2000,
  "checks": [
    {
      "name": "database",
      "status": "ok",
      "message": "prefix tables reachable",
      "durationMs": 12
    }
  ],
  "details": {
    "lifecycleState": "enabled"
  }
}
```

| Alan | Değerler / kural |
|------|------------------|
| `status` | `ok` \| `degraded` \| `error` |
| `checks[].status` | aynı enum |
| Timeout | `timeoutMs` aşımı → çekirdek `status=error`, message=`health timeout` |
| Secret | `details` içinde secret/token **yasak** |

Durum etkileri:

- Tekil `degraded` → modül enabled kalabilir; admin uyarılır  
- Tekrarlayan `error` → çekirdek modülü `failed` yapabilir (eşik uygulama konfigurasyonu; varsayılan öneri: 3 ardışık)

### 4. Loglarda modül kaynağı

Tüm yapılandırılmış loglarda zorunlu alanlar:

| Alan | Örnek |
|------|--------|
| `module_id` | `sample-module` |
| `source` | `module:sample-module` veya `core` |
| `correlation_id` | varsa |
| `lifecycle_state` | relevant ise |
| entity id’ler | `customer_id` / `brand_id` / … gerektiğinde |

Kurallar:

- Modül log API’si çekirdek logger üzerinden; `module_id` otomatik enjekte edilir  
- Başka modülün `module_id`’si ile log yazmak yasak  
- Error log’da stack trace tutulabilir; secret redaksiyon zorunlu  

### 5. Harici API hataları

Connector/asset modülleri:

- timeout zorunlu  
- retry (job retry ile hizalı)  
- rate-limit (modül veya connector politikası)  
- kullanıcıya/platforma özgü ham hata sızdırılmaz; normalize error code  

### 6. UI hata yüzeyi

- Modül sekmesi crash olursa host error boundary yalnızca o slot’u değiştirir  
- Shell (nav, diğer sekmeler) çalışmaya devam eder  

## Gerekçe

Plugin ekosistemlerinde tek bozuk eklentinin siteyi düşürmesi kabul edilemez; DOP aynı disiplinle kurulur.

## Sınırlar

- Process-level memory isolation (ayrı process) v1 zorunlu değil.  
- Chaos test zorunluluğu checklist’te; altyapı yokken manuel senaryo.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Runtime yok | Error boundary + boot isolation sıfırdan tasarlanmalı |
| Logger yok | Structured logging standardı erken seçilmeli |
| Foundation “hatası uygulamayı düşürmez” | Bu belge concrete health/log alanlarını kilitler |

## Açık Sorular

Yok. Health error eşiği kurulum geneli (öneri: 3 ardışık). `degraded` iken job alımı devam eder.
