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


## Multi-sector Brand form — 2026-09-08

Operator-authorized direct staging work on `chatgpt/search-demand-foundation`. Brand create/edit uses a searchable multi-sector selector and the union of active services in selected sectors. Service search, selected-only view, selected count, priority controls, inline service creation with explicit sector, reviewable out-of-scope selections, validation summary and a sticky save bar keep the form focused.

Selected sectors reference global ServiceCategory IDs through `brand_service_category`; current labels follow Library edits and deleted categories detach. The first selected code remains the legacy scalar `sector` for single-sector consumers. The additive migration attaches the existing sector and sectors of active linked services without changing offerings, priorities, goals or query identities. Existing scalar-only creation paths retain a read fallback.

Sector removal does not silently remove services. Save requires all selected services to be active and in scope; the operator restores a sector or explicitly removes its service. A newly entered service that resolves to an existing identity in another sector is rejected without relabeling the global identity. Brand, sector links, services, priorities and areas save transactionally. Edit mount reads form data directly instead of running portfolio findings/task calculations.

Source-reviewed only. No clone, dependency installation, tests, formatter, build, browser or server verification ran, per operator instruction. Deployment migration/runtime and human UI acceptance remain unverified; this is not a DONE claim. This is ordinary synchronous form CRUD with no provider work.
