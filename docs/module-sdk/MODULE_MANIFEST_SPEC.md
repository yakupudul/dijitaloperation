# MODULE_MANIFEST_SPEC

> DOP Module SDK — Manifest sözleşmesi  
> Dayanak: `docs/foundation` ADR-008, ADR-010; `MODULE_CONTRACT.md`  
> Mevcut kod: `docs/current-state` — uygulama yok (greenfield)

## Amaç

Her DOP modülü, çekirdeğin domain bilmeden keşfedebileceği tek bir **manifest** dosyası ile kendini bildirir. Manifest olmadan modül yüklenemez.

## Kararlar

### 1. Dosya adı ve konum

| Kural | Değer |
|-------|--------|
| Dosya adı | `module.manifest.json` |
| Konum | Modül kök dizini |
| Format | JSON (UTF-8) |
| Şema sürümü | Manifest içindeki `manifestVersion` alanı |

`manifestVersion` şu an **`1`** olmalıdır. Bilinmeyen major manifest sürümü → yükleme **failed**, uygulama ayakta kalır.

### 2. Modül kimliği (`id`)

| Kural | Açıklama |
|-------|----------|
| Biçim | kebab-case |
| Regex | `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` |
| Uzunluk | 2–64 karakter |
| Benzersizlik | Kurulumda global unique |
| Yasak kimlikler | `core`, `dop-core`, `system`, `platform` |

Örnekler: `website-diagnosis`, `sample-module`, `ga4-connector`.

**Karar:** Kimlik bir kez yayınlandıktan sonra değiştirilmez. Yeniden adlandırma yeni modül + migration planı demektir.

### 3. Görünen ad ve sınıf

| Alan | Zorunlu | Açıklama |
|------|---------|----------|
| `name` | Evet | UI’da görünen ad (ör. `"Website Diagnosis"`) |
| `description` | Hayır | Kısa açıklama |
| `class` | Evet | Enum: `asset` \| `connector` \| `diagnosis` \| `intelligence` \| `automation` \| `presentation` |

### 4. Modül sürümü (`version`)

| Kural | Değer |
|-------|--------|
| Şema | [SemVer 2.0.0](https://semver.org/) |
| Biçim | `MAJOR.MINOR.PATCH` (+ isteğe bağlı pre-release) |
| Kaynak | Manifest’teki `version` alanı tek otoritedir |

- **MAJOR:** kırıcı public contract / event / settings değişiklikleri  
- **MINOR:** geriye uyumlu özellik  
- **PATCH:** geriye uyumlu düzeltme  

### 5. Core sürüm uyumluluğu (`core`)

```json
"core": {
  "min": "0.1.0",
  "maxExclusive": "1.0.0"
}
```

| Alan | Anlam |
|------|--------|
| `min` | Desteklenen en düşük çekirdek sürümü (dahil) |
| `maxExclusive` | Desteklenmeyen ilk üst sürüm (hariç) |

Çekirdek sürümü aralık dışında ise modül **yüklenmez** (`failed`); diğer modüller ve çekirdek çalışmaya devam eder.

### 6. Bağımlılıklar (`dependencies`)

```json
"dependencies": {
  "modules": {
    "required": ["website"],
    "optional": ["ai-insights"]
  }
}
```

| Tür | Davranış |
|-----|----------|
| `required` | Bağımlı modül `enabled` değilse bu modül `enabled` olamaz; durum `failed` veya enable reddi |
| `optional` | Yoksa veya disabled ise modül çalışır; ilgili özellikler degrade olur (`EVENT_CONTRACT.md`, `MODULE_DEVELOPMENT_GUIDE.md`) |

- Döngüsel `required` bağımlılık yasaktır; çekirdek kayıt sırasında reddeder.
- Versiyon aralığı modül bağımlılıkları için **v1 manifest’te yok**; ihtiyaç doğarsa `manifestVersion: 2` ile eklenir (uydurma yapılmaz).

### 7. Zorunlu üst düzey alanlar (manifestVersion 1)

| Alan | Tip | Açıklama |
|------|-----|----------|
| `manifestVersion` | number | `1` |
| `id` | string | Modül kimliği |
| `name` | string | Görünen ad |
| `version` | string | SemVer |
| `class` | string | Modül sınıfı |
| `core` | object | `min` + `maxExclusive` |
| `permissions` | array | İzin tanımları (`PERMISSION_CONTRACT.md`) |
| `settings` | array | Ayar tanımları (`SETTINGS_CONTRACT.md`) |
| `extensions` | array | Extension kayıtları (`EXTENSION_POINTS.md`) |
| `jobs` | array | Job tanımları (`JOB_CONTRACT.md`) |
| `events` | object | `publishes` + `subscribes` (`EVENT_CONTRACT.md`) |
| `data` | object | Tablo/önek sahipliği (`DATA_OWNERSHIP.md`) |
| `health` | object | Health check kaydı |
| `dependencies` | object | Modül bağımlılıkları (boş olabilir) |

### 8. Minimal örnek iskelet

```json
{
  "manifestVersion": 1,
  "id": "sample-module",
  "name": "Örnek Modül",
  "version": "0.1.0",
  "class": "presentation",
  "core": { "min": "0.1.0", "maxExclusive": "1.0.0" },
  "dependencies": { "modules": { "required": [], "optional": [] } },
  "permissions": [],
  "settings": [],
  "extensions": [],
  "jobs": [],
  "events": { "publishes": [], "subscribes": [] },
  "data": {
    "tablePrefix": "m_sample_module_",
    "tables": []
  },
  "health": { "id": "default", "timeoutMs": 2000 }
}
```

`data.tables` isteğe bağlı belgeleme dizisidir (name + description); zorunlu sahiplik alanı `tablePrefix`tır. Tam dolu örnek: `SAMPLE_MODULE_SPEC.md`.

### 9. Health bloğu (manifest)

| Alan | Zorunlu | Açıklama |
|------|---------|----------|
| `health.id` | Evet | Modül içi check kimliği (çoğunlukla `"default"`) |
| `health.timeoutMs` | Evet | Üst süre; aşım → check `error` |

Dönen payload: `ERROR_ISOLATION.md` ve `SAMPLE_MODULE_SPEC.md`.

## Gerekçe

Somut alan adları olmadan modül registry uygulanamaz. Foundation “alanlar netleşecek” demişti; Module SDK bu netleştirmeyi yapar.

## Sınırlar

- Paketleme: **`app-modules/`** + **`internachi/modular`** (ADR-032). ZIP/marketplace yok.
- Manifest imzalama yok.
- `dependencies.modules` için SemVer aralığı v1’de yok.
- Pre-release (`0.1.0-beta.1`) enable: Admin kararıyla serbest (ürün bloker değil).

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Uygulama / module registry yok | `internachi/modular` + manifest registry sıfırdan |
| Dizin | `app-modules/{module-id}/` |
| Stack | Laravel 13 + Filament 5 panel `app` |

## Açık Sorular

Yok.
