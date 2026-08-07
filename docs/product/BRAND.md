# Brand

## Purpose

Brand, bir Customer altındaki markadır. Cross-channel dijital varlıkların ortak bağlamını sağlar.

## User value

Website, Ads, GBP gibi varlıklar aynı marka altında toplanır; ekip marka bağlamını (pazar, dil, kitle) kaybetmez.

## Core concepts

Brand ≠ Digital Asset. Brand bağlamdır; Digital Asset yönetilen somut şeydir.

## MVP behavior

* Brand Customer'a bağlı oluşturulur
* Temel alanlar: ad, sektör, ana ülke, hedef pazarlar/ülkeler, diller, kısa açıklama, hedef kitle, hizmet/ürün özeti, rakipler (basit), logo (opsiyonel basit upload veya URL — ağır attachment framework zorunlu değil)
* Sorumlu ekip üyeleri
* Brand detail'den Digital Assets listesine geçiş

## Important data / attributes

name, customer_id, sector, primary country, target markets, languages, description, audience, offerings, competitors, responsible users, logo reference.

## Relationships

Customer → Brand → Digital Assets (+ responsible users).

## Main screens / workflows

Brand CRUD nested under Customer; Brand detail overview; link to assets.

## Rules / invariants

Brand tek Customer'a aittir. Cross-customer brand yok. SaaS workspace yok.

## Derived information

Asset count, open findings — related veriden derive.

## Later enhancements

Zengin brand kit, competitor intelligence, cross-channel scorecards.

## Explicit non-goals

Markayı Digital Asset ile karıştırmak; her kanalı ayrı Brand yapmak.

## Acceptance intent

Ekip bir Customer altında Brand tanımlayıp asset bağlama hazırlığına geçebilir.
