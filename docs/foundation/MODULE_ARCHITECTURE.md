# MODULE_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-004, ADR-032, ADR-033, ADR-035

## Kararlar

### 1. Temel

Modular monolith: tek repo/app/deploy/DB.  
Paketleme: **`app-modules/`** + **`internachi/modular`** + Composer + Laravel Service Provider + Filament Plugin.

### 2. MVP Module Registry (ADR-035)

Minimum alanlar: `module_id`, `enabled`/`disabled`, isteğe bağlı bilgisel `installed_version`.

Disabled → DOP UI / scheduled analysis jobs kapalı; kod Composer’da kalabilir; veri silinmez.

### 3. MVP’de yazılmayacak custom framework parçaları (future / non-MVP)

* compatibility engine (`core.min` / `core.maxExclusive`)
* custom module migrator / migration registry
* discovered/registered/failed/uninstalled FSM
* runtime plugin install, purge, marketplace, custom schema registry

Bunlar `docs/module-sdk` içinde belgelenebilir ancak **MVP implementasyonunu zorlamaz**.

### 4. İlk ürün modülleri

Website → Website Diagnosis → connectors → AI Insights (sıra roadmap’te).  
Sample module = kısa smoke test, büyük faz değil.

## Gerekçe

ADR-033: paketlerin verdiğini tekrar yazmamak MVP hızını korur.

## Sınırlar

* Diagnosis katalog içeriği bu belgede üretilmez.
* PHPUnit (ADR-038; Pest vs PHPUnit ürün kapsamını değiştirmez — canonical PHPUnit)
## Açık Sorular

Yok.
