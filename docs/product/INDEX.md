# DOP Product Memory

Bu klasör DOP'un **normatif product blueprint** katmanıdır.

Kod dokümantasyonu değildir. Cursor / Architect / Reviewer / Implementer dahil bütün geliştirme agent'ları, ürünün **ne yapması gerektiğini** buradan okur.

## Üç bilgi katmanı

1. **MASTER_SPEC** (`docs/MASTER_SPEC.md`)  
   Değişmez üst ürün kuralları. Çelişkide her zaman kazanır.

2. **PRODUCT BLUEPRINTS** (`docs/product/**`)  
   Domain ve modüllerin ayrıntılı ürün davranışı.

3. **IMPLEMENTATION ROADMAP** (`docs/IMPLEMENTATION_ROADMAP.md`)  
   Hangi sırada geliştirileceği.

Accepted ADR'ler (`docs/foundation/DECISION_LOG.md`) MASTER_SPEC ile birlikte kaynak hiyerarşisinde ürün/mimari kararları kilitler.

## Agent kuralları

* Architect product feature üretmeden önce ilgili blueprint'i okumak zorundadır.
* Reviewer aynı blueprint'e göre review yapmak zorundadır.
* Implementer görev JSON'undaki `product_spec_paths` altındaki bütün dosyaları okumak zorundadır.
* Agent, blueprint'te bulunmayan önemli ürün davranışını keyfi olarak uydurmamalıdır.
* Routine teknik implementation kararları için kullanıcı beklenmez (framework-native seçimler serbesttir; ürün davranışını değiştirmez).

## Kaynak önceliği

1. `docs/MASTER_SPEC.md`
2. Accepted ve daha yeni ADR'ler
3. `docs/product/*` product blueprint'leri
4. `docs/IMPLEMENTATION_ROADMAP.md`
5. foundation / module-sdk referans belgeleri

Blueprint, MASTER_SPEC'i **override edemez**; yalnızca ayrıntılandırır.

## Korunan son kararlar (geri alınmaz)

* Ayrı Result entity yok (ADR-036)
* Finding kalıcı Digital Asset-level lifecycle (ADR-034)
* MVP Core sade (ADR-037) — Attachments/Tags/ağır Notification/Audit/Health zorunlu değil
* Minimal Module Registry (ADR-035)
* Custom compatibility/migrator/lifecycle FSM MVP dışı
* Framework'ü yeniden yazma (ADR-033)
* SaaS / Workspace / müşteri portalı / harici write yok
* AI ham veriye kontrolsüz bırakılmaz

## Roadmap → product spec mapping

| Roadmap alanı | Product blueprint |
|---------------|-------------------|
| Customer | `docs/product/CUSTOMER.md` |
| Brand | `docs/product/BRAND.md` |
| Brand Intelligence Context | `docs/product/BRAND_INTELLIGENCE.md` |
| Digital Asset | `docs/product/DIGITAL_ASSET.md` |
| Connection + credentials | `docs/product/CONNECTION.md` + `docs/product/DIGITAL_ASSET.md` (+ ADR-027, ADR-039) |
| Agency Integrations | `docs/product/CONNECTION.md` (+ ADR-039) |
| Google Integration setup | `docs/product/GOOGLE_INTEGRATION_SETUP.md` |
| Minimal Module Registry | `docs/product/MODULE_PLATFORM.md` |
| Dashboard / ops UI | `docs/product/DASHBOARD.md` |
| Run / Evidence / Finding / Recommendation / Task | `docs/product/ANALYSIS_PIPELINE.md` |
| Website module | `docs/product/website/WEBSITE.md` |
| Website Diagnosis | `docs/product/website/DIAGNOSIS.md` |
| WordPress | `docs/product/website/WORDPRESS.md` |
| Search Console | `docs/product/website/SEARCH_CONSOLE.md` |
| GA4 | `docs/product/website/GA4.md` |
| PageSpeed / Lighthouse | `docs/product/website/PAGESPEED_LIGHTHOUSE.md` |
| DataForSEO Integration (agency) | `docs/product/integrations/DATAFORSEO.md` |
| DataForSEO (Website usage) | `docs/product/website/DATAFORSEO.md` |
| AI Insights | `docs/product/website/AI_INSIGHTS.md` |
| Google Business Profile | `docs/product/google-business-profile/GOOGLE_BUSINESS_PROFILE.md` |
| Google Ads | `docs/product/google-ads/GOOGLE_ADS.md` |
| Meta Ads | `docs/product/meta-ads/META_ADS.md` |
| Instagram | `docs/product/instagram/INSTAGRAM.md` |
| Cross-asset / cross-channel analysis | `docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md` |
| Later assets (YouTube, CRM, …) | `docs/product/future/DIGITAL_ASSETS.md` |

## Blueprint şablonu

Her blueprint genelde şunları içerir: Purpose, User value, Core concepts, MVP behavior, Important data/attributes, Relationships, Main screens/workflows, Rules/invariants, Derived information, Later enhancements, Explicit non-goals, Acceptance intent.
