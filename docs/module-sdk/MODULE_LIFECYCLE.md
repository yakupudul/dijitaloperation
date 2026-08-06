# MODULE_LIFECYCLE

> Dayanak: ADR-008, ADR-014; `MODULE_CONTRACT.md`  
> İlgili: `MODULE_MANIFEST_SPEC.md`, `ERROR_ISOLATION.md`, `DATA_OWNERSHIP.md`

## Amaç

Modülün keşiften kaldırmaya kadar geçirdiği durumları ve her durumda sistem davranışını tanımlar.

## Kararlar

### 1. Durumlar

| Durum | Anlam |
|-------|--------|
| `discovered` | Paket/manifest diskte bulundu; henüz registry’ye işlenmedi |
| `registered` | Manifest doğrulandı; registry kaydı var; henüz aktif değil |
| `enabled` | Extension, job, event listener ve route’lar aktif |
| `disabled` | Kasıtlı kapalı; veri durur; çekirdek ve diğer modüller çalışır |
| `failed` | Yükleme, uyumluluk, required bağımlılık veya tekrarlayan health hatası |
| `uninstalled` | Registry’den çıkarıldı; **veri otomatik silinmez** |

Geçiş diyagramı (özet):

```text
discovered → registered → enabled ⇄ disabled
                ↓            ↓
             failed ←———————┘
                ↓
           uninstalled (veri korunur)
```

### 2. Enable öncesi kontroller (sırayla)

1. Manifest parse + şema doğrulama  
2. `core.min` / `core.maxExclusive` uyumu  
3. `required` modül bağımlılıkları `enabled` mi?  
4. Pending migration’lar uygulanabilir mi? (uygula veya enable’ı reddet)  
5. Permission / settings / extension / job / event kayıtları  
6. İlk health check (timeout içinde)

Herhangi bir adım başarısız → durum `failed` veya enable işlemi reddedilir; **process çökmez**.

### 3. Modül `disabled` olduğunda

| Alan | Davranış |
|------|----------|
| HTTP route / UI extension | Kaydı gizlenir / çağrılmaz |
| Event subscribers | Çağrılmaz |
| Job schedule / consume | Yeni job kabul edilmez; çalışan job iptal politikası: mümkünse graceful cancel |
| Settings UI | Okunabilir (read-only) veya gizli — varsayılan: admin’de görünür, kullanıcıda gizli |
| Permissions | Tanımlar registry’de kalır; yeni yetki ataması önerilmez |
| Tablolar / veri | **Korunur** |
| Çekirdek | Çalışmaya devam eder |
| Diğer modüller | Çalışmaya devam eder; bu modüle `optional` bağımlı olanlar degrade olur |

### 4. Modül `failed` olduğunda

| Alan | Davranış |
|------|----------|
| Sistem | Ayakta kalır |
| UI | Admin’e “modül yüklenemedi / bozuldu” durumu gösterilir; son kullanıcı akışları kırılmaz |
| Extension’lar | Aktif sayılmaz |
| Log | `module_id`, `lifecycle_state=failed`, hata kodu zorunlu |

### 5. Uninstall / kaldırma

1. Modül önce `disabled` yapılır.  
2. Registry kaydı `uninstalled` olur.  
3. **Veri silinmez** (ADR-014).  
4. Veri silme yalnızca ayrı, yetkili, onaylı “purge” operasyonudur; varsayılan uninstall purge değildir.  
5. Purge yoksa tablolar ve settings değerleri yerinde kalır; aynı `id` ile yeniden kurulum veriyi yeniden bağlayabilir.

### 6. Migration yaşam döngüsü

| An | Kural |
|----|--------|
| Enable / upgrade | Modülün kendi migration zinciri çekirdek migrator tarafından **modül scope’unda** çalıştırılır |
| Disable | Migration geri alınmaz |
| Uninstall | Migration geri alınmaz; drop yok |
| Rollback | “Mümkün olduğunda” down migration desteklenir; geri alınamaz değişiklik ADR ile kayda geçer |

Ayrıntı: `DATA_OWNERSHIP.md`.

### 7. Versiyon yükseltme

1. Yeni paket + yeni manifest `version`  
2. SemVer major ise public contract breaking notu zorunlu  
3. Migration’lar uygulanır  
4. Health check geçer  
5. State `enabled` kalır veya kısa süreli `registered`→`enabled`

## Gerekçe

WordPress/Perfex benzeri enable/disable modeli operatör alışkanlığına uyar; veri silmeme üretim kazalarını önler.

## Sınırlar

- Hot-reload / runtime plugin inject teknolojisi seçilmedi.  
- Multi-version side-by-side aynı `id` desteklenmez (tek aktif sürüm).

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Lifecycle kodu yok | Module registry + state machine sıfırdan |
| DB yok | State saklama tablosu çekirdekte tasarlanacak (`module_registry` veya eşdeğeri — uygulama aşaması) |
| Foundation durum adları “isimler kesinleşebilir” diyordu | Bu belge kanonik adları kilitler: `registered`, `enabled`, `disabled`, `failed`, (+ `discovered`, `uninstalled`) |

## Açık Sorular

1. `failed` modül otomatik yeniden deneme (backoff) yapsın mı?  
2. Workspace bazlı enable mi, global mi? (multi-tenant model henüz kilitli değil)
