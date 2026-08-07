# Instagram Digital Asset

## Purpose

Instagram organic account, Brand altında yönetilen bir Digital Asset türüdür. Ajansın müşteri markasının Instagram varlığını read-only kanıtlarla operasyonel Findings’e bağlar.

## User value

Ekip, müşteri markasının Instagram hesabını tek asset olarak görür; profil, yayın ve etkileşim sinyallerini Evidence → Finding yoluna alır ve harici yazmadan (paylaşım/yorum/DM yok) öncelikli iç iş üretir.

## Core concepts

* **Digital Asset type:** `instagram`
* **Instagram asset** = yönetilen Instagram Business/Creator hesabı (organic social presence)
* **Connection** = Instagram Graph API (read-only) — asset hakkında veri okur; Connection asset değildir
* Pipeline: Connection read → Evidence → deterministic checks → Findings → AI explanation → Recommendation drafts → manual internal Tasks
* Harici write yok (MASTER_SPEC §5): paylaşım/publish, yorum/cevap, DM, story publish, profil düzenleme, takip/unfollow yasak
* Meta Ads’ten ayrıdır: Meta Ads reklam hesabıdır; Instagram bu blueprint’te organic social asset’tir

## MVP behavior

* Brand altında Instagram Digital Asset kaydı oluşturulabilir
* Temel kimlik alanları: display name, ig_user_id, username, account_type (business/creator), optional linked Meta/Facebook page id hint (non-secret)
* Optional non-secret config: linked Brand Website asset reference, linked Meta Ads asset reference (same Brand) for later cross-channel packs
* Enabled read-only Instagram Graph API Connection: encrypted access token credentials (ADR-027); connection test / account access probe
* İlk dikey slice (implementation): connection access probe → Run + normalized Evidence + connection health (`last_success_at` / `last_error`) — prior connector probe pattern
* Sonraki slice’lar: account/profile Evidence, recent media summary aggregates, engagement aggregates (API’nin read-only sunduğu ölçüde)
* Deterministic Findings örnek adayları (katalog/rules geldikçe): account access lost, missing/incomplete bio or profile picture signal when evidenced, posting cadence drop vs prior period, sudden engagement drop — yalnızca Evidence ile
* AI Insights mevcut Evidence/Findings üzerinde yorumlayabilir; assignee/due date uydurmaz; harici write / “publish post” otomasyonu önermez
* Recommendation → Task dönüşümü manueldir

## Important data / attributes

Asset (non-secret): name, type=`instagram`, brand_id, status, ig_user_id, username, account_type, optional linked_website_asset_id / linked_meta_ads_asset_id.

Connection: type for Instagram Graph API (e.g. `instagram_graph_api`), enabled, non-secret account mapping in config, encrypted credentials, last_success_at, last_error.

Evidence types (normalized, no raw dump as Finding): e.g. `instagram_account_access`, `instagram_account_profile`, `instagram_media_summary` — exact ids implementation’da blueprint’e sadık kalınarak seçilir.

## Relationships

Brand → Instagram Digital Asset → Instagram Graph API Connection → Runs → Evidence; Asset → Findings → Recommendations → Tasks.  
Optional: Instagram asset ↔ Brand Website / Meta Ads assets (same Brand) for later cross-channel packs (roadmap 22).  
Bu blueprint tek başına cross-asset orchestration kurmaz.

## Main screens / workflows

* Brand → Digital Assets: create Instagram asset
* Asset detail: Overview, Connections, Runs, Findings, Recommendations, Tasks (Website/GBP/Ads tabs pattern)
* Attach/test Instagram Graph API connection (read-only)
* Start collect/probe Run; review Evidence/Findings; accept Recommendation → manual Task

## Rules / invariants

* No Instagram write actions: no media publish, no comment replies, no DMs, no profile edits, no follow/unfollow via DOP
* Prefer least-privilege read-only Instagram/Meta permissions
* Access tokens never appear in Evidence, logs, or UI
* Do not invent posts/metrics/followers absent from Evidence
* Deterministic layer before AI when checks are rule-expressible
* No separate Result entity (ADR-036); Findings remain persistent asset-level (ADR-034)
* No SaaS/tenant/customer portal; internal agency ops only

## Derived information

Account health, posting cadence, engagement deltas — derived from Evidence + rules, not from a fake social dashboard clone.

## Later enhancements

* Richer Instagram diagnosis catalog
* Multi-account Brand UX
* Cross-asset: Website/Meta Ads ↔ Instagram consistency (roadmap 22)
* AI packs for content-theme summarization grounded in Evidence excerpts only

## Explicit non-goals

* Publishing or scheduling Instagram content
* Auto-replying to comments or DMs
* Full Instagram / Creator Studio / BI warehouse clone
* Treating Instagram Graph API as a Website connection or as Meta Ads (Instagram is its own Digital Asset; Meta Ads is roadmap step 20)
* Building YouTube / CRM modules in this blueprint

## Acceptance intent

Ajans, Brand altında typed Instagram Digital Asset + encrypted read-only Instagram Graph API Connection modelini product olarak tanımlı bulur; implementation bu blueprint’e göre probe → Evidence → Findings yolunu açar ve hiçbir harici write (paylaşım dahil) yapmaz.
