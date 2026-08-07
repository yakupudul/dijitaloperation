# MODULE_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Detay: `docs/module-sdk/*` (MVP vs future ayrımı)  
> İlgili ADR: ADR-033, ADR-035

## Kararlar (MVP)

1. Paketleme: `app-modules/` + `internachi/modular` + Composer + Service Provider (+ Filament plugin gerektiğinde).  
2. Registry: `module_id`, `enabled`/`disabled` [, bilgisel version].  
3. Disabled → DOP UI / schedule / analysis kapalı; veri ve Composer paketi kalır.  
4. Migration: **Laravel module/package migrations** — custom migrator/registry MVP’de yok.  
5. İletişim: Laravel events + çekirdek modeller; private tablo/import ve harici write yasak.  
6. Website Diagnosis: connector’suz temel seviye mümkün; Recommendation→Task manuel.

## Future / non-MVP (belgede kalır, implementasyonu zorlamaz)

* `core.min` / `core.maxExclusive` compatibility engine  
* discovered/registered/failed/uninstalled FSM  
* purge, marketplace, ZIP install, custom schema registry  

## Gerekçe

ADR-033: framework özelliklerini tekrar yazmamak.

## Sınırlar

* Manifest dosyası faydalı olabilir; zorunlu mega-şema MVP blocker değildir.

## Açık Sorular

Yok.
