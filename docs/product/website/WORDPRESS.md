# WordPress Connector V1

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

## Product boundary

Website Integration displays only connection state, last collection, progress, record/batch counts,
dataset schema/preview and collection history. It does not interpret observations as Findings,
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

Code and automated contract coverage do not prove a live WordPress installation. Real operator UAT
must install the generated ZIP on a disposable WordPress site, pair it to the matching Website asset,
run a collection, verify all five datasets and confirm Public Discovery remains present. No live UAT
or production deploy is claimed by this document.
