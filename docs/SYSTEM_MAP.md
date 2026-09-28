# MoxDOP — sistem haritası

> Bu dosya `php artisan moxdop:system-map` ile koddan üretilir; elle düzenlemeyin. Ürün kararları: `docs/MASTER_SPEC.md`, ADR'ler: `docs/foundation/DECISION_LOG.md`, yetenek durumu: `PRODUCT_CAPABILITY_LEDGER.md`.

## Akış

1. **Bağlantılar** (Google, Meta, DataForSEO, WordPress Connector, WhatsApp) hesapları keşfeder; her gün 05:10 otomatik keşif çalışır.
2. Hesaplar **dijital varlıklara** bağlanır; bağlı varlıklar için **merkezi toplama** (`collection` kuyruğu) veriyi çeker.
3. **Danışman, SEO görevleri, Hizmet Beyni, uyum denetimi, uyarılar** veriden iş üretir.
4. Her iş **Komuta merkezine** düşer; operatör orada yapar / erteler / kapatır.
5. Dış sistemlere yalnız aşağıdaki **onaylı yazmalar** gider; geri kalan her şey okumadır.
6. Sonuçlar **aylık rapora**, **Ajans karnesine** ve **ajans işletmesine** (kârlılık, tahsilat) yansır.

## Menü

- **Menü**
  - Bugün (`operator.dashboard`)
  - Komuta merkezi (`operator.command-center`) — sekmeler: İş listesi (`operator.tasks`), Uyarılar (`operator.alerts`), Yenilemeler (`operator.renewals`)
- **Portföy**
  - Portföy sağlığı (`operator.portfolio.health`)
  - Müşteriler (`operator.customers`)
  - Markalar (`operator.brands`)
  - Dijital Varlıklar (`operator.assets`)
- **İşler**
  - Danışman (`operator.ads_advisor`) — sekmeler: Ayrıntılı ekran (`operator.ads_advisor.detailed`)
  - SEO Görevleri (`operator.seo_tasks`) — sekmeler: Ayrıntılı ekran (`operator.seo_tasks.detailed`)
  - İçerik takvimi (`operator.content.calendar`)
- **Pazar**
  - Sorgular (`operator.library.search-queries`) — sekmeler: Hizmetler (`operator.library.services`)
  - Rakipler (`operator.library.search-demand-competitors`) — sekmeler: Harita sıralaması (`operator.market.map-rankings`), Rakip izleme (`operator.market.competitor-watch`), Backlink fırsatları (`operator.market.backlinks`), AI görünürlüğü (`operator.market.ai-visibility`)
  - Hizmet Beyni (`operator.brain.services`) — sekmeler: Beyin önerileri (`operator.brain.recommendations`), Yöntemler (`operator.brain.methods`), Onay kuyruğu (`operator.brain.proposals`)
- **Satış**
  - Lead kutusu (`operator.leads`)
  - Potansiyel Müşteriler (`operator.prospects`) — sekmeler: Niyet Radarı (`operator.intent-radar`)
- **Raporlar**
  - Aylık rapor (`operator.reports.monthly`) — sekmeler: Rapor kuyruğu (`operator.reports.queue`), Ajans karnesi (`operator.reports.scorecard`), Grafik notları (`operator.reports.annotations`), Üretim Arşivi (`operator.archive`)
  - Ajans işletmesi (`operator.agency`)
- **Sistem**
  - Entegrasyonlar (`operator.integrations`) — sekmeler: WordPress siteleri (`operator.integrations.wordpress-sites`), Kopya web siteleri (`operator.integrations.website-duplicates`), Veri merkezi (`operator.data-center`)
  - Ayarlar (`operator.settings`) — sekmeler: Uyum (`operator.compliance`), Aktivite (`operator.activity`)

## Komuta merkezi kaynakları

- `alert` — Uyarı
- `advisor` — Danışman
- `seo` — SEO
- `site_fix` — Site düzeltmesi
- `brain` — Beyin önerisi
- `compliance` — Uyum
- `lead` — Lead
- `system` — Sistem
- `approval` — Onay bekliyor
- `coverage` — Kurulum eksiği
- `calendar` — İçerik takvimi
- `client_approval` — Müşteri onayı
- `followup` — Takip
- `invoice` — Tahsilat
- `commitment` — Taahhüt
- `task` — Görev
- `live` — Canlı doğrulama
- `data` — Veri şüpheli
- `lead_outcome` — Lead sonucu
- `gbp` — İşletme Profili
- `CoverageSource` (ek kaynak)
- `CalendarSource` (ek kaynak)
- `AgencySource` (ek kaynak)
- `TaskSource` (ek kaynak)
- `VerificationSource` (ek kaynak)
- `LeadOutcomeSource` (ek kaynak)
- `GbpSource` (ek kaynak)

## Onaylı dış yazmalar

