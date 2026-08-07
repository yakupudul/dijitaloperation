# MODULE_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Sözleşme: `docs/module-sdk/*`  
> İlgili ADR: ADR-004, ADR-005, ADR-008, ADR-021, ADR-022, ADR-024

## Kararlar

### 1. Mimari stil

Plugin-based **modular monolith**:

* Tek repository
* Tek uygulama
* Tek deployment
* Tek veritabanı
* Net modül sınırları
* Background jobs + scheduler
* Yerel modüller (Composer package / Filament plugin)
* ZIP / marketplace yok

### 2. Teknoloji eşlemesi (ADR-021)

| Konu | Seçim |
|------|--------|
| App | Laravel 13 / PHP 8.3+ |
| Panel | Filament 5 / Livewire |
| Queue | Database queue (başlangıç) |
| Scheduler | Laravel scheduler |
| Events | Laravel events |
| HTTP | Laravel HTTP client |
| Encryption | Laravel encryption |
| Test | Pest |
| Modül paketi | Monorepo içi Composer path repository / Filament plugin |

### 3. Modül kuralları

Her modül: manifest, sürüm, bağımlılık, migration, model, servis, panel resource/sayfa, permission, setting, job, event, health check, test içerir.  
Disable → çekirdek çalışmaya devam eder. Ayrıntı: `docs/module-sdk`.

### 4. Modül sınıfları

Asset | Connector | Diagnosis | Intelligence | Automation | Presentation  

MVP’de Presentation = ajans Filament paneline katkı (Client Portal yok).

### 5. İlk modüller (ADR-024)

1. Website  
2. Website Diagnosis  
3. WordPress Connector  
4. Search Console Connector  
5. GA4 Connector  
6. PageSpeed / Lighthouse Connector  
7. DataForSEO Connector  
8. AI Insights  

### 6. Website Diagnosis

* Connector zorunlu değildir (ADR-012 sürer).  
* Connection’lar eklendikçe kapsam ve güven artar.  
* Akış: `MASTER_SPEC` §10.

## Gerekçe

Yerel Composer/Filament plugin modeli, Laravel ekosisteminde plugin sınırlarını koruyarak tek deploy basitliğini sağlar.

## Sınırlar

* Redis/Horizon yok sayılmaz; ihtiyaç ADR’si ile eklenir.
* Modül klasör standardı (`modules/` vs `packages/`) uygulama iskeletinde kilitlenir.

## Açık Sorular

1. Modül path repository kök dizini adı ne olacak?
2. Website asset ile Website Diagnosis ayrı Composer paketleri mi (öneri: evet)?
