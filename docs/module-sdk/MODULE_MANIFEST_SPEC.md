# MODULE_MANIFEST_SPEC

> DOP Module SDK — Manifest sözleşmesi  
> Ana kaynak: `docs/MASTER_SPEC.md`  
> ADR-032, ADR-033, ADR-035

## MVP notu (önce oku)

MVP’de zorunlu olan: Composer package + Service Provider (+ gerektiğinde Filament plugin) ve Core **minimal registry** (`module_id`, enabled/disabled).

Aşağıdakiler **future / non-MVP** rehberdir; custom compatibility engine / migrator / lifecycle FSM’yi MVP’de uygulamaya zorlamaz:

* `core.min` / `core.maxExclusive`
* zorunlu health bloğu / karmaşık dependency motoru
* “Manifest olmadan yüklenemez” katı kuralı (Laravel discovery birincildir)

## Amaç

Modülün kendini tanıtabileceği standart bir manifest sözleşmesi. Laravel package discovery birincil yükleme yoludur.

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

### 5. Core sürüm uyumluluğu (`core`) — **future / non-MVP**

```json
"core": {
  "min": "0.1.0",
  "maxExclusive": "1.0.0"
}
```

MVP’de custom compatibility engine **yoktur**. Alanlar ileride kullanılmak üzere şemada durabilir; uygulamayı bloke etmez.

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
