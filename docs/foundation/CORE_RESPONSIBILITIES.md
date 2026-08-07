# CORE_RESPONSIBILITIES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-007, ADR-018, ADR-020, ADR-026…029

## Kararlar

### Çekirdeğin yönettiği ortak yetenekler (ADR-020)

* Authentication (`web` guard; tek Filament panel `app` / `/app`)
* Users (Admin oluşturur; public registration yok)
* Roles / Permissions (`spatie/laravel-permission`; Admin, Team Member)
* Customers, Customer contacts, Brands
* Digital assets, Connections
* Encrypted credentials (`core_connection_credentials`)
* Module registry, Module enable/disable
* Navigation extension points
* Events, Background jobs, Scheduler
* Evidence, Findings, Recommendations, Tasks
* Notifications, Notes, Attachments, Tags
* Audit logs, Run history, Error logs
* Feature flags, Health checks, Application settings

### Connection / credential ayrımı (ADR-027)

* `core_connections`: secret olmayan kimlik, `config_json`, sağlık
* `core_connection_credentials`: `encrypted_payload` (Laravel encrypted cast); ham secret UI state’e çıkmaz

### Analysis minimum alanları (ADR-028)

MASTER_SPEC §7.2 — Evidence / Finding / Recommendation.  
Platforma özel kolon core’a eklenmez. `fingerprint` Finding tekrarını ilişkilendirir.

### Recommendation → Task (ADR-029)

Manuel dönüşüm; snapshot context; assignee/due uydurma yok.

### Çekirdeğin bilmemesi / yapmaması (ADR-007, ADR-018)

* SEO / Ads / GA4 iş kuralları, crawl, platforma özgü AI prompt, harici platform bağımlılığı
* Harici write action

## Gerekçe

Ortak akış nesneleri ve credential izolasyonu modül sınırlarını korur.

## Sınırlar

* Filament resource sınıf adları bu belgede dayatılmaz.
* Diagnosis rule katalog içeriği Website Diagnosis fazı dokümanındadır.

## Açık Sorular

Yok (Core için kararlar kilitli).
