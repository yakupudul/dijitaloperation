# MoxDOP — sistem haritası

> Bu dosya `php artisan moxdop:system-map` ile koddan üretilir; elle düzenlemeyin. Ürün kararları: `docs/MASTER_SPEC.md`, ADR'ler: `docs/foundation/DECISION_LOG.md`, yetenek durumu: `PRODUCT_CAPABILITY_LEDGER.md`.

## Akış

1. **Bağlantılar** (Google, Meta, DataForSEO, WordPress Connector) hesapları keşfeder; her gün 05:10 otomatik keşif çalışır.
2. Hesaplar **dijital varlıklara** bağlanır; bağlı varlıklar için **merkezi toplama** (`collection` kuyruğu) veriyi çeker.
3. **Sorgular, kümeler ve kanal ekranları** (Site, Google Ads, Meta, İşletme Profili) veriden öneri üretir; AI çıktısı gerçek veriyle ve sektör uyum kapısıyla doğrulanır.
4. Öneriler **Bugün** ve marka / varlık ekranlarında görünür; operatör onaylar veya kapatır.
5. Dış sistemlere yalnız aşağıdaki **onaylı yazmalar** gider; geri kalan her şey okumadır.

## Menü

- ****
  - Bugün (`operator.dashboard`) — sekmeler: Onay kutusu (`operator.komuta`), Takvim (`operator.calendar`)
  - Markalar (`operator.brands`) — sekmeler: Portföy (`operator.portfolio`), Web siteleri (`operator.websites`), Onarım masası (`operator.repair`), İçerik (`operator.content`), Bağlantılar (`operator.links`), İşletme profilleri (`operator.gbp-desk`), Google Ads (`operator.google-ads-desk`), Meta reklamları (`operator.meta-desk`)
  - Moximu (`operator.sales`) — sekmeler: Pazarlama (`operator.marketing`), Sosyal medya (`operator.social`)
- **Sistem**
  - Sistem (`operator.integrations`) — sekmeler: Hesaplar (`operator.integrations.accounts`), Bağlantı sağlığı (`operator.integrations.connection-health`), Dijital varlıklar (`operator.assets`), Genel ayarlar (`operator.settings`), Kullanıcılar (`operator.settings.users`), Standartlar (`operator.library.website-standards`), Sektör ve hizmet kataloğu (`operator.library.services`), Sorgu kuralları (`operator.settings.query-rules`), Kitaplar (`operator.settings.books`), AI sağlayıcıları (`operator.settings.ai-workers`), AI işlemleri ve promptlar (`operator.settings.ai-operations`), MCP anahtarları (`operator.settings.mcp`), Güvenlik karnesi (`operator.settings.security`), Sistem (`operator.settings.system-health`), Zamanlanmış işler (`operator.settings.jobs`), Değişiklik kaydı (`operator.settings.changes`), Geliştirme havuzu (`operator.settings.improvements`), Sürümler (`operator.settings.releases`)

## Onaylı dış yazmalar

| Kanal | İşlem |
| --- | --- |
| google_ads, wordpress, gbp | review_reply, local_post, profile_update, profile_fields, media_upload, negative_list_add, ads_change, draft_create, article_drafts, post_date, update_apply, site_fix, content_draft, content_apply, connector_update, site_build |

Google Ads kampanya / bütçe / durum değişikliği **yoktur**; öneriler Google Ads Editor dosyası olarak dışa aktarılır.

## Kuyruklar

- `default` — hızlı işler (bildirim, dış yazmalar, uptime).
- `heavy` — uzun AI / analiz işleri; yalnız redis kuyruğunda ayrılır.
- `collection` — merkezi veri toplama.

## Zamanlanmış işler

