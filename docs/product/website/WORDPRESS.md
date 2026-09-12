# WordPress Connector V1

## Inventory bootstrap correction — 2026-09-11

This supersedes the first-heartbeat prerequisite below. Pairing and the reconciliation scheduler
initialize missing delivery state for enabled paired connections. Signed connection tests refresh
the installed plugin version. No receipt time, event or successful inventory is fabricated by
initialization. Daily/three-day inventory polling works before the first push and with V1 snapshots;
1.1.0 is still required for changed-object scopes and activity delivery. Existing pause/cursors are
preserved. Older paired installations recover on the next scheduler tick. The public crawl and
PageSpeed remain separate. PHP tests and live UAT were not executable in this environment.

## Purpose

WordPress Connector, bir Website Digital Asset için CMS’in içeriden bildiği verileri
salt-okunur ve imzalı snapshot’lar halinde toplar. WordPress bir Digital Asset değildir;
asset-scoped `CoreConnection` + encrypted `CoreConnectionCredential` olarak kalır.

Public Discovery ayrı bir kaynaktır. Connector CMS iç gerçeğini, Public Discovery ise
ziyaretçiye/search engine’e yayınlanan HTTP ve HTML gerçeğini temsil eder. WordPress
sitesinde ikisi birlikte çalışır; connector Public Discovery’nin yerine geçmez.

## Pairing and authentication

1. Admin, MoxDOP root operator ekranında Website için 15 dakikalık tek kullanımlık kod üretir.
2. Kodun yalnızca HMAC hash’i ve son kullanma zamanı DB’ye yazılır; açık metin kod yazılmaz.
3. WordPress yöneticisi production plugin ekranında `https://app.moximu.com` ve kodu girer.
4. Plugin `POST /api/connectors/wordpress/pair` çağrısında site/home ve iki REST endpoint’ini gönderir.
5. MoxDOP Website host eşleşmesini, HTTPS’i ve `/wp-json/moxdop/v1/{status|snapshot}` yollarını doğrular.
6. MoxDOP tek sefer gösterilen client ID + 256-bit shared secret üretir. MoxDOP tarafı Laravel
   encrypted cast, WordPress tarafı Sodium secretbox veya AES-256-GCM ile saklar.
7. Pairing code yeniden kullanılamaz. Credential rotation, yeni pairing tamamlanana kadar çalışan
   credential’ı kesmez.

Her connector isteği `GET` ve HMAC-SHA256 imzalıdır. İmza; method, REST route, canonical query,
Unix timestamp, UUID nonce ve body hash’i kapsar. Beş dakikalık clock-skew sınırı ve WordPress
transient tabanlı replay koruması vardır. Yanıtlar da server time, request nonce ve canonical data
hash’i üzerinden imzalanır. MoxDOP redirect kabul etmez, yanıt boyutunu sınırlar ve hedefi public
network URL güvenlik kontrolünden geçirir.

## Read-only endpoints

- `GET /wp-json/moxdop/v1/status`
- `GET /wp-json/moxdop/v1/snapshot?section=...&page=...&per_page=...`

Snapshot bölümleri:

| Section | Collected facts |
| --- | --- |
| `site` | WordPress/PHP version, safe settings allowlist, active theme, locale/timezone, multisite, REST/cron state, Polylang/LiteSpeed presence, cached Site Health payload when available |
| `extensions` | Plugin/theme identity, version, active state, update availability, available version, auto-update state |
| `content` | Page/post/public UI CPT inventory across publish/draft/pending/private/future, title, slug, permalink, dates, parent/template/media, Polylang language/translations, raw/rendered content and hash |
| `media` | Attachment metadata and URL, alt, MIME, dimensions and size metadata; no media binary |
| `taxonomies` | Public/UI taxonomies, terms, parent/count and Polylang language |
| `seo` | Allowlisted Yoast, Rank Math and SEOPress title/description/canonical/robots fields |

No user account, password, comment, arbitrary option, media file binary or write route is exposed.
Create/update/delete/publish operations are explicit non-goals.

## Data contract

Connector family: `WEB_RF_WP_REST` / provider `WORDPRESS_SITE_CONNECTOR`.

Normalized current-state datasets:

- `website_cms_site_snapshot`
- `website_cms_object_snapshot`
- `website_cms_extension_snapshot`
- `website_cms_taxonomy_snapshot`
- `website_cms_seo_snapshot`

Full connector response, including content HTML, remains in the compressed private raw ingestion
object. Normalized CMS object rows retain inventory, provenance, content hash/length and safe metadata;
they do not duplicate full HTML.

Public collection additionally writes `website_html_snapshot` for the final HTML returned to an
outside visitor. Each URL observation stores the current and previous SHA-256 hash, byte size,
response identity, `first_seen|unchanged|changed` state and a reference to the compressed private HTML
artifact. Artifact storage is content-addressed: an unchanged HTML body is not stored a second time,
while every collection still records a new observation. This is intentionally different from
WordPress `post_content` / block rendering. Draft and private CMS objects remain connector-only because
they have no public visitor HTML.

