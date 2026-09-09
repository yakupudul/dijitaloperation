=== MoxDOP Website Connector ===
Contributors: moxdop
Tags: moxdop, website, inventory, seo
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Read-only, signed Website inventory connector for MoxDOP.

== Description ==

The connector exposes authenticated, read-only snapshots of WordPress/PHP settings,
themes, plugins, update availability, content and public custom post types, media
metadata, taxonomies, Polylang language fields, and allowlisted SEO plugin fields.

Activity records include the acting WordPress user ID/display name, event type and changed field names.
It does not expose account lists, passwords, comments, form submissions, arbitrary options, or media
file contents. It does not register create, update, or delete REST methods.

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
Daily inventory reconciliation complements activity delivery. Remote management is disabled.

== Changelog ==

= 1.1.0 =
* Added signed activity outbox, delivery status and bounded object snapshots.
* Added safe health facts and selected branch/business fields.
* Excluded non-public internal post types such as form entry stores.
