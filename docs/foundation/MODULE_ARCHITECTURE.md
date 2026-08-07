# MODULE_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Sözleşme: `docs/module-sdk/*`  
> İlgili ADR: ADR-004, ADR-008, ADR-021, ADR-022, ADR-032, ADR-024

## Kararlar

### 1. Mimari stil

Plugin-based modular monolith: tek repo, tek app, tek deploy, tek DB, net sınırlar, background jobs.

### 2. Modül dizini ve paketleme (ADR-032)

| Konu | Karar |
|------|--------|
| Kök dizin | `app-modules/` |
| Araç | `internachi/modular` |
| Davranış | Her modül Composer package |
| Yasak | ZIP upload, marketplace |

Her modül: service provider, models, migrations, services, Filament resources/pages/widgets, config, jobs, events, tests (+ manifest, permissions, settings, health).

### 3. Teknoloji eşlemesi

| Konu | Seçim |
|------|--------|
| App | Laravel 13 / PHP 8.3+ |
| Panel | Filament 5 — id `app`, path `/app` |
| RBAC | `spatie/laravel-permission` |
| Queue | Database queue |
| AI | `laravel/ai` |
| Test | Pest |

### 4. İlk modüller (ADR-024)

Website, Website Diagnosis, WordPress/GSC/GA4/PageSpeed-Lighthouse/DataForSEO connectors, AI Insights.

### 5. Website Diagnosis

* Connector zorunlu değil (ADR-012)  
* Katalog: Faz 4 öncesi `docs/website/DIAGNOSIS_CATALOG.md` (ADR-031); Core blocker değil  

## Gerekçe

`internachi/modular` + `app-modules/` Laravel’de yerel plugin sınırlarını standartlaştırır.

## Sınırlar

* Redis/Horizon ihtiyaç ADR’si ile.
* Katalog içeriği bu belgede üretilmez.

## Açık Sorular

Yok.
