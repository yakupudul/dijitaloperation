# Website Digital Asset

## Purpose

Website, ilk ve birincil Digital Asset türüdür.

## User value

Domain üzerinden teknik/on-page teşhis ve connection zenginleştirmesi tek asset'te toplanır.

## Core concepts

Website asset; WordPress/GA4/GSC/DataForSEO/PageSpeed connection'lardır.

## MVP behavior

* Brand altında Website kaydı: domain, primary URL, CMS (opsiyonel), languages, target countries, site type, optional hosting context
* Related connections and source-specific collection state
* Ekran vizyonu: Overview, Connections, Runs, Findings, Recommendations, Tasks
* İlk akış: Customer → Brand → Website → Diagnosis → Evidence → Findings → Recommendations → Task
* WordPress status derived from the authenticated read-only WordPress connection when present
* Public Discovery remains active for WordPress and non-WordPress sites; it verifies externally published HTTP/HTML
* Final public HTML is versioned per URL with current/previous hash and change state; unchanged bodies reuse the same private compressed artifact
* Integration coverage distinguishes discovered URLs from URLs whose final HTML was actually captured
* Website Intelligence Projection rebuilds Page/Search Term/Entity/Outcome read profiles from Website, WordPress, bound GSC and bound GA4 facts; provider tables remain canonical

## Important data / attributes

domain, primary_url, cms, languages, target_countries, site_type, hosting_context (optional).

## Relationships

Brand → Website asset → Connections; pipeline entities.

## Main screens / workflows

Website create/detail; attach connections; start diagnosis run.

Target operator workspace navigation:

1. Genel Bakış
2. Sayfalar & İçerik
3. Teknik Sağlık
4. Arama & AI Görünürlüğü
5. Performans & Dönüşüm
6. Altyapı & WordPress
7. İyileştirmeler
8. Veri Kaynakları

`Sayfalar & İçerik` is the first projection-backed operator slice. It presents Page profiles, source coverage,
public HTML capture/change state, WordPress object state, compact GSC/GA4 page context and a source comparison
detail. Stored visitor HTML can be opened only by authenticated operators and is returned as non-executable
plain text after ownership and checksum verification. This screen presents collected facts; interpretation remains
in Findings/Improvements.

`Teknik Sağlık` is the second projection-backed operator slice. It summarizes externally observed HTTP reachability,
redirects, deterministic crawl observations, document-head signals, structured data, TLS certificate facts and
PageSpeed lab LCP coverage. It has no opaque health score and does not create Findings or Recommendations. Missing
collection is shown as unavailable rather than zero; page-level details retain source-record provenance.

`Altyapı & WordPress` is the third projection-backed operator slice. Authenticated CMS site/runtime facts, safe
WordPress settings, Site Health observation counts, plugin/theme versions and update state, taxonomy summaries and
detected SEO/features are retained as a `wordpress` Entity source state. Website configuration and external TLS facts
remain separate `website` source state. Connection status is read from the asset-scoped connector without exposing
credentials. The screen is inventory and observation truth; it does not convert updates or Site Health counts into
Findings.

`Veri Kaynakları` is the fourth projection-backed operator slice. It separates connection/readiness state from actual
collected-data state for Public Website, WordPress Connector, PageSpeed, confirmed-binding GSC and confirmed-binding
GA4. Source watermark and available coverage counts are shown without duplicating Integration raw dataset tables.
The screen links to the canonical source-management and collection workspaces, while preserving the public HTML versus
WordPress inside-truth and platform-signal versus verified-outcome boundaries.

## Rules / invariants

CMS-specific fields Core'a şişirilmez; module/connection'dan gelir. Integration ekranı collection truth gösterir;
Finding/Recommendation/Task yalnızca Website Digital Asset analizinde üretilir.
WordPress `post_content` CMS iç gerçeğidir; `website_html_snapshot` ise dış HTTP yanıtındaki nihai HTML'dir ve
bu iki veri birbirinin yerine kullanılamaz.
Projection source state'leri de bu sınırı korur: aynı Page identity altında `wordpress` ve `website` ayrı kalır.
GSC query/page ve GA4 landing/page verileri yalnız confirmed Asset Binding üzerinden profile katılır. Missing değer sıfır değildir;
GA4 Key Event provider-attributed signal'dır ve verified business outcome sayılmaz.

## Intelligence Projection read model

* `website_page_profiles`: public HTTP/HTML, CMS içeriği, organic search ve davranış state'lerini Page identity üzerinde birleştirir
* `website_search_term_profiles`: GSC query kimliği ve query→page ilişkilerini kaynak semantiği korunarak taşır
* `website_entity_profiles`: operator-confirmed Brand/Website entity bağlamını kaynak state'leriyle taşır
* `website_outcome_profiles`: explicit Business Action mapping'lerine bağlı platform sinyallerini taşır
* `website_intelligence_projection_runs`: period, source coverage/watermark, partial failure ve rebuild provenance kaydıdır

Bu tablolar canonical provider fact store değildir; silinip kaynak fact'lardan yeniden üretilebilir. Varsayılan projection penceresi son
tamamlanmış 90 UTC gündür. Collection completion async rebuild tetikler; operator backfill komutu
`intelligence:website-projection:rebuild`'dir. `Sayfalar & İçerik`, `Teknik Sağlık`, `Altyapı & WordPress` ve `Veri Kaynakları`
bu read model'i kullanır. Kalan hedef sekmeler ve sekmeler arası nihai Genel Bakış ayrı UI fazlarıdır.

## Derived information

WordPress version/theme/plugin health from connector evidence; last diagnosis from runs.

## Later enhancements

Multi-environment sites, staging vs prod.

## Explicit non-goals

WordPress write; hosting control panel automation.

## Acceptance intent

Website asset kaydı diagnosis ve connection eklemeye hazır operasyon nesnesidir.
