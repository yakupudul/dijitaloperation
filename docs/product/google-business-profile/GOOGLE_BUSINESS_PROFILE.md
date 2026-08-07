# Google Business Profile Digital Asset

## Purpose

Google Business Profile (GBP), Website sonrası sıradaki Digital Asset türüdür. Markanın yerel arama / Maps görünürlüğünü operasyonel olarak yönetmek için Brand altında tutulur.

## User value

Ajans ekibi, müşteri markasının GBP konumunu tek asset olarak görür; read-only API kanıtlarıyla eksik profil, tutarsız NAP, zayıf review sinyalleri ve görünürlük düşüşlerini Findings’e bağlar.

## Core concepts

* **Digital Asset type:** `google_business_profile` (veya eşdeğer typed asset kaydı)
* **GBP asset** = yönetilen yerel işletme profili / location (Maps / Business Profile)
* **Connection** = GBP asset hakkında veri okuyan Google Business Profile API (veya Business Information / Performance read scope’ları)
* Connection asset değildir; GA4/GSC’nin Website’e bağlanması gibi GBP API bağlantısı GBP asset’e bağlanır
* Pipeline: Connection read → Evidence → deterministic checks → Findings → AI explanation → Recommendation drafts → manual internal Tasks
* Harici write yok (MASTER_SPEC §5)

## MVP behavior

* Brand altında GBP Digital Asset kaydı oluşturulabilir
* Temel kimlik alanları: display name, place/location identifier, primary category, address (NAP), phone, website URL, timezone/locale where useful
* Optional non-secret config: linked Brand Website asset reference (aynı marka altında), service area vs storefront hint
* Enabled read-only GBP API Connection: encrypted OAuth/token credentials (ADR-027); connection test / account+location access probe
* İlk dikey slice (implementation): connection access probe → Run + normalized Evidence + connection health (`last_success_at` / `last_error`) — Website connector probe pattern
* Sonraki slice’lar: location profile Evidence, review aggregate Evidence, performance/insights Evidence (API’nin read-only sunduğu ölçüde)
* Deterministic Findings örnek adayları (katalog/rules geldikçe): incomplete profile, missing hours, website URL mismatch vs Brand Website, missing phone/address fields, low review rating threshold, sudden review-volume/rating change when period evidence exists
* AI Insights mevcut Evidence/Findings üzerinde yorumlayabilir; assignee/due date uydurmaz; harici write önermez
* Recommendation → Task dönüşümü manueldir

## Important data / attributes

Asset (non-secret): name, type=`google_business_profile`, brand_id, status, place/location id, primary category, address fields, phone, website_url, optional linked_website_asset_id.

Connection: type for GBP API (e.g. `google_business_profile_api`), enabled, non-secret account/location mapping in config, encrypted credentials, last_success_at, last_error.

Evidence types (normalized, no raw dump as Finding): e.g. `gbp_location_access`, `gbp_location_profile`, `gbp_reviews_aggregate`, `gbp_performance` — exact ids implementation’da blueprint’e sadık kalınarak seçilir.

## Relationships

Brand → GBP Digital Asset → GBP API Connection → Runs → Evidence; Asset → Findings → Recommendations → Tasks.  
Optional: GBP asset ↔ Brand Website asset (same Brand) for NAP/website consistency checks.  
Cross-channel analysis (roadmap 22) later; bu blueprint tek başına cross-asset orchestration kurmaz.

## Main screens / workflows

* Brand → Digital Assets: create GBP asset
* GBP asset detail: Overview, Connections, Runs, Findings, Recommendations, Tasks (Website tabs pattern)
* Attach/test GBP API connection (read-only)
* Start collect/probe Run; review Evidence/Findings; accept Recommendation → manual Task

## Rules / invariants

* No GBP write actions: no post/create/update/delete location, no review replies, no media upload, no Q&A writes, no attribute edits via DOP
* Prefer least-privilege read-only Google scopes
* Credentials never appear in Evidence, logs, or UI
* Do not invent metrics/reviews/categories absent from Evidence
* Deterministic layer before AI when checks are rule-expressible
* No separate Result entity (ADR-036); Findings remain persistent asset-level (ADR-034)
* No SaaS/tenant/customer portal; internal agency ops only

## Derived information

Profile completeness, NAP consistency vs Website asset, review health signals, visibility/performance deltas — derived from Evidence + rules, not from a fake KPI store.

## Later enhancements

* Richer local-SEO diagnosis catalog (GBP-specific)
* Multi-location brands (one asset per location)
* Cross-asset: Website ↔ GBP consistency packs (roadmap 22)
* AI packs for review-theme summarization grounded in Evidence excerpts only

## Explicit non-goals

* Editing or publishing to Google Business Profile
* Auto-replying to reviews
* Full Google Business UI clone / BI warehouse
* Treating GBP API as a Website connection (GBP is its own Digital Asset)
* Building Google Ads / Meta Ads / Instagram in this blueprint

## Acceptance intent

Ajans, Brand altında typed GBP Digital Asset + encrypted read-only GBP API Connection modelini product olarak tanımlı bulur; implementation bu blueprint’e göre probe → Evidence → Findings yolunu açar ve hiçbir harici write yapmaz.
