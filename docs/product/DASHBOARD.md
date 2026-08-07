# Dashboard

## Purpose

Ajans ekibine 'Bugün neyle ilgilenmeliyim?' sorusunu cevaplamak.

## User value

Kritik açık işler ve bozulan bağlantılar tek bakışta görünür; grafik tiyatrosu değil aksiyon listesi.

## Core concepts

Progressive dashboard: veri/modül geldikçe kartlar anlamlılaşır. Yoksa sahte KPI üretilmez.

## MVP behavior

Başlangıçta sade olabilir (sistem çalışıyor + kullanıcı).  
Pipeline geldikçe kart adayları: kritik açık Findings, yüksek öncelikli Recommendations, başarısız Connections, gecikmiş Tasks, görünürlük kayıpları, ciddi Website teknik sorunları, yakın zamanda resolved önemli Findings.

## Important data / attributes

Kartlar mevcut domain kayıtlarından aggregate edilir; dashboard için ayrı sahte dataset yok.

## Relationships

Dashboard → Findings / Recommendations / Tasks / Runs / Customers.  
Cross-asset / cross-channel Findings (roadmap 22; `docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md`) may appear as action cards when present — dashboard does not invent channel scores.

## Main screens / workflows

Ana navigasyon vizyonu:
* Dashboard
* Customers → Customer → Brands → Brand → Digital Assets
* Findings
* Recommendations
* Tasks
* Runs / Audits
* Modules
* Users / Roles
* Settings

Notifications ve comprehensive System Health **sonra** (ADR-037).

## Rules / invariants

Sahte metrik yok. Veri yoksa boş/ dürüst state. External write CTA yok.

## Derived information

Tüm kartlar derived; ayrı denormalized KPI store zorunlu değil.

## Later enhancements

Kişiselleştirilmiş öncelik, bildirim merkezi, health framework.

## Explicit non-goals

Looker-benzeri BI; pazarlama demo grafikleri; MVP'de zorunlu notification/health engine.

## Acceptance intent

Dashboard aksiyon odaklıdır; mevcut olmayan veriyi uydurmaz.
