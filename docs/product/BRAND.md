# Brand

## Purpose

Brand, bir Customer altındaki markadır. Cross-channel dijital varlıkların ortak bağlamını sağlar.

## User value

Website, Ads, GBP gibi varlıklar aynı marka altında toplanır; ekip marka bağlamını (pazar, dil, kitle) kaybetmez.

## Core concepts

Brand ≠ Digital Asset. Brand bağlamdır; Digital Asset yönetilen somut şeydir.

## MVP behavior

* Brand Customer'a bağlı oluşturulur
* Operatörün minimum ticari bağlamı: ad, global katalogdan hizmetler, öncelikli hizmetler ve çoklu ülke/şehir/ilçe hizmet bölgeleri
* Sektör, dil, sorumlu ekip ve logo isteğe bağlı yönetim alanlarıdır
* Eski açıklama/hedef kitle/offerings/rakipler metinleri uyumluluk için saklanır; yeni Brand formunun ana akışı değildir
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

## Brand Intelligence Context

Structured factual business context lives in `BrandIntelligenceContext` (one-to-one), not as duplicated Website fields.

See `docs/product/BRAND_INTELLIGENCE.md`.

Legacy Brand text fields (`description`, `audience`, `offerings`, `competitors`, simple `target_markets`) remain for identity/backward compatibility. Intelligence Context is the structured source for future analysis.

Global Service Catalog, Brand-scoped Offering bağlantısı, Brand Service Area ve Search Query Library sınırları için `docs/product/SEARCH_DEMAND_INTELLIGENCE.md` belgesine bakın.

## Later enhancements

Competitor intelligence fetching, AI Recommendations using Brand Context + Evidence, cross-channel scorecards.

## Explicit non-goals

Markayı Digital Asset ile karıştırmak; her kanalı ayrı Brand yapmak.

## Acceptance intent

Ekip bir Customer altında Brand tanımlayıp asset bağlama hazırlığına geçebilir.
