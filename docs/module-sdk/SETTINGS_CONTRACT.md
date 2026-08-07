# SETTINGS_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Dayanak: ADR-020 (Application settings), ADR-021  
> İlgili: `MODULE_MANIFEST_SPEC.md`, `PERMISSION_CONTRACT.md`, `DATA_OWNERSHIP.md`

## Amaç

Modül ayarlarının bildirimi, saklama kapsamı ve okuma/yazma kuralları.

## Kararlar

### 1. Ayar anahtarı

| Kural | Değer |
|-------|--------|
| Biçim | `{moduleId}.{key}` |
| `key` | kebab-case |
| Örnek | `sample-module.greeting` |

### 2. Manifest tanımı

```json
"settings": [
  {
    "key": "sample-module.greeting",
    "title": "Karşılama metni",
    "description": "Marka sekmesinde gösterilir",
    "type": "string",
    "scope": "brand",
    "default": "Merhaba",
    "required": false,
    "secret": false,
    "permission": "sample-module.manage"
  }
]
```

| Alan | Zorunlu | Açıklama |
|------|---------|----------|
| `key` | Evet | Global unique |
| `title` | Evet | UI etiketi |
| `type` | Evet | `string` \| `number` \| `boolean` \| `enum` \| `json` \| `secret_ref` |
| `scope` | Evet | `application` \| `customer` \| `brand` \| `digital_asset` \| `connection` \| `module` |

`workspace` scope **MVP’de yoktur**. Kurulum geneli için `application` kullanılır.
| `default` | Hayır | `secret` true iken default yasak |
| `required` | Evet | boolean |
| `secret` | Evet | true ise değer yerine credential reference |
| `permission` | Evet | Yazma izni |
| `enumValues` | `type=enum` iken | string dizisi |

### 3. Saklama

- Değerler **çekirdek settings store** üzerindedir (modül private tablosuna ayar kopyalamak yasak değil ama önerilmez; tek kaynak çekirdek API).  
- `secret: true` → çekirdek **credential reference** API’si; ham secret modül log’una yazılamaz.  
- Modül disable → değerler silinmez.  
- Uninstall → değerler silinmez.

### 4. Okuma / yazma

```text
Settings.get(key, scopeRef) → value | default
Settings.set(key, scopeRef, value, actor) → requires permission
```

- `scopeRef`: ilgili entity id (`brandId` vb.) veya `module` scope için `{ moduleId }`  
- Validation: manifest `type` + `required`  
- Audit log: set/delete çekirdek audit’e yazılır (`module_id` ile)

### 5. UI

- `settings.page` extension veya çekirdek generic settings form  
- Generic form manifest’ten üretilir; custom UI design system kurallarına uyar (`EXTENSION_POINTS.md`)

## Gerekçe

Tek settings store, çapraz modül yapılandırma çakışmasını ve secret sızıntısını azaltır.

## Sınırlar

- Secret saklama: Laravel encryption (ADR-021); harici vault yok.  
- Nested form builder yok; `json` tipi ileri kullanım içindir.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Settings yok | Çekirdek settings + secret_ref store sıfırdan |
| `.env` yok | Bu sözleşme env’yi modül ayarı sanmaz; runtime env ≠ module settings |

## Açık Sorular

1. `module` scope ile `application` scope ayrımı UI’da nasıl gösterilecek?  
2. Enum değişikliklerinde eski değer validation fail mi, yoksa soft warn mi?
