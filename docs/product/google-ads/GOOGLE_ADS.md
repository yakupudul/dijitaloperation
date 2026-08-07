# Google Ads Digital Asset

## Purpose

Google Ads account, Brand altında yönetilen bir Digital Asset türüdür. Ajansın reklam hesabı görünürlüğünü read-only kanıtlarla operasyonel Findings’e bağlar.

## User value

Ekip, müşteri markasının Google Ads hesabını tek asset olarak görür; kampanya/harcama/performans sinyallerini Evidence → Finding yoluna alır ve harici yazmadan öncelikli iç iş üretir.

## Core concepts

* **Digital Asset type:** `google_ads`
* **Google Ads asset** = yönetilen Google Ads customer/account (MCC child veya standalone)
* **Connection** = Google Ads API (read-only) — asset hakkında veri okur; Connection asset değildir
* Pipeline: Connection read → Evidence → deterministic checks → Findings → AI explanation → Recommendation drafts → manual internal Tasks
* Harici write yok (MASTER_SPEC §5): kampanya oluşturma/durdurma/düzenleme, bütçe değişimi, teklif yazma yasak

## MVP behavior

* Brand altında Google Ads Digital Asset kaydı oluşturulabilir
* Temel kimlik alanları: display name, customer/account id, currency, timezone (non-secret)
* Optional non-secret config: linked Brand Website asset reference, MCC parent id hint
* Enabled read-only Google Ads API Connection: encrypted OAuth/token (and developer-token where required) credentials (ADR-027); connection test / accessible-customer or customer access probe
* İlk dikey slice (implementation): connection access probe → Run + normalized Evidence + connection health (`last_success_at` / `last_error`) — prior connector probe pattern
* Sonraki slice’lar: account summary Evidence, campaign performance aggregates, disapproved/limited assets signals (API’nin read-only sunduğu ölçüde)
* Deterministic Findings örnek adayları (katalog/rules geldikçe): account access lost, spend spike/drop vs prior period, zero-impression campaigns with budget, disapproved ads count threshold — yalnızca Evidence ile
* AI Insights mevcut Evidence/Findings üzerinde yorumlayabilir; assignee/due date uydurmaz; harici write / “pause campaign” otomasyonu önermez
* Recommendation → Task dönüşümü manueldir

## Important data / attributes

Asset (non-secret): name, type=`google_ads`, brand_id, status, customer_id / resource name, currency_code, timezone, optional linked_website_asset_id.

Connection: type for Google Ads API (e.g. `google_ads_api`), enabled, non-secret login-customer/MCC mapping in config, encrypted credentials, last_success_at, last_error.

Evidence types (normalized, no raw dump as Finding): e.g. `google_ads_account_access`, `google_ads_landing_final_urls`, `google_ads_account_summary`, `google_ads_campaign_performance` — exact ids implementation’da blueprint’e sadık kalınarak seçilir.

## Relationships

Brand → Google Ads Digital Asset → Google Ads API Connection → Runs → Evidence; Asset → Findings → Recommendations → Tasks.  
Optional: Google Ads asset ↔ Brand Website asset (same Brand) for landing-page consistency later.  
Cross-channel analysis (roadmap 22) later; bu blueprint tek başına cross-asset orchestration kurmaz.

## Main screens / workflows

* Brand → Digital Assets: create Google Ads asset
* Asset detail: Overview, Connections, Runs, Findings, Recommendations, Tasks (Website/GBP tabs pattern)
* Attach/test Google Ads API connection (read-only)
* Start collect/probe Run; review Evidence/Findings; accept Recommendation → manual Task

## Rules / invariants

* No Google Ads write actions: no campaign/ad group/ad/budget/bid/keyword mutations, no conversions upload, no account changes via DOP
* Prefer least-privilege read-only Google Ads scopes / access
* Credentials and developer tokens never appear in Evidence, logs, or UI
* Do not invent spend/metrics/campaigns absent from Evidence
* Deterministic layer before AI when checks are rule-expressible
* No separate Result entity (ADR-036); Findings remain persistent asset-level (ADR-034)
* No SaaS/tenant/customer portal; internal agency ops only

## Derived information

Account health, spend/performance deltas, disapproval risk — derived from Evidence + rules, not from a fake KPI store or full Ads UI clone.

## Later enhancements

* Richer Ads diagnosis catalog
* Multi-account brands / MCC tree UX
* Cross-asset: Website ↔ Ads landing consistency (roadmap 22)
* AI packs for creative/theme summarization grounded in Evidence excerpts only

## Explicit non-goals

* Mutating Google Ads entities
* Autonomous bid/budget optimization
* Full Google Ads UI / Looker-style BI warehouse
* Treating Google Ads API as a Website connection (Ads is its own Digital Asset)
* Building Meta Ads / Instagram in this blueprint

## Acceptance intent

Ajans, Brand altında typed Google Ads Digital Asset + encrypted read-only Google Ads API Connection modelini product olarak tanımlı bulur; implementation bu blueprint’e göre probe → Evidence → Findings yolunu açar ve hiçbir harici write yapmaz.
