# DATA_OWNERSHIP

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Dayanak: ADR-009, ADR-014, ADR-017, ADR-021  
> İlgili: `MODULE_LIFECYCLE.md`, `SETTINGS_CONTRACT.md`

## Amaç

Modül tablolarının sahipliği, isimlendirme, migration ve kaldırmada veri koruma kuralları.

## Kararlar

### 1. Sahiplik

| Kaynak | Sahip | Kim yazar? |
|--------|--------|------------|
| Core tablolar (`customers`, `brands`, `digital_assets`, `core_connections`, users, roles, evidence, findings, recommendations, tasks, …) | Çekirdek | Yalnızca çekirdek |
| `core_connection_credentials` | Çekirdek | Çekirdek API; ham secret UI’ya çıkmaz |
| Modül tabloları | İlgili modül | Yalnızca o modül |
| Settings store | Çekirdek | Çekirdek API |
| Tasks / notifications / audit | Çekirdek | Çekirdek API |

**Yasaklar:**

- Modül → başka modülün private tablosuna SQL/ORM ile yazamaz / okuyamaz  
- Modül → core tablolarını keyfî ALTER/UPDATE edemez  
- Modül → core satırlarını doğrudan UPDATE ile “iş kuralı” uygulayamaz; core API kullanır  

Okuma ihtiyacı çapraz ise: event, public contract veya core API.

### 2. Tablo öneki (table prefix)

Tercihen tek veritabanı (ADR-005). v1 sahiplik koruması:

| Kural | Değer |
|-------|--------|
| Manifest alanı | `data.tablePrefix` |
| Biçim | `m_{module_id_snake}_` |
| Dönüşüm | kebab `sample-module` → `m_sample_module_` |
| Örnek tablo | `m_sample_module_notes` |

Kurallar:

- Modül yalnızca kendi `tablePrefix` ile başlayan tabloları oluşturur  
- Çekirdek migrator, prefix dışına çıkan migration’ı reddeder  
- Fiziksel DB schema ayrımı ileride eklenebilir; v1 için prefix zorunlu sözleşme  

### 3. Foreign key politikası

- Modül tabloları core entity id’lerine **mantıksal referans** tutabilir (`brand_id`, `digital_asset_id`, `connection_id`, `customer_id`)  
- MVP’de `workspace_id` kullanılmaz
- Fiziksel FK zorunlu değildir; kullanılıyorsa ON DELETE davranışı **core silme politikasına** bırakılır (Eloquent/MySQL)
- Başka modül tablosuna FK **yasaktır**

### 4. Migration nasıl çalışır?

| Kural | Açıklama |
|-------|----------|
| Konum | Modül içi `migrations/` dizini |
| Sahiplik | Her dosya yalnızca o modüle ait |
| Çalıştırıcı | Çekirdek Module Migrator |
| Kayıt | Çekirdek `schema_migrations` benzeri tabloya `(module_id, migration_id, applied_at)` |
| Enable / upgrade | Pending migration’lar uygulanmadan `enabled` olunamaz |
| Disable | Down çalışmaz |
| Uninstall | Drop/down çalışmaz; veri kalır |
| Down | Mümkün olduğunca sağlanır; yoksa ADR + not |

Migration kimliği: sıralı ve benzersiz (`20260806120000_create_notes`).

### 5. Kaldırmada veri koruma

```text
uninstall ≠ delete data
purge = ayrı, yetkili, onaylı operasyon
```

| Operasyon | Tablolar | Settings | Permissions |
|-----------|----------|----------|-------------|
| disable | kalır | kalır | kalır |
| uninstall | kalır | kalır | kalır (orphan olabilir) |
| purge (opsiyonel) | drop/truncate bilinçli | silinebilir | temizlenebilir |

Purge varsayılan değildir; UI’da çift onay + `moduleId` yazarak onay önerilir (UX uygulama aşaması).

### 6. Yeniden kurulum

Aynı `id` yeniden enable edilirse:

- Mevcut tablolar ve satırlar yeniden kullanılır  
- Migration kaldığı yerden devam eder  
- “Boş kurulum” isteniyorsa ayrı purge gerekir  

## Gerekçe

Prefix + migrator reddi, modular monolith’te fiili şema izolasyonu sağlar; veri silmeme operasyonel güvenlik sağlar.

## Sınırlar

- ORM: Eloquent (Laravel).  
- Multi-DB / schema-per-module zorunlu değil (tek MySQL 8).  
- Online DDL politikası yok.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| DB/migration yok | Laravel migrator + modül prefix enforcement sıfırdan |
| Prefix | `m_{id_snake}_` |
| Workspace tabloları | Oluşturulmaz |

### Credential notu (ADR-027)

* Secret’lar `core_connection_credentials.encrypted_payload`  
* Laravel encrypted cast / encryption  
* Filament/Livewire model state’ine ham değer yazılmaz  

## Açık Sorular

Yok. Purge yetkisi: Admin + `core.modules.purge` (uygulama permission id’si; Core’u bloke etmez).
