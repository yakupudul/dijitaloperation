# Meta Ads Digital Asset

## Purpose

Meta Ads account (Facebook/Meta advertising), Brand altında yönetilen bir Digital Asset türüdür. Ajansın Meta reklam hesabını read-only kanıtlarla operasyonel Findings’e bağlar.

## User value

Ekip, müşteri markasının Meta Ads hesabını tek asset olarak görür; kampanya/harcama/performans ve delivery sinyallerini Evidence → Finding yoluna alır ve harici yazmadan öncelikli iç iş üretir.

## Core concepts

* **Digital Asset type:** `meta_ads`
* **Meta Ads asset** = yönetilen Meta ad account
* **Connection** = Meta Marketing API (read-only) — asset hakkında veri okur; Connection asset değildir
* Pipeline: Connection read → Evidence → deterministic checks → Findings → AI explanation → Recommendation drafts → manual internal Tasks
* Harici write yok (MASTER_SPEC §5): kampanya oluşturma/durdurma/düzenleme, bütçe/teklif yazma, creative publish yasak

## MVP behavior

* Brand altında Meta Ads Digital Asset kaydı oluşturulabilir
* Temel kimlik alanları: display name, ad_account_id (`act_…`), currency, timezone (non-secret)
* Optional non-secret config: linked Brand Website asset reference, Business Manager id hint
* Enabled read-only Meta Marketing API Connection: encrypted access token credentials (ADR-027); connection test / ad-account access probe
* İlk dikey slice (implementation): connection access probe → Run + normalized Evidence + connection health (`last_success_at` / `last_error`) — prior connector probe pattern
* Sonraki slice’lar: account summary Evidence, campaign/adset performance aggregates, delivery/disapproval signals (API’nin read-only sunduğu ölçüde)
* Deterministic Findings örnek adayları (katalog/rules geldikçe): account access lost, spend spike/drop vs prior period, ads in disapproved/with_issues, learning-limited delivery — yalnızca Evidence ile
* AI Insights mevcut Evidence/Findings üzerinde yorumlayabilir; assignee/due date uydurmaz; harici write / “pause campaign” otomasyonu önermez
* Recommendation → Task dönüşümü manueldir

## Important data / attributes

Asset (non-secret): name, type=`meta_ads`, brand_id, status, ad_account_id, currency_code, timezone, optional linked_website_asset_id.

Connection: type for Meta Marketing API (e.g. `meta_ads_api`), enabled, non-secret account mapping in config, encrypted credentials, last_success_at, last_error.

Evidence types (normalized, no raw dump as Finding): e.g. `meta_ads_account_access`, `meta_ads_account_summary`, `meta_ads_campaign_performance` — exact ids implementation’da blueprint’e sadık kalınarak seçilir.

## Relationships

Brand → Meta Ads Digital Asset → Meta Ads API Connection → Runs → Evidence; Asset → Findings → Recommendations → Tasks.  
Optional: Meta Ads asset ↔ Brand Website / Instagram assets (same Brand) for later cross-channel packs (roadmap 22).  
Bu blueprint tek başına cross-asset orchestration kurmaz.

## Main screens / workflows

* Brand → Digital Assets: create Meta Ads asset
* Asset detail: Overview, Connections, Runs, Findings, Recommendations, Tasks (Website/GBP/Ads tabs pattern)
* Attach/test Meta Ads API connection (read-only)
* Start collect/probe Run; review Evidence/Findings; accept Recommendation → manual Task

## Rules / invariants

* No Meta Ads write actions: no campaign/ad set/ad/budget/bid mutations, no creative publish, no audience writes via DOP
* Prefer least-privilege read-only Meta permissions
* Access tokens never appear in Evidence, logs, or UI
* Do not invent spend/metrics/campaigns absent from Evidence
* Deterministic layer before AI when checks are rule-expressible
* No separate Result entity (ADR-036); Findings remain persistent asset-level (ADR-034)
* No SaaS/tenant/customer portal; internal agency ops only

## Derived information

Account health, spend/performance deltas, delivery/disapproval risk — derived from Evidence + rules, not from a fake KPI store or Ads Manager clone.

## Later enhancements

* Richer Meta Ads diagnosis catalog
* Multi-account Business Manager UX
* Cross-asset: Website/Instagram ↔ Meta Ads consistency (roadmap 22)
* AI packs for creative/theme summarization grounded in Evidence excerpts only

## Explicit non-goals

* Mutating Meta Ads entities or publishing creatives
* Autonomous bid/budget optimization
* Full Ads Manager / BI warehouse
* Treating Meta Marketing API as a Website connection (Meta Ads is its own Digital Asset)
* Building Instagram organic module in this blueprint (separate roadmap step 21)

## Acceptance intent

Ajans, Brand altında typed Meta Ads Digital Asset + encrypted read-only Meta Marketing API Connection modelini product olarak tanımlı bulur; implementation bu blueprint’e göre probe → Evidence → Findings yolunu açar ve hiçbir harici write yapmaz.
