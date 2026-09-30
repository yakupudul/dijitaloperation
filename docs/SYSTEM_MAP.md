# MoxDOP — sistem haritası

> Bu dosya `php artisan moxdop:system-map` ile koddan üretilir; elle düzenlemeyin. Ürün kararları: `docs/MASTER_SPEC.md`, ADR'ler: `docs/foundation/DECISION_LOG.md`, yetenek durumu: `PRODUCT_CAPABILITY_LEDGER.md`.

## Akış

1. **Bağlantılar** (Google, Meta, DataForSEO, WordPress Connector) hesapları keşfeder; her gün 05:10 otomatik keşif çalışır.
2. Hesaplar **dijital varlıklara** bağlanır; bağlı varlıklar için **merkezi toplama** (`collection` kuyruğu) veriyi çeker.
3. **Sorgular, kümeler ve kanal ekranları** (Site, Google Ads, Meta, İşletme Profili) veriden öneri üretir; AI çıktısı gerçek veriyle ve sektör uyum kapısıyla doğrulanır.
4. Öneriler **Bugün** ve marka / varlık ekranlarında görünür; operatör onaylar veya kapatır.
5. Dış sistemlere yalnız aşağıdaki **onaylı yazmalar** gider; geri kalan her şey okumadır.

## Menü

- **Menü**
  - Bugün (`operator.dashboard`)
  - Müşteriler (`operator.customers`)
  - Markalar (`operator.brands`)
  - Sorgular (`operator.library.queries`)
  - Entegrasyonlar (`operator.integrations`) — sekmeler: Keşfedilen varlıklar (`operator.integrations.discovered`), WordPress siteleri (`operator.integrations.wordpress-sites`), Veri merkezi (`operator.data-center`)
  - Ayarlar (`operator.settings`) — sekmeler: AI işlemleri ve promptlar (`operator.settings.ai-operations`), Standartlar (`operator.library.website-standards`), Sektör ve hizmet kataloğu (`operator.library.services`), Kullanıcılar (`operator.settings.users`), Sistem (`operator.settings.system-health`)

## Onaylı dış yazmalar

| Kanal | İşlem |
| --- | --- |
| google_ads, wordpress, gbp | review_reply, local_post, negative_list_add, draft_create, article_drafts, update_apply, site_fix, content_draft, content_apply, connector_update |

Google Ads kampanya / bütçe / durum değişikliği **yoktur**; öneriler Google Ads Editor dosyası olarak dışa aktarılır.

## Kuyruklar

- `default` — hızlı işler (bildirim, dış yazmalar, uptime).
- `heavy` — uzun AI / analiz işleri; yalnız redis kuyruğunda ayrılır.
- `collection` — merkezi veri toplama.

## Zamanlanmış işler

| Ne zaman | İş |
| --- | --- |
| `*/5 * * * * (UTC)` | `async:mark-stale-runs` |
| `*/5 * * * * (UTC)` | `horizon:snapshot` |
| `23 */2 * * * (UTC)` | `moxdop:ads:budget-watch` |
| `30 6 * * * (UTC)` | `moxdop:alerts:scan` |
| `40 7 * * 1 (Europe/Istanbul)` | `moxdop:analyst:weekly` |
| `30 3 * * * (UTC)` | `moxdop:backup` |
| `47 6 * * * (UTC)` | `moxdop:brand-candidates` |
| `35 3 * * * (UTC)` | `moxdop:collection:activity-refresh` |
| `* * * * * (UTC)` | `moxdop:collection:redispatch-stale` |
| `45 6 * * * (UTC)` | `moxdop:compliance:scan` |
| `10 5 * * * (UTC)` | `moxdop:data-pool-audit` |
| `20 3 * * * (UTC)` | `moxdop:db:ensure-partitions` |
| `*/5 * * * * (UTC)` | `moxdop:dispatch-due-automations` |
| `* * * * * (UTC)` | `moxdop:gbp:publish-scheduled` |
| `40 4 * * * (UTC)` | `moxdop:gbp:purge-expired` |
| `52 6 * * * (Europe/Istanbul)` | `moxdop:gbp:suggestions` |
| `40 5 * * * (UTC)` | `moxdop:google-ads:record-quality-scores` |
| `7 7 * * * (Europe/Istanbul)` | `moxdop:google-ads:suggestions` |
| `10 5 * * * (Europe/Istanbul)` | `moxdop:integrations:discover` |
| `*/5 * * * * (UTC)` | `moxdop:intel:collect` |
| `15 6 * * * (UTC)` | `moxdop:measurement:refresh` |
| `41 5 * * * (UTC)` | `moxdop:meta:geo-results` |
| `56 6 * * * (Europe/Istanbul)` | `moxdop:meta:suggestions` |
| `*/5 * * * * (UTC)` | `moxdop:ops:evaluate-alerts` |
| `13 7 * * * (Europe/Istanbul)` | `moxdop:outcomes:measure` |
| `* * * * * (UTC)` | `moxdop:resources:automate` |
| `10 5 * * * (UTC)` | `moxdop:resources:retry-stopped` |
| `10 4 2 * * (UTC)` | `moxdop:retention` |
| `52 5 * * 1 (UTC)` | `moxdop:site:weekly` |
| `13 5 3 * * (Europe/Istanbul)` | `moxdop:site` |
| `43 5 * * 1 (Europe/Istanbul)` | `moxdop:site` |
| `27 5 * * * (Europe/Istanbul)` | `moxdop:site` |
| `25 7 * * * (Europe/Istanbul)` | `moxdop:verify:data` |
| `20 6 * * * (Europe/Istanbul)` | `moxdop:verify:live` |
| `17 * * * * (UTC)` | `moxdop:website:sitemap-watch` |
| `20 6 * * * (UTC)` | `moxdop:wordpress:health` |
| `* * * * * (UTC)` | `moxdop:wordpress:reconcile` |
| `*/5 * * * * (UTC)` | queue-heartbeat-probe |
| `* * * * * (UTC)` | reminders-due |
| `*/5 * * * * (UTC)` | uptime-checks |
