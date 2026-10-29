<?php

/**
 * Plugin Name: MoxDOP Website Connector
 * Description: Signed Website inventory connector for MoxDOP. Reads inventory and health; creates drafts (with categories, SEO fields and Polylang language / translation links); one-click login, approved updates, approved SEO fixes (301 merges are kept by the connector, listed under Tools › MoxDOP yönlendirmeleri, and the old page becomes a draft) and approved content updates only when a site admin enables them; tells MoxDOP (with the state of the posts MoxDOP made) and IndexNow about changes right after a save, without waiting for WP-Cron; exports HTML the page-cache plugin already stored and the rendered content of published pages (read-only); builds a site (ACF, pages, Elementor templates, media, menus) only when a site admin enables site building; when the host refuses MoxDOP's requests, the site fetches its work from MoxDOP itself; the post editor offers "Akıllı tarih": the nearest free Monday, Wednesday or Friday of the last 60 days (or the next one ahead)  as the publishing date; a site admin can connect the site directly to Claude or ChatGPT (MCP with OAuth sign-in: read, drafts, SEO and content edits by the chosen level, never publishing, every write undoable).
 * Version: 1.30.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: MoxDOP
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;

define('MOXDOP_CONNECTOR_VERSION', '1.30.0');
define('MOXDOP_CONNECTOR_FILE', __FILE__);
define('MOXDOP_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-canonical-json.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-lock.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-secrets.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-auth.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-drafts.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-page-cache.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-content-export.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-posts.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-rest-controller.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-admin.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-events.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-commands.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-health.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-management.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-fixes.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-builder.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-indexnow.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-updater.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-translation-pairing.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-smart-date.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-ai.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-ai-full.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-showcase.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-showcase-admin.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-admin-ui.php';

(new MoxDOP_Connector_Management)->register();
(new MoxDOP_Connector_Fixes)->register();
(new MoxDOP_Connector_Builder)->register();
(new MoxDOP_Connector_IndexNow)->register();
(new MoxDOP_Connector_Smart_Date)->register();
(new MoxDOP_Connector_AI)->register();
(new MoxDOP_Connector_Showcase)->register();
register_uninstall_hook(__FILE__, ['MoxDOP_Connector_Showcase', 'uninstall']);

(new MoxDOP_Connector_Events)->register();
register_deactivation_hook(__FILE__, ['MoxDOP_Connector_Events', 'deactivate']);
(new MoxDOP_Connector_Commands)->register();
register_deactivation_hook(__FILE__, ['MoxDOP_Connector_Commands', 'deactivate']);

register_activation_hook(__FILE__, static function () {
    if (! get_option('moxdop_connector_installation_id')) {
        add_option('moxdop_connector_installation_id', wp_generate_uuid4(), '', 'no');
    }
});

add_action('rest_api_init', static function () {
    (new MoxDOP_Connector_REST_Controller(new MoxDOP_Connector_Auth))->register_routes();
});

if (is_admin()) {
    (new MoxDOP_Connector_Admin_UI)->register();
    (new MoxDOP_Connector_Showcase_Admin)->register();
    (new MoxDOP_Connector_Admin(new MoxDOP_Connector_Secrets))->register();
    (new MoxDOP_Connector_Translation_Pairing)->register();
}
