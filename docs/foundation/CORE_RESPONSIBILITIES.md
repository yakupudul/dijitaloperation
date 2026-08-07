# CORE_RESPONSIBILITIES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-020 (superseded detay), ADR-033, ADR-035, ADR-037

## Kararlar

### MVP Core zorunlu

* Authentication (`web`; Filament panel `app` / `/app`)
* Users, Roles / Permissions (`spatie/laravel-permission`; Admin, Team Member)
* Customers, Customer contacts, Brands
* Digital assets, Connections, Encrypted credentials
* Minimal Module Registry (`module_id`, enabled/disabled [, installed_version bilgisel])
* Runs, Evidence, Findings, Recommendations, Tasks
* Basic application settings
* Basic logs (Laravel log kanalları önce)
* Events, Queue (database), Scheduler

### MVP’de zorunlu değil (sonra eklenebilir)

* Attachments, Tags, Notes
* Feature flags
* Gelişmiş Notification engine
* Kapsamlı custom Audit Log / Health Check framework’leri

### Finding / Evidence (ADR-034)

* Evidence → Run’a bağlı  
* Finding → Asset’te kalıcı; fingerprint ile upsert; `last_run_id`; resolved olabilir  
* Recommendation → Finding’e bağlanabilir  

### Çekirdek bilmez / yapmaz

* Domain teşhis kuralları, crawl, harici write, platforma özgü AI prompt  
* Framework’ün çözdüğünü yeniden yazmak (ADR-033)

## Gerekçe

Hafif MVP: ürün değerine odak; altyapıda Laravel/Filament/Spatie/Modular.

## Sınırlar

* Navigation extension points Filament plugin/navigation yoluyla; ayrı mega-framework yok.
* Module enable/disable DOP UI/job/analysis kapar; Composer paketini silmez.

## Açık Sorular

Yok.