| Kanal | İşlem |
| --- | --- |
| google_ads, wordpress, gbp | review_reply, local_post, negative_list_add, draft_create, article_drafts, update_apply, site_fix, content_draft, content_apply, connector_update |

Google Ads kampanya / bütçe / durum değişikliği **yoktur**; öneriler Google Ads Editor dosyası olarak dışa aktarılır.

## Kuyruklar

- `default` — hızlı işler (WhatsApp, bildirim, dış yazmalar, uptime).
- `heavy` — uzun AI / analiz işleri (danışman, SEO planı, Beyin, SERP, silme); yalnız redis kuyruğunda ayrılır.
- `collection` — merkezi veri toplama.

## Zamanlanmış işler

| Ne zaman | İş |
| --- | --- |
| `*/5 * * * * (UTC)` | `async:mark-stale-runs` |
| `*/5 * * * * (UTC)` | `horizon:snapshot` |
| `23 */2 * * * (UTC)` | `moxdop:ads:budget-watch` |
| `0 8 * * 1 (UTC)` | `moxdop:advisor:digest` |
| `30 7 * * 1 (UTC)` | `moxdop:advisor:measure` |
| `0 7 * * 1 (UTC)` | `moxdop:advisor:plan` |
| `30 6 * * * (UTC)` | `moxdop:alerts:scan` |
| `30 3 * * * (UTC)` | `moxdop:backup` |
| `20 6 * * 1 (UTC)` | `moxdop:brain:refresh` |
| `35 3 * * * (UTC)` | `moxdop:collection:activity-refresh` |
| `* * * * * (UTC)` | `moxdop:collection:redispatch-stale` |
| `45 6 * * * (UTC)` | `moxdop:compliance:scan` |
| `*/10 * * * * (UTC)` | `moxdop:content:publish-due` |
| `10 7 * * * (UTC)` | `moxdop:customers:health` |
| `10 5 * * * (UTC)` | `moxdop:data-pool-audit` |
| `10 4 * * * (UTC)` | `moxdop:data:retention` |
| `30 5 * * 1 (UTC)` | `moxdop:demand:build` |
| `0 6 * * 1 (UTC)` | `moxdop:demand:compare` |
| `45 5 * * 1 (UTC)` | `moxdop:demand:serp` |
| `*/5 * * * * (UTC)` | `moxdop:dispatch-due-automations` |
| `40 4 * * * (UTC)` | `moxdop:gbp:purge-expired` |
| `40 5 * * * (UTC)` | `moxdop:google-ads:record-quality-scores` |
| `10 5 * * * (Europe/Istanbul)` | `moxdop:integrations:discover` |
| `50 4 * * * (UTC)` | `moxdop:intel:backlinks` |
| `*/5 * * * * (UTC)` | `moxdop:intel:collect` |
| `25 5 * * 1 (UTC)` | `moxdop:intel:competitors` |
| `30 4 * * * (UTC)` | `moxdop:intel:grid` |
| `5 5 * * * (UTC)` | `moxdop:intel:reviews` |
| `*/5 * * * * (UTC)` | `moxdop:intent-radar:tick` |
| `30 7 1 * * (Europe/Istanbul)` | `moxdop:invoices:draft-monthly` |
| `15 6 * * * (UTC)` | `moxdop:measurement:refresh` |
| `41 5 * * * (UTC)` | `moxdop:meta:geo-results` |
| `*/5 * * * * (UTC)` | `moxdop:ops:evaluate-alerts` |
| `5 6 * * * (UTC)` | `moxdop:renewals:daily` |
| `0 7 1 * * (Europe/Istanbul)` | `moxdop:reports:prepare-monthly` |
| `* * * * * (UTC)` | `moxdop:resources:automate` |
| `10 5 * * * (UTC)` | `moxdop:resources:retry-stopped` |
| `40 9 * * * (UTC)` | `moxdop:seo:inspect-changed` |
| `30 6 * * 1 (UTC)` | `moxdop:seo:plan` |
| `55 5 * * 1 (UTC)` | `moxdop:topics:build` |
| `25 7 * * * (Europe/Istanbul)` | `moxdop:verify:data` |
| `20 6 * * * (Europe/Istanbul)` | `moxdop:verify:live` |
| `17 * * * * (UTC)` | `moxdop:website:sitemap-watch` |
| `10 7 * * 1 (UTC)` | `moxdop:website:url-verdicts` |
| `* * * * * (UTC)` | `moxdop:whatsapp:dispatch` |
| `50 3 * * * (UTC)` | `moxdop:whatsapp:retention` |
| `20 6 * * * (UTC)` | `moxdop:wordpress:health` |
| `* * * * * (UTC)` | `moxdop:wordpress:reconcile` |
| `*/5 * * * * (UTC)` | queue-heartbeat-probe |
| `* * * * * (UTC)` | reminders-due |
| `*/5 * * * * (UTC)` | uptime-checks |
| `0 * * * * (UTC)` | whatsapp-contact-link |
