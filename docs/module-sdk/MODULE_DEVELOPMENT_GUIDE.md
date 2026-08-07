# MODULE_DEVELOPMENT_GUIDE

> Pratik kılavuz: WordPress eklentisi / Perfex modülü geliştirir gibi DOP modülü eklemek  
> Normatif sözleşmeler: diğer `docs/module-sdk/*` dosyaları  
> Ürün/mimari dayanak: `docs/foundation/*`  
> Mevcut kod: `docs/current-state` — uygulama yok

## Amaç

Yeni bir özelliği çekirdeğe yamamak yerine **bağımsız modül** olarak nasıl tasarlayıp yayınlayacağınızı adım adım anlatır.

## Kararlar (geliştirme akışı)

### Adım 0 — Modül mü, çekirdek mi?

Çekirdeğe sadece `CORE_RESPONSIBILITIES` listesindeki ortak yetenekler girer.  
SEO kuralı, Meta metriği, crawl, platforma özgü AI prompt → **modül**.

### Adım 1 — Kimlik, dizin ve sürüm

1. Dizin: `app-modules/{id}/` (`internachi/modular`)  
2. `id` seç: kebab-case, kalıcı (`website-diagnosis`)  
3. `version` başlat: `0.1.0`  
4. `core.min` / `core.maxExclusive` belirle  
5. `class` seç: asset | connector | diagnosis | intelligence | automation | presentation  

Ayrıntı: `MODULE_MANIFEST_SPEC.md`.

### Adım 2 — Manifest’i yaz

`module.manifest.json` oluştur; boş diziler bile zorunlu alanları taşımalı.  
Referans: `SAMPLE_MODULE_SPEC.md`.

### Adım 3 — Veri sahipliği

1. `tablePrefix`: `m_{id_snake}_`  
2. `migrations/` altında yalnızca bu prefix ile tablolar  
3. Başka modül / core tablosuna direkt yazma  

Ayrıntı: `DATA_OWNERSHIP.md`.

### Adım 4 — Permission ve settings

1. En az `.view` / `.manage`  
2. Ayar anahtarları `{id}.{key}`  
3. Secret ise `secret_ref`  

### Adım 5 — UI extension

- Menü: `navigation.menu`  
- Sekmeler: `navigation.tab.customer` | `.brand` | `.digital_asset`  
- Route: `{moduleId}/...`  
- Design system dışına çıkma  

Ayrıntı: `EXTENSION_POINTS.md`.

### Adım 6 — Jobs ve events

1. Uzun iş → job kaydı + handler  
2. Publish/subscribe tiplerini manifest’e yaz  
3. Envelope alanlarını doldur  
4. Optional modül için `dependencies.modules.optional` + runtime `isEnabled`  

### Adım 7 — Health ve log

- Health şemasını uygula  
- Logger’a `module_id` enjekte ettir  

### Adım 8 — Lifecycle doğrula

Enable → kullan → disable → veri duruyor mu → failed simülasyonu → uninstall (purge yok).

### Adım 9 — Checklist

`MODULE_TEST_CHECKLIST.md` tamamlanmadan yayınlama.

---

## Hızlı referans tablosu

| Soru | Cevap |
|------|--------|
| Modül kimliği? | kebab-case `id`, kalıcı |
| Sürüm? | SemVer `version` |
| Core uyumu? | `core.min` + `core.maxExclusive` |
| Bağımlılık? | `dependencies.modules.required\|optional` |
| Extension’lar? | `extensions[]` type kataloğu |
| Customer/Brand/Asset sekmesi? | `navigation.tab.*` |
| Menü? | `navigation.menu` |
| Permission? | `{id}.{action}` + manifest `permissions` |
| Ayarlar? | `{id}.{key}` + core settings store |
| Migration? | Modül `migrations/`; core migrator; prefix enforce |
| Tablo sahipliği? | `m_{id_snake}_*` yalnız sahibi yazar |
| Job? | `{id}.{job}` + jobs[] |
| Event? | `{id}.{event}` publish/subscribe |
| Disable? | UI/job/sub pasif; veri kalır; sistem ayakta |
| Load fail? | Modül `failed`; sistem ayakta |
| Uninstall veri? | Otomatik silinmez |
| Health? | `ok\|degraded\|error` + checks[] |
| Log kaynağı? | `module_id`, `source=module:{id}` |
| Optional özellik? | optional dep + registry check + degrade |
| UI bütünlüğü? | core design tokens/primitives; no global CSS |
| Yayın öncesi? | `MODULE_TEST_CHECKLIST.md` |

---

## İsteğe bağlı modül özelliği kullanma (özet)

```text
manifest.dependencies.modules.optional += ["other-module"]
runtime:
  if ModuleRegistry.isEnabled("other-module"):
      use public contract OR subscribe/publish their public events
  else:
      hide feature; log degraded_feature; continue
```

Private import / private table → **asla**.

---

## Teknoloji notu

`docs/MASTER_SPEC.md` / ADR-021:

* Laravel 13, PHP 8.3+, Filament 5 (panel `app` / `/app`), Livewire, MySQL 8  
* `spatie/laravel-permission`, database queue, Laravel scheduler/events/HTTP/encryption, Pest  
* AI: `laravel/ai` (key env’de)  
* Modüller: `app-modules/` + `internachi/modular`  

API adları sözleşme düzeyindedir; bu PR’da kod yazılmaz.

## Gerekçe

Tek kılavuz, 12 sözleşmeyi günlük geliştirme sırasına dizer; yeni katkıların çekirdeği şişirmesini engeller.

## Sınırlar

- Yayın marketi / imzalama yok.  
- Website Diagnosis kural kataloğu `docs/website/DIAGNOSIS_CATALOG.md` (Faz 4 öncesi).  
- SDK örneği: `sample-module` (Faz 2).

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Greenfield | Roadmap Faz 0–2 |
| Modül kökü | `app-modules/` |
| Harici write | Yasak (ADR-018) |

## Açık Sorular

Yok. Website Diagnosis, `MODULE_TEST_CHECKLIST` kapısını kullanır.
