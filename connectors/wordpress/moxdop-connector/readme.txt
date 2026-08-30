=== MoxDOP Website Connector ===
Contributors: moxdop
Tags: moxdop, website, inventory, seo
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Read-only, signed Website inventory connector for MoxDOP.

== Description ==

The connector exposes authenticated, read-only snapshots of WordPress/PHP settings,
themes, plugins, update availability, content and public custom post types, media
metadata, taxonomies, Polylang language fields, and allowlisted SEO plugin fields.

It does not expose user accounts, passwords, comments, arbitrary options, or media
file contents. It does not register create, update, or delete REST methods.

== Installation ==

1. Upload and activate the plugin.
2. In MoxDOP, open the Website integration and generate a one-time pairing code.
3. In WordPress, open Settings > MoxDOP Connector.
4. Enter the MoxDOP HTTPS origin and pairing code.

== Security ==

Requests and responses are signed with HMAC-SHA256, expire after five minutes, and
use one-time nonces. The shared secret is encrypted at rest using Sodium or OpenSSL.
