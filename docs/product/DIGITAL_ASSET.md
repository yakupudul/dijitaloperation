# Digital Asset

## Purpose

Digital Asset, markaya bağlı olarak gerçekten yönetilen dijital şeydir.

## User value

Ekip 'neyi yönetiyoruz?' sorusuna net cevap verir; bağlantılar ve teşhisler asset üzerinde toplanır.

## Core concepts

**DIGITAL ASSET** = yönetilen şey (Website, GBP, Google Ads account, Meta Ads, Instagram, YouTube, CRM, …)  
**CONNECTION** = asset'i incelemek için veri sağlayan kaynak (WordPress, GA4, GSC, DataForSEO, PageSpeed, …)  
GA4/GSC/DataForSEO ayrı Website asset değildir; Website connection'larıdır.

## MVP behavior

* Asset Brand'a bağlıdır; type + name + status
* Connections asset'e bağlanır
* Asset ekranı Runs / Findings / Recommendations / Tasks'e köprü olur (pipeline geldikçe)
* Yeni asset türleri modüllerle eklenir
* Platforma özel şişkin Core kolonları yok

## Important data / attributes

name, type, brand_id, status, module ownership. last success/error/health zorunlu duplicate kolon değildir.

## Relationships

Brand → Digital Asset → Connection; Asset → Runs → Evidence; Asset → Findings → Recommendations → Tasks.

## Main screens / workflows

Asset list (per brand), asset detail overview, connections management entry, operational tabs as features land.

## Rules / invariants

Connection asset değildir. Harici write yok. Core, her platform field'ını taşımak zorunda değildir.

## Derived information

Latest operational state, last successful run, connection health — Run/Connection verisinden UI'da derive edilebilir.

## Later enhancements

Cross-asset views, richer health widgets, asset-level SLAs.

## Explicit non-goals

Her API kaynağını Digital Asset yapmak; Result entity; SaaS multi-tenant asset isolation.

## Acceptance intent

Ekip Brand altında typed Digital Asset kaydı oluşturup Connection bağlama yolunu açabilir.
