# PERMISSION_CONTRACT

> Dayanak: ADR-006 (roles and permissions)  
> İlgili: `EXTENSION_POINTS.md`, `MODULE_MANIFEST_SPEC.md`

## Amaç

Modül izinlerinin nasıl adlandırılacağı, kaydedileceği ve zorunlu kılınacağı.

## Kararlar

### 1. Permission kimliği

| Kural | Değer |
|-------|--------|
| Biçim | `{moduleId}.{action}` |
| `action` | kebab-case veya noktalı alt eylem (`notes.view`) |
| Regex | `^[a-z][a-z0-9-]*\.[a-z][a-z0-9_.]*$` |
| Örnek | `sample-module.view`, `sample-module.notes.manage` |

Çekirdek izinleri: `core.{action}` (ör. `core.brand.view`).

### 2. Manifest kaydı

```json
"permissions": [
  {
    "id": "sample-module.view",
    "title": "Örnek Modülü görüntüle",
    "description": "Marka sekmesindeki örnek içeriği görür",
    "group": "sample-module"
  },
  {
    "id": "sample-module.manage",
    "title": "Örnek Modülü yönet",
    "description": "Ayar ve not yönetimi",
    "group": "sample-module"
  }
]
```

| Alan | Zorunlu |
|------|---------|
| `id` | Evet |
| `title` | Evet |
| `description` | Hayır |
| `group` | Evet (UI gruplama; genelde moduleId) |

### 3. Kayıt ve yaşam döngüsü

1. Enable sırasında izinler çekirdek permission registry’ye upsert edilir  
2. Disable: tanımlar kalır; rol atamaları silinmez  
3. Uninstall: tanımlar “orphaned” olabilir; **otomatik silinmez** (veri koruma ile uyumlu)  
4. Aynı `id` başka modül tarafından yayınlanamaz  

### 4. Zorunlu kılma noktaları

| Katman | Kural |
|--------|--------|
| UI extension | `permission` alanı yoksa veya kullanıcıda yoksa gizle |
| HTTP/API | Modül handler girişinde çekirdek `authorize(permission)` |
| Job tetikleme (kullanıcı kaynaklı) | İlgili manage/view izni |
| Event handler | Kullanıcı izni değil; workspace/modül enable kontrolü |

### 5. Rol modeli

- Roller çekirdektedir  
- Modül kendi rolünü oluşturmaz; permission üretir  
- Workspace admin, permission’ları rollere atar  

### 6. Önerilen minimum izin çifti

Her kullanıcıya dönük modül için en az:

- `{moduleId}.view`  
- `{moduleId}.manage`  

Daha ince taneli izinler serbesttir.

## Gerekçe

İsim alanı `moduleId` ile başlayınca çakışma ve audit kolaylaşır.

## Sınırlar

- ABAC/policy motoru seçilmedi.  
- Field-level permission yok.  

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Auth/permission yok | RBAC + registry sıfırdan |
| Foundation “kendi izinlerini tanımlar” | Bu belge id şeması ve lifecycle’ı kilitler |

## Açık Sorular

1. Super-admin tüm modül izinlerini implicit mi alır?  
2. Permission rename (id değişimi) için alias mekanizması olacak mı?
