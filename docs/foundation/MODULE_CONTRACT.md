# MODULE_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Detay: `docs/module-sdk/*`  
> İlgili ADR: ADR-008, ADR-009, ADR-022

## Kararlar

### 1. Manifest

Her modül `module.manifest.json` ile kaydolur (alanlar: `docs/module-sdk/MODULE_MANIFEST_SPEC.md`).

### 2. Paketleme (ADR-022)

* Yerel Composer package / Filament plugin  
* Tek repository içinde  
* ZIP upload / marketplace yok  

### 3. Yaşam döngüsü

Durumlar: discovered → registered → enabled ⇄ disabled → failed → uninstalled  
Disable/fail → çekirdek ayakta. Uninstall → veri otomatik silinmez.

### 4. Veri / migration

* Modül kendi migration’larını yönetir  
* Tablo öneki: `m_{module_id_snake}_`  
* Core tablolarına keyfî ALTER yok  

### 5. İletişim

İzinli: events, açık contract, çekirdek API.  
Yasak: private tablo yazımı, iç servis import’u, harici write action.

### 6. Website Diagnosis ürün sözleşmesi

1. Domain/site bilgisi kayıtlı Website asset üzerinde  
2. Run başlat (job)  
3. Evidence topla (connector’suz temel seviye mümkün)  
4. Finding oluştur  
5. Recommendation oluştur  
6. Kullanıcı manuel Task üretebilir  
7. GA4 / GSC / DataForSEO zorunlu değil; connection olarak eklenince kapsam artar  

## Gerekçe

SDK + yerel paket modeli WordPress/Perfex benzeri bağımsız özellik eklemeyi Laravel’de tekrarlar.

## Sınırlar

* Concrete PHP interface imzaları kod fazında.  
* Bu belge Filament resource sınıf adlarını dayatmaz.

## Açık Sorular

1. Public contract’lar PHP interface + event mi, yoksa yalnızca event + core modeller mi?
2. Down migration zorunluluğu eşiği nedir?
