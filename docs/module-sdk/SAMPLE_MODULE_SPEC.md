# SAMPLE_MODULE_SPEC

> Gerçek uygulama kodu içermez.  
> Amaç: SDK sözleşmelerinin uçtan uca dolu bir örneği.  
> Modül kimliği: `sample-module`

## 1. Özet

| Alan | Değer |
|------|--------|
| id | `sample-module` |
| name | Örnek Modül |
| class | `presentation` (örnek amaçlı; diagnosis değil) |
| version | `0.1.0` |
| Amaç | Brand detayına sekme ekler; ayar, permission, job, event, tablo, health gösterir |
| Disable | Kapatılınca çekirdek ve diğer modüller çalışmaya devam eder |

## 2. Klasör iskeleti (kod değil, sözleşme)

```text
sample-module/
  module.manifest.json
  migrations/
    20260806100000_create_notes.md   # gerçekte SQL/ORM dosyası olur; burada tanım
  README.md
```

## 3. `module.manifest.json` (tam)

```json
{
  "manifestVersion": 1,
  "id": "sample-module",
  "name": "Örnek Modül",
  "description": "SDK doğrulama amaçlı örnek modül",
  "version": "0.1.0",
  "class": "presentation",
  "core": {
    "min": "0.1.0",
    "maxExclusive": "1.0.0"
  },
  "dependencies": {
    "modules": {
      "required": [],
      "optional": ["ai-insights"]
    }
  },
  "permissions": [
    {
      "id": "sample-module.view",
      "title": "Örnek Modülü görüntüle",
      "description": "Marka sekmesindeki örnek içeriği görüntüler",
      "group": "sample-module"
    },
    {
      "id": "sample-module.manage",
      "title": "Örnek Modülü yönet",
      "description": "Not oluşturma, ayar değiştirme ve job tetikleme",
      "group": "sample-module"
    }
  ],
  "settings": [
    {
      "key": "sample-module.greeting",
      "title": "Karşılama metni",
      "description": "Marka sekmesinde gösterilecek metin",
      "type": "string",
      "scope": "brand",
      "default": "Merhaba",
      "required": false,
      "secret": false,
      "permission": "sample-module.manage"
    }
  ],
  "extensions": [
    {
      "type": "navigation.tab.brand",
      "id": "main",
      "title": "Örnek Modül",
      "target": "brand",
      "route": "sample-module/brands/:brandId",
      "permission": "sample-module.view",
      "order": 50,
      "icon": "puzzle"
    },
    {
      "type": "settings.page",
      "id": "settings",
      "title": "Örnek Modül Ayarları",
      "route": "sample-module/settings",
      "permission": "sample-module.manage",
      "order": 50
    }
  ],
  "jobs": [
    {
      "id": "sample-module.refresh-notes",
      "title": "Örnek notları yenile",
      "queue": "default",
      "maxAttempts": 3,
      "timeoutMs": 30000,
      "concurrency": 1,
      "retry": {
        "strategy": "exponential",
        "initialDelayMs": 1000,
        "maxDelayMs": 60000
      }
    }
  ],
  "events": {
    "publishes": [
      {
        "type": "sample-module.note-created",
        "version": 1
      },
      {
        "type": "sample-module.refresh-completed",
        "version": 1
      }
    ],
    "subscribes": [
      {
        "type": "core.brand.updated",
        "version": 1
      }
    ]
  },
  "data": {
    "tablePrefix": "m_sample_module_",
    "tables": [
      {
        "name": "m_sample_module_notes",
        "description": "Marka bazlı örnek notlar"
      }
    ]
  },
  "health": {
    "id": "default",
    "timeoutMs": 2000
  }
}
```

## 4. Veri tablosu (sözleşme)

**Tablo:** `m_sample_module_notes`

| Kolon | Tip (mantıksal) | Açıklama |
|-------|-----------------|----------|
| `id` | uuid | PK |
| `brand_id` | uuid | Core Brand referansı |
| `body` | string | Not metni |
| `created_by` | uuid | User ref |
| `created_at` | datetime | |
| `updated_at` | datetime | |

MVP’de `workspace_id` kolonu yoktur.

Migration adı: `20260806100000_create_notes`  
Down: tabloyu drop (mümkün; örnek modül için kabul)  
Uninstall: bu down **otomatik çalışmaz**.

## 5. Brand sekmesi davranışı

1. Kullanıcı Brand detayına girer  
2. `sample-module.view` varsa “Örnek Modül” sekmesi görünür  
3. Sekmede `sample-module.greeting` ayarı (brand scope) okunur  
4. Not listesi `m_sample_module_notes` üzerinden gelir  
5. `sample-module.manage` ile yeni not eklenir → event `sample-module.note-created`  

UI: çekirdek Tab + Table + Form primitive’leri; global CSS yok.

## 6. Event örnekleri

### Publish: `sample-module.note-created`

```json
{
  "id": "11111111-1111-1111-1111-111111111111",
  "type": "sample-module.note-created",
  "version": 1,
  "occurredAt": "2026-08-06T22:00:00.000Z",
  "moduleId": "sample-module",
  "correlationId": "corr_1",
  "payload": {
    "noteId": "note_1",
    "brandId": "brand_1",
    "customerId": "customer_1",
    "bodyPreview": "İlk not"
  }
}
```

### Subscribe: `core.brand.updated`

- Handler: ilgili brand için “refresh needed” bayrağı yazar veya `sample-module.refresh-notes` job enqueue eder  
- Ağır iş handler içinde yapılmaz  

## 7. Job: `sample-module.refresh-notes`

| Alan | Değer |
|------|--------|
| Tetik | Kullanıcı (manage) veya `core.brand.updated` sonrası |
| İş | Brand notlarını tutarlılık için yeniden işler (örnek) |
| Bitince | `sample-module.refresh-completed` publish |

Disable iken yeni job kabul edilmez.

## 8. Optional bağımlılık: `ai-insights`

- `ai-insights` enabled ise: not oluşturulunca o modülün public event/contract’ı ile “özet öner” özelliği açılır  
- Değilse: UI özelliği gizlenir; hata yok; log `degraded_feature=ai-summary`

## 9. Health check örneği

```json
{
  "moduleId": "sample-module",
  "version": "0.1.0",
  "status": "ok",
  "checkedAt": "2026-08-06T22:05:00.000Z",
  "timeoutMs": 2000,
  "checks": [
    { "name": "manifest", "status": "ok", "message": "valid", "durationMs": 1 },
    { "name": "database", "status": "ok", "message": "m_sample_module_notes reachable", "durationMs": 8 }
  ],
  "details": { "lifecycleState": "enabled", "notesCountApprox": 12 }
}
```

## 10. Disable senaryosu

| Bileşen | Sonuç |
|---------|--------|
| Brand sekmesi | Kaybolur |
| Subscribers / jobs | Pasif |
| `m_sample_module_notes` | Veri durur |
| Greeting setting | Durur |
| Core brand sayfası | Çalışır |
| Diğer modüller | Çalışır |

## 11. Log örneği (şema)

```json
{
  "level": "info",
  "message": "note created",
  "module_id": "sample-module",
  "source": "module:sample-module",
  "correlation_id": "corr_1",
  "brand_id": "brand_1",
  "customer_id": "customer_1"
}
```

## 12. Bu örneğin test kapısı

`MODULE_TEST_CHECKLIST.md` maddelerinin tamamı bu modül için uygulanabilir duman senaryosudur.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Hiç modül yok | `sample-module` ilk referans implementasyon adayı olabilir (kod bu PR’da yok) |
| Brand detay UI yok | Önce core brand host + tab extension point gerekir |
