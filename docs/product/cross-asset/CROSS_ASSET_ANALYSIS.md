# Cross-Asset / Cross-Channel Analysis

## Purpose

Brand altında birden fazla Digital Asset’in halihazırda normalize edilmiş Evidence (ve gerekirse Findings) kayıtlarını birlikte okuyarak kanallar arası tutarsızlık ve fırsatları belirlemek.

## User value

Ajans ekibi “Website NAP ile GBP uyuşuyor mu?”, “Ads landing ile site tutarlı mı?”, “Meta Ads / Instagram / Website sinyalleri çelişiyor mu?” sorularını tek asset silosunda değil Brand bağlamında, kanıta dayalı cevaplar.

## Core concepts

* **Brand** = cross-channel bağlam (`docs/product/BRAND.md`); Brand bir Digital Asset değildir
* **Cross-asset pack** = aynı Brand altındaki 2+ typed Digital Asset’in Evidence’ini deterministic kurallarla karşılaştıran analiz birimi
* Pack yeni bir Digital Asset türü veya Connection değildir; orchestration + rule katmanıdır
* Pipeline değişmez: source Evidence → deterministic/rule checks → persistent Findings → AI explanation → Recommendations → manual internal Tasks (`docs/product/ANALYSIS_PIPELINE.md`)
* Finding’ler Digital Asset-level kalır (ADR-034); ayrı Result entity yok (ADR-036)
* Progressive: gerekli asset veya Evidence yoksa pack sahte skor üretmez; dürüst skip/empty

## MVP behavior

* Yalnızca **aynı Brand** altındaki Digital Asset’ler karşılaştırılır (cross-Customer yasak)
* Asset blueprint’lerinde zaten adlandırılmış pack adayları (implementation sırası küçük dilimler halinde):
  * Website ↔ Google Business Profile: NAP / website URL consistency
  * Website ↔ Google Ads: landing-page consistency
  * Website / Instagram ↔ Meta Ads consistency
  * Website / Meta Ads ↔ Instagram consistency
* Optional non-secret `linked_*_asset_id` config (asset blueprint’lerinde tanımlı) varsa pack pairing için tercih edilir; yoksa aynı Brand içinde type’a göre **tek anlamlı** eşleşme varsa kullanılabilir; belirsizse pack atlanır
* Run asset/connection kapsamında kalır (`ANALYSIS_PIPELINE`): cross-asset Run primary (subject) Digital Asset üzerinde açılır; sibling asset Evidence’i Brand kapsamında **read-only** okunur
* Finding primary/subject Digital Asset’e yazılır; summary/payload’da related Brand sibling asset kimlikleri ve dayanak Evidence referansları yer alır — Brand-level Finding tablosu / Result store yok
* Fingerprint, pack id + primary asset + related asset kimliklerini içerir (duplicate yok; ADR-034 upsert)
* İlk implementation slice’ları bu blueprint’ten sonra gelir: mevcut Evidence türleri yeterli olan en küçük deterministic pack
* AI yalnızca cite edilen Evidence/Finding üzerinde yorumlar; kanıtsız kanal skoru veya harici write önermez
* Recommendation → Task dönüşümü manueldir (ADR-025)

## Important data / attributes

Pack contract (product-level; exact code ids implementation’da sadık kalınarak seçilir):

* `pack_id` (stable kebab id)
* Participating Digital Asset types
* Required Evidence types (normalized fields only)
* Primary/subject Digital Asset selection rule
* Finding category/severity/confidence intent
* Fingerprint recipe including pack + asset identities
* Skip reasons when Evidence incomplete

Run: `module_id` for cross-asset analysis (ör. `cross-asset-analysis`), status/timings; Evidence optionally records comparison snapshot types when useful (normalized, no raw dumps).

Finding: ADR-034 alanları; primary `digital_asset_id`; related sibling asset ids in summary/payload; `last_run_id`.

## Relationships

Customer → Brand → Digital Assets (Website, GBP, Google Ads, Meta Ads, Instagram, …).  
Cross-asset pack → reads Evidence across same-Brand assets → Finding on primary asset → Recommendation → Task.  
Ops dashboard (roadmap 23 / `docs/product/DASHBOARD.md`) open cross-asset Findings’i aksiyon kartı olarak gösterebilir; bu blueprint dashboard UI kurmaz.

## Main screens / workflows

* Brand / asset operational context: “Cross-channel” or pack Runs appear under participating assets’ Runs/Findings tabs (framework-native Filament; yeni portal yok)
* Operator starts or schedules a Brand-relevant pack when required assets exist
* Review Evidence/Findings; accept Recommendation → manual Task
* Missing sibling asset/Evidence → honest empty/skip messaging (no fake KPI)

## Rules / invariants

* Harici write yok (MASTER_SPEC §5)
* Cross-Customer / cross-Brand analysis yok
* Evidence’te olmayan NAP, URL, spend, engagement veya “kanal skoru” uydurulmaz
* Deterministic layer before AI when rule-expressible (ADR-023)
* Findings remain persistent asset-level (ADR-034); no Result entity (ADR-036)
* Credentials never appear in Evidence, logs, or UI
* Prefer framework-native Laravel/Filament solutions (ADR-033); no custom orchestration framework for MVP packs
* No SaaS/tenant/customer portal

## Derived information

Consistency / mismatch signals are derived from normalized Evidence comparisons — not from a denormalized cross-channel KPI warehouse.

## Later enhancements

* Richer pack catalog as asset Evidence types mature
* Brand overview surfacing open cross-asset Findings (with dashboard stage)
* Optional AI packs that summarize multi-asset Evidence excerpts only
* Additional platforms (YouTube, CRM) only after their blueprints + first modules

## Explicit non-goals

* Looker-style BI / marketing demo scorecards
* Autonomous multi-channel remediation or external write CTAs
* Brand’i Digital Asset yapmak veya Brand-level Finding/Result store eklemek
* Tek asset diagnosis / connector işlerini bu blueprint altında yeniden yazmak
* Cross-customer competitive intelligence engine
* Dashboard production hardening (roadmap 23)

## Acceptance intent

Ajans, Brand altında typed Digital Asset’ler arasında kanıta dayalı, progressive, read-only cross-channel consistency pack’lerinin product sözleşmesini tanımlı bulur; implementation bu blueprint’e göre küçük deterministic pack dilimleri açar, Finding’leri primary asset üzerinde tutar ve hiçbir harici write veya sahte kanal skoru üretmez.
