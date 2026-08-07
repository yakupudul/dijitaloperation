# MODULE_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Detay: `docs/module-sdk/*`  
> İlgili ADR: ADR-008, ADR-009, ADR-022, ADR-032

## Kararlar

1. Manifest: `module.manifest.json` (`MODULE_MANIFEST_SPEC.md`).  
2. Paketleme: `app-modules/` + `internachi/modular`; ZIP/marketplace yok.  
3. Yaşam döngüsü: discovered → registered → enabled ⇄ disabled → failed → uninstalled; veri otomatik silinmez.  
4. Veri: tablo öneki `m_{module_id_snake}_`; core ALTER yok.  
5. İletişim: events / açık contract / çekirdek. Private import/tablo ve harici write yasak.  
6. Panel katkıları: tek Filament panel `app` üzerinde extension point’ler.  
7. Website Diagnosis: connector’suz temel seviye; katalog Faz 4 öncesi; Recommendation→Task manuel.

## Gerekçe

Yerel modular paketler + SDK, bağımsız özellik eklemeyi disipline eder.

## Sınırlar

* Concrete PHP interface imzaları kod fazında.
* Diagnosis kural içerikleri katalogda.

## Açık Sorular

Yok.
