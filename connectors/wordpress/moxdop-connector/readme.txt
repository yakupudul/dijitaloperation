=== MoxDOP Website Connector ===
Contributors: moxdop
Tags: moxdop, website, inventory, seo
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later

Signed Website connector for MoxDOP. Reads inventory and health; can create drafts (never publishes); optional one-click admin login and approved updates, both off until the site admin enables them.

== Description ==

The connector exposes authenticated snapshots of WordPress/PHP settings,
themes, plugins, update availability, content and public custom post types, media
metadata, taxonomies, Polylang language fields, and allowlisted SEO plugin fields.

Activity records include the acting WordPress user ID/display name, event type and changed field names.
It does not expose account lists, passwords, comments, form submissions, arbitrary options, or media
file contents.

It is not read-only. Its only changing operations are listed under "Remote actions" below; each
one is signed, logged on the site, and can be switched off by the site admin.

== Installation ==

1. Upload and activate the plugin.
2. In MoxDOP, open the Website integration and generate a one-time pairing code.
3. In WordPress, open Settings > MoxDOP Connector.
4. Enter the MoxDOP HTTPS origin and pairing code.

== Security ==

Requests and responses are signed with HMAC-SHA256, expire after five minutes, and
use one-time nonces. The shared secret is encrypted at rest using Sodium or OpenSSL.

== Activity delivery ==

Content, SEO, maintenance and selected configuration changes are buffered locally.
Up to 50 events are sent every five minutes through WP-Cron, with signed acknowledgements,
deduplication and exponential retry. Low traffic can delay WP-Cron; configure a host cron
for reliable timing. The local outbox retains up to 10,000 events and reports coverage gaps.
No historical activity is invented. Existing pairing survives plugin updates.
Incremental snapshot requests accept up to 50 object IDs and echo the accepted scope.
Daily inventory reconciliation complements activity delivery.

== Remote actions ==

* Drafts: create a draft (post_status=draft) and trash drafts that MoxDOP created. Publishing and editing existing
  content are not possible. On by default; disable with the option `moxdop_connector_allow_drafts` = 0 or the
  `moxdop_connector_allow_drafts` filter.
* One-click login: a single-use link valid for 60 seconds that signs in as the WordPress user the site admin chose
  in Settings > MoxDOP Connector. Off until a user is chosen (`moxdop_connector_login_user`).
* Approved updates: apply one WordPress-offered core, plugin or theme update per request, after an admin approves it
  in MoxDOP. Off by default; enable with the option `moxdop_connector_allow_updates` = 1 or the
  `moxdop_connector_allow_updates` filter. Updates are not rolled back automatically.
* Connector updates: MoxDOP can update this plugin only, only to a newer version, only from a ZIP on the paired
  MoxDOP host whose SHA-256 matches, after an admin approves it. On by default; disable with the option
  `moxdop_connector_allow_self_update` = 0 or the `moxdop_connector_allow_self_update` filter.
* IndexNow: after a published page changes, the site tells Bing / Yandex (api.indexnow.org). The key file is served
  at /{key}.txt. On by default, off while search engines are discouraged; option `moxdop_connector_indexnow`.

Every remote action is written to the site's MoxDOP management log.

== Changelog ==

= 1.4.1 =
* Activity is sent right after a save (one-off WP-Cron with a non-blocking loopback); the 5-minute schedule stays as the fallback.
* One-click connector update from MoxDOP (hash-checked, newer versions only, can be switched off).
* IndexNow notice for published / changed pages and for pages changed by approved fixes.

= 1.4.0 =
* Optional, admin-approved SEO fixes (ADR-070): SEO title and description (Yoast, Rank Math, SEOPress or the plugin's own fields), image alt text, JSON-LD schema, 301 redirects, noindex / canonical, one internal link per change. Off by default; every change is logged and can be undone while nobody changed it since.
* Optional content updates: a new page version is saved as a draft copy and replaces the live page only after a second approval; WordPress keeps the old version as a revision. Off by default.
* Health, login-link and update responses are now signed like every other response.

= 1.3.0 =
* Added a health endpoint (WordPress / PHP versions, pending updates, Site Health counts).
* Added optional single-use admin login links and admin-approved updates (ADR-068); both off by default.

= 1.2.0 =
* Added signed draft creation and removal of MoxDOP-created drafts (ADR-064). Never publishes.

= 1.1.0 =
* Added signed activity outbox, delivery status and bounded object snapshots.
* Added safe health facts and selected branch/business fields.
* Excluded non-public internal post types such as form entry stores.