## Product boundary

Website Integration displays only connection state, last collection, required-source progress,
discovered URL versus captured HTML coverage, HTML change counts, record/batch counts, dataset
schema/preview and collection history. It does not interpret observations as Findings,
Recommendations or Tasks.

Website Digital Asset analysis consumes completed connector and public DatasetRuns. Deterministic V1
rules cover reported core/plugin/theme updates, REST/cron state and connector-to-published-HTML parity
for SEO title, description and canonical. “Update available” is not described as a vulnerability.
The Finding lifecycle creates grounded Recommendations; Task creation remains manual.

GA4 and GSC continue to contribute behavior/search Evidence to the Website workspace through their
existing connections. Connector collection never guesses indexing, analytics behavior, SSL, DNS,
CDN/Nginx output or the final browser HTML.

## Runtime and acceptance state

Connector collection is queued through the shared Website Collection Engine. Non-WordPress Website
assets run public families only. A paired WordPress Website plans public families plus
`WEB_RF_WP_REST` in the same run.

The resumable public collection queue is seeded from sitemaps, previously discovered URLs and published
WordPress permalinks, then expanded through real same-site links. The collection bound is 5,000 HTML
pages / 2 GB downloaded response data per run, with a 10 MB complete-response limit per URL; reaching a
bound is recorded explicitly and must not be described as an unbounded whole-internet crawl.

Code and automated contract coverage do not prove a live WordPress installation. Real operator UAT
must install the generated ZIP on a disposable WordPress site, pair it to the matching Website asset,
run a collection, verify all five datasets and confirm Public Discovery remains present. No live UAT
or production deploy is claimed by this document.


## Connector activity and standards extension — 2026-09-09

Operator-approved sequence: connector → integration ingestion/UX → deterministic standards.
The existing pairing and read endpoints remain compatible; downloadable connector version is 1.1.0.
No clone, dependency install, tests, formatter, build, browser or host execution was performed.
Source implementation is not deployment, runtime acceptance, or DONE.

Implemented:
- WordPress local non-autoloaded outbox table, 50-event signed POST batches through WP-Cron every
  five minutes, signed per-ID acknowledgements, replay protection, retry/backoff and 10,000-event cap.
  Save hooks never perform HTTP. Events in one request coalesce by type/object; separate editor
  requests remain separate audit records. Missing queue writes/overflow report a persistent gap.
- Content publish/update/status/delete, allowlisted SEO/business metadata, selected settings,
  theme/plugin maintenance and role-change events. Acting WP user ID/display name are included;
  no passwords, form entries, arbitrary metadata values, visitor clicks or binary media.
  Public content types are inventoried; internal submission/order stores are excluded.
- Safe cached/runtime health, delayed cron count, module presence, debug/registration policies,
  cache flags, adapter versions and allowlisted branch fields extend existing CMS metadata.
- Additive receiving tables; connection/installation-bound HMAC, timestamp/nonce verification,
  credential recheck under connection lock, deduplication and transactional acknowledgement.
- Website Integration Activity tab: period/type/actor filters, 25-row pagination, delivery age,
  pending count and coverage-gap warning. Global Activity merges the same stored events.
- Scheduler admits at most two event reconciliation collections, at most 50 events/changed object
  IDs per incremental scope, and defers while the asset has an active collection. Successful
  completion advances the receipt watermark; failed/partial collections do not. The existing
  CMS snapshot writer retains untouched objects on incremental refresh and removes only scoped
  missing objects. The connector must echo the exact object scope before writes are accepted.
- Daily CMS inventory reconciliation and global-settings changes use full CMS inventory; ordinary
  content changes use scoped content/media/SEO reads. Site/extensions/taxonomies are still full
  lightweight snapshots. Relevant known public URLs use existing bounded targeted crawl (max 100).
  No daily all-page browser run, paid provider or AI call is introduced.
- Standards categories: Website, Google Ads, Meta Ads. Ads categories are placeholders with explicit
  empty-state copy; their criteria are not shipped. Website definitions distinguish general vs
  WordPress inheritance. Existing expert criteria are retained but inactive; their add form is
  removed. Length heuristics default inactive. Historical evidence is preserved.
- 25 additional deterministic/advisory checks cover duplicate/multiple metadata, H1/language,
  internal target status, canonical/hreflang target status, content fingerprint matches, image
  attributes, mixed resources, WordPress debug/registration/visibility/cron/update/health policies
  and delivery freshness. Existing 500-profile assessment bound remains visible. Missing target
  HTTP, stale evidence, unsupported connector fields and truncated scans do not become passes.
  Inventory, HTML and cached Site Health observations remain distinct.

