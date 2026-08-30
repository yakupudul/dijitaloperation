<?php
/**
 * Plugin Name: MoxDOP Website Connector
 * Description: Read-only, signed Website inventory connector for MoxDOP.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: MoxDOP
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('MOXDOP_CONNECTOR_VERSION', '1.0.0');
define('MOXDOP_CONNECTOR_FILE', __FILE__);
define('MOXDOP_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-canonical-json.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-secrets.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-auth.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-rest-controller.php';
require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-admin.php';

register_activation_hook(__FILE__, static function () {
    if (! get_option('moxdop_connector_installation_id')) {
        add_option('moxdop_connector_installation_id', wp_generate_uuid4(), '', 'no');
    }
});

add_action('rest_api_init', static function () {
    (new MoxDOP_Connector_REST_Controller(new MoxDOP_Connector_Auth()))->register_routes();
});

if (is_admin()) {
    (new MoxDOP_Connector_Admin(new MoxDOP_Connector_Secrets()))->register();
}

