<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.4.1: one-click update of THIS plugin from MoxDOP.
 *
 * MoxDOP sends a signed request with the new version, a short-lived download link on the MoxDOP host that paired
 * this site, and the SHA-256 of the ZIP. The plugin downloads, checks the hash, installs over itself and keeps
 * itself active. Only newer versions, only this plugin, only from the paired MoxDOP host. A site admin can switch it
 * off in Settings › MoxDOP Connector.
 */
final class MoxDOP_Connector_Updater
{
    public static function allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_self_update', get_option('moxdop_connector_allow_self_update', '1') === '1');
    }

    public function update(WP_REST_Request $request)
    {
        if (! self::allowed()) {
            return new WP_Error('moxdop_self_update_disabled', 'Connector self-update is disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $version = is_array($body) ? (string) ($body['version'] ?? '') : '';
        $url = is_array($body) ? (string) ($body['package_url'] ?? '') : '';
        $sha = is_array($body) ? strtolower((string) ($body['sha256'] ?? '')) : '';
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version) || ! preg_match('/^[a-f0-9]{64}$/', $sha) || strlen($url) > 2048) {
            return new WP_Error('moxdop_invalid_update', 'version, package_url and sha256 are required.', ['status' => 400]);
        }
        if (version_compare($version, MOXDOP_CONNECTOR_VERSION, '<=')) {
            return new WP_Error('moxdop_not_newer', 'The connector is already at '.MOXDOP_CONNECTOR_VERSION.'.', ['status' => 409]);
        }
        $app = (string) get_option('moxdop_connector_app_url');
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url($app, PHP_URL_HOST)) {
            return new WP_Error('moxdop_untrusted_package', 'The package must come from the paired MoxDOP host.', ['status' => 400]);
        }
        if (dirname(plugin_basename(MOXDOP_CONNECTOR_FILE)) !== 'moxdop-connector') {
            return new WP_Error('moxdop_unexpected_folder', 'The connector is installed in an unexpected folder; update it manually once.', ['status' => 409]);
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (! WP_Filesystem()) {
            return new WP_Error('moxdop_filesystem', 'WordPress cannot write files without FTP credentials on this host.', ['status' => 409]);
        }
        $file = download_url($url, 60);
        if (is_wp_error($file)) {
            return new WP_Error('moxdop_download_failed', 'Download failed: '.$file->get_error_message(), ['status' => 502]);
        }
        if (! hash_equals($sha, (string) hash_file('sha256', $file))) {
            wp_delete_file($file);

            return new WP_Error('moxdop_hash_mismatch', 'The package hash does not match.', ['status' => 400]);
        }
        $plugin = plugin_basename(MOXDOP_CONNECTOR_FILE);
        $was_active = is_plugin_active($plugin);
        $skin = new Automatic_Upgrader_Skin;
        $result = (new Plugin_Upgrader($skin))->install($file, ['overwrite_package' => true, 'clear_update_cache' => true]);
        wp_delete_file($file);
        if ($was_active && ! is_plugin_active($plugin)) {
            activate_plugin($plugin);
        }
        wp_clean_plugins_cache();
        $data = get_plugin_data(MOXDOP_CONNECTOR_FILE, false, false);
        $after = (string) ($data['Version'] ?? '');
        $ok = ! is_wp_error($result) && $result !== false && $after === $version;
        $message = is_wp_error($result) ? $result->get_error_message() : implode(' ', array_map('wp_strip_all_tags', (array) $skin->get_upgrade_messages()));
        $log = get_option('moxdop_connector_management_log', []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['action' => $ok ? 'self_update_completed' : 'self_update_failed', 'at' => gmdate('c'), 'from' => MOXDOP_CONNECTOR_VERSION, 'to' => $after]);
        update_option('moxdop_connector_management_log', array_slice($log, 0, 30), false);

        return new WP_REST_Response(['ok' => $ok, 'from_version' => MOXDOP_CONNECTOR_VERSION, 'to_version' => $after, 'message' => substr((string) $message, 0, 1000)], $ok ? 200 : 500);
    }
}