| Ne zaman | İş |
| --- | --- |
| `*/5 * * * * (Europe/Istanbul)` | `async:mark-stale-runs` |
| `*/5 * * * * (Europe/Istanbul)` | `horizon:snapshot` |
| `23 */2 * * * (Europe/Istanbul)` | `moxdop:ads:budget-watch` |
| `27 * * * * (Europe/Istanbul)` | `moxdop:ads:catch-up` |
| `40 5 * * * (Europe/Istanbul)` | `moxdop:ads:quiet-dormant` |
| `43 7 * * * (Europe/Istanbul)` | `moxdop:ads:service-stats` |
| `13 10 * * * (Europe/Istanbul)` | `moxdop:ads:winners-snapshot` |
| `10 0 * * * (Europe/Istanbul)` | `moxdop:ai:claude-api-window` |
| `*/15 * * * * (Europe/Istanbul)` | `moxdop:ai:claude-api-window` |
| `20 4 * * * (Europe/Istanbul)` | `moxdop:ai:prune-jobs` |
| `30 9 * * * (Europe/Istanbul)` | `moxdop:alerts:scan` |
| `40 7 * * 1 (Europe/Istanbul)` | `moxdop:analyst:weekly` |
| `30 6 * * * (Europe/Istanbul)` | `moxdop:backup` |
| `50 * * * * (Europe/Istanbul)` | `moxdop:backup` |
| `35 6 * * 0 (Europe/Istanbul)` | `moxdop:bing:backlinks` |
| `5 7 * * * (Europe/Istanbul)` | `moxdop:bing:collect` |
| `47 9 * * * (Europe/Istanbul)` | `moxdop:brand-candidates` |
| `20 3 * * * (Europe/Istanbul)` | `moxdop:brands:audit` |
| `40 2 * * * (Europe/Istanbul)` | `moxdop:brands:autofill` |
| `13 21 * * 0 (Europe/Istanbul)` | `moxdop:brands:care` |
| `41 9 * * 1 (Europe/Istanbul)` | `moxdop:brands:chief` |
| `50 5 * * * (Europe/Istanbul)` | `moxdop:brands:direction` |
| `*/10 * * * * (Europe/Istanbul)` | `moxdop:brands:direction` |
| `40 5 * * * (Europe/Istanbul)` | `moxdop:brands:dossier` |
| `55 5 * * * (Europe/Istanbul)` | `moxdop:brands:epicrisis` |
| `21 * * * * (Europe/Istanbul)` | `moxdop:brands:epicrisis` |
| `*/15 * * * * (Europe/Istanbul)` | `moxdop:brands:follow-up` |
| `50 3 * * * (Europe/Istanbul)` | `moxdop:brands:review` |
| `40 5 * * 1 (Europe/Istanbul)` | `moxdop:brands:site-logos` |
| `25 * * * * (Europe/Istanbul)` | `moxdop:brands:site-logos` |
| `3 7 * * * (Europe/Istanbul)` | `moxdop:clarity:pull` |
| `10 7 * * * (Europe/Istanbul)` | `moxdop:clusters:score-pages` |
| `37 4 * * * (Europe/Istanbul)` | `moxdop:clusters:sync-overlaps` |
| `35 6 * * * (Europe/Istanbul)` | `moxdop:collection:activity-refresh` |
| `* * * * * (Europe/Istanbul)` | `moxdop:collection:redispatch-stale` |
| `45 9 * * * (Europe/Istanbul)` | `moxdop:compliance:scan` |
| `7,27,47 * * * * (Europe/Istanbul)` | `moxdop:content:autopilot` |
| `17 5 * * * (Europe/Istanbul)` | `moxdop:content:cluster-links` |
| `23 * * * * (Europe/Istanbul)` | `moxdop:content:post-status` |
| `0 5 * * 1 (Europe/Istanbul)` | `moxdop:content:week` |
| `0 9,15 * * * (Europe/Istanbul)` | `moxdop:content:weekly-titles` |
| `0 6 * * 3 (Europe/Istanbul)` | `moxdop:content:weekly-titles` |
| `10 8 * * * (Europe/Istanbul)` | `moxdop:data-pool-audit` |
| `20 6 * * * (Europe/Istanbul)` | `moxdop:db:ensure-partitions` |
| `*/5 * * * * (Europe/Istanbul)` | `moxdop:dispatch-due-automations` |
| `12 * * * * (Europe/Istanbul)` | `moxdop:finance:tick` |
| `37 5 * * * (Europe/Istanbul)` | `moxdop:gbp:fill-post-queue` |
| `*/5 * * * * (Europe/Istanbul)` | `moxdop:gbp:publish-queue` |
| `* * * * * (Europe/Istanbul)` | `moxdop:gbp:publish-scheduled` |
| `40 7 * * * (Europe/Istanbul)` | `moxdop:gbp:purge-expired` |
| `40 3 * * * (Europe/Istanbul)` | `moxdop:gbp:reply-drafts` |
| `17 * * * * (Europe/Istanbul)` | `moxdop:gbp:review-posts` |
| `52 6 * * * (Europe/Istanbul)` | `moxdop:gbp:suggestions` |
| `40 8 * * * (Europe/Istanbul)` | `moxdop:google-ads:record-quality-scores` |
| `7 7 * * * (Europe/Istanbul)` | `moxdop:google-ads:suggestions` |
| `20 6 * * 1 (Europe/Istanbul)` | `moxdop:google-ads:weekly-terms` |
| `5 9 * * * (Europe/Istanbul)` | `moxdop:gsc:inspect-urls` |
| `40 8 * * * (Europe/Istanbul)` | `moxdop:health:repair` |
| `10 5 * * * (Europe/Istanbul)` | `moxdop:integrations:discover` |
| `10 7 * * * (Europe/Istanbul)` | `moxdop:links:daily` |
| `40 7 * * 1 (Europe/Istanbul)` | `moxdop:links:desk` |
| `50 6 * * * (Europe/Istanbul)` | `moxdop:links:gate` |
| `10 2 * * * (Europe/Istanbul)` | `moxdop:links:templates` |
| `15 9 * * * (Europe/Istanbul)` | `moxdop:measurement:refresh` |
| `25 7 * * 1 (Europe/Istanbul)` | `moxdop:mentions:sync` |
| `41 8 * * * (Europe/Istanbul)` | `moxdop:meta:geo-results` |
| `56 6 * * * (Europe/Istanbul)` | `moxdop:meta:suggestions` |
| `52 8 * * * (Europe/Istanbul)` | `moxdop:ops:error-digest` |
| `*/5 * * * * (Europe/Istanbul)` | `moxdop:ops:evaluate-alerts` |
| `13 7 * * * (Europe/Istanbul)` | `moxdop:outcomes:measure` |
| `20 1-6 * * * (Europe/Istanbul)` | `moxdop:overlaps:check` |
| `*/15 * * * * (Europe/Istanbul)` | `moxdop:queries:autopilot` |
| `5 * * * * (Europe/Istanbul)` | `moxdop:queries:autopilot` |
| `47 5 * * * (Europe/Istanbul)` | `moxdop:queries:keyword-planner` |
| `23 * * * * (Europe/Istanbul)` | `moxdop:queries:prune-pending` |
| `25 * * * * (Europe/Istanbul)` | `moxdop:queries:prune-unserviced` |
| `40 4 * * * (Europe/Istanbul)` | `moxdop:repair:audit` |
| `5,35 0-6 * * * (Europe/Istanbul)` | `moxdop:repair:autopilot` |
| `10 5 * * * (Europe/Istanbul)` | `moxdop:repair:prepare` |
| `12 * * * * (Europe/Istanbul)` | `moxdop:repair:prepare` |
| `35 * * * * (Europe/Istanbul)` | `moxdop:repair:verify` |
| `* * * * * (Europe/Istanbul)` | `moxdop:resources:automate` |
| `10 8 * * * (Europe/Istanbul)` | `moxdop:resources:retry-stopped` |
| `10 7 2 * * (Europe/Istanbul)` | `moxdop:retention` |
| `20 3 * * * (Europe/Istanbul)` | `moxdop:retention` |
| `50 4 * * * (Europe/Istanbul)` | `moxdop:screens:check` |
| `20 5 * * * (Europe/Istanbul)` | `moxdop:showcase:daily` |
| `20 4 * * 2 (Europe/Istanbul)` | `moxdop:site:broken-translations` |
| `35 4 * * * (Europe/Istanbul)` | `moxdop:site:doorway-check` |
| `0 20 * * 2 (Europe/Istanbul)` | `moxdop:site:weekly` |
| `13 5 3 * * (Europe/Istanbul)` | `moxdop:site` |
| `43 5 * * 1 (Europe/Istanbul)` | `moxdop:site` |
| `27 5 * * * (Europe/Istanbul)` | `moxdop:site` |
| `*/10 * * * * (Europe/Istanbul)` | `moxdop:social:inbox` |
| `17 * * * * (Europe/Istanbul)` | `moxdop:social:insights` |
| `*/5 * * * * (Europe/Istanbul)` | `moxdop:social:publish` |
| `40 * * * * (Europe/Istanbul)` | `moxdop:targets:refresh` |
| `25 7 * * * (Europe/Istanbul)` | `moxdop:verify:data` |
| `20 6 * * * (Europe/Istanbul)` | `moxdop:verify:live` |
| `41 * * * * (Europe/Istanbul)` | `moxdop:website:drop-redirected` |
| `17 * * * * (Europe/Istanbul)` | `moxdop:website:sitemap-watch` |
| `* * * * * (Europe/Istanbul)` | `moxdop:whatsapp:dispatch` |
| `50 6 * * * (Europe/Istanbul)` | `moxdop:whatsapp:retention` |
| `20 9 * * * (Europe/Istanbul)` | `moxdop:wordpress:health` |
| `* * * * * (Europe/Istanbul)` | `moxdop:wordpress:reconcile` |
| `40 */3 * * * (Europe/Istanbul)` | `moxdop:work:warm-page-traffic` |
| `25 7 * * * (Europe/Istanbul)` | `queue:prune-failed` |
| `*/5 * * * * (Europe/Istanbul)` | ai-queue-api-round |
| `19 * * * * (Europe/Istanbul)` | disk-breakdown |
| `0 19 * * * (Europe/Istanbul)` | evening-close |
| `0 9 1 * * (Europe/Istanbul)` | marketing-month-summary |
| `30 8 * * * (Europe/Istanbul)` | morning-plan |
| `*/5 * * * * (Europe/Istanbul)` | queue-heartbeat-probe |
| `* * * * * (Europe/Istanbul)` | reminders-due |
| `0 * * * * (Europe/Istanbul)` | sales-brain-learn |
| `*/10 * * * * (Europe/Istanbul)` | sales-lead-research |
| `30 8 * * * (Europe/Istanbul)` | sales-morning-drafts |
| `25 * * * * (Europe/Istanbul)` | storage-detail |
| `*/5 * * * * (Europe/Istanbul)` | uptime-checks |
| `*/5 * * * * (Europe/Istanbul)` | whatsapp-backup-watchdog |
| `0 * * * * (Europe/Istanbul)` | whatsapp-contact-link |
| `*/30 * * * * (Europe/Istanbul)` | whatsapp-diagnosis |