Not implemented in this delivery:
- Remote SEOPress/LiteSpeed write controls, installations, automatic plugin/theme/core updates,
  image optimization or rollback. management_enabled=false; no advertised executable action.
- Complete malware scans, backup/SMTP provider adapters, visitors/session recording, historical
  activity reconstruction, all-page browser checks or automatic whole-site standards after each event.
- Full planned SEO catalogue (e.g. complete sitemap membership, reciprocal hreflang, orphan/depth
  graph, GSC URL Inspection/cluster comparison) is not yet implemented by the new checks.
- Continuous CMS delta cursor independent of events; daily inventory is the recovery mechanism.
  Low traffic or disabled WP-Cron needs host scheduling. A coverage gap cannot reconstruct history.

Deployment: normal staging script installs additive Laravel tables and restarts workers.
Then download connector 1.1.0 from the existing connector screen and replace the installed plugin.
Existing pairing remains; WordPress init creates the local outbox. First successful heartbeat
enables reconciliation. Scheduler and collection workers must run. Verify live pairing, event
redelivery, scoped deletion, filtered history, and standards before operational acceptance.

## Website integration collection controls — 2026-09-10

Implemented in staging source; no clone, tests, formatter, build, browser UAT or deployment run,
as explicitly requested by the operator. This is not a verified runtime/DONE claim.

- Website integration offers General (public HTML/TLS + paired WordPress), Public, WordPress full
  inventory, and PageSpeed scopes. PageSpeed is explicit in this screen; other existing callers'
  family defaults are unchanged. Missing CMS/PageSpeed connections are checked on the server.
- Connector 1.1.0+ delivery state exposes daily/three-day full inventory cadence and pause/resume.
  Event-driven refresh remains in bounded batches between inventories. Pause affects future
  admissions, not an active run or receipt/audit recording; manual collection remains available.
- Display last receipt, automatic reconciliation/full inventory, central pending event count,
  stale receipt warning and retry errors. Pending count is stored unprocessed events, not the
  sender's last-reported outbox size. Automatic inventory timestamps exclude manual collections.
- Source statuses use latest dataset attempts per source, independent of the most recent overall
  run. Latest-run counters remain explicitly latest-run counters. Queries no longer hydrate the
  entire collection history each poll; idle screen refreshes every 30 seconds.
- Collection console/history show scope and automatic trigger. PageSpeed-only runs have a valid
  progress denominator. Admission through the Website orchestrator rejects another active run
  under a short per-asset cache lock; reconciliation ticks also use a shared lock.
- Fix full-inventory reconciliation advancing past the first 50 events: only the processed event
  batch advances the cursor after success, retaining later URL refresh work. No incoming history
  is deleted. Failed/partial work retains its cursor, retrying later. At most two automatic
  reconciliation runs remain active; busy/unsupported candidates are deferred.
- A missing recent heartbeat no longer silently suppresses periodic recovery inventory after the
  first supported delivery. Disconnected/unpaired sources are excluded; old plugin versions wait.
- New additive preference columns/indexes require the normal staging migration. Shared cache,
  scheduler and collection workers are required. Keep Connector 1.1.0+ installed and WP-Cron or
  host cron running. General public crawl/PageSpeed are not automatically run daily. No remote
  CMS mutation, paid enrichment or AI request is introduced.

Remaining acceptance: migrate staging, exercise scope selection and pause/resume, confirm delayed
event batches/cursor recovery and source timestamps against a real paired site. No such acceptance
has been performed in this change.

## Collection freshness and progress clarification — 2026-09-12

Supersedes the heartbeat prerequisite and manual-inventory exclusion described in older entries.
Pairing/scheduler bootstrap does not require a delivered heartbeat. A recent successful full
WordPress/general run containing all five completed WP datasets satisfies inventory freshness;
its finish time is shown as “Last full WordPress inventory”. It does not consume pending content
notifications. Access-role-only batches need no content inventory. Actual content changes retain
the existing bounded changed-object and affected-URL path with connector 1.1.0+; periodic daily or
three-day full WordPress inventory remains a safety net for missed notifications.

The global header shows each job's queue/retry/stall state and completed dataset count, without a
synthetic overall percentage. Manual general/public collection still starts a fresh public crawl;
chunk execution within a run resumes its saved queue/checkpoint. The watchdog recovers expired
running jobs with bounded attempts and never replays completed datasets. Provider continuation
backoff is persisted so the database worker cannot poll before it expires.

Design reference: Google's [crawl-budget guidance](https://developers.google.com/crawling/docs/crawl-budget)
separates demand/freshness from capacity. Here, event scopes and durable checkpoints reduce work;
conditional HTTP revalidation and adaptive per-URL recrawl policy remain future work, not claims
of this patch. PHPUnit regressions are present but PHP/Pint and live UAT were unavailable.
