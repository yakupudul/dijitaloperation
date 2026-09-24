<?php

defined('ABSPATH') || exit;

/**
 * Connector v2 (1.3.0): health report, one-click login and approved updates.
 *
 * Both write-like features are OFF until a site administrator enables them in Settings › MoxDOP Connector:
 * one-click login signs in only the user the site admin picked, with a single-use link valid for 60 seconds;
 * updates install only the plugin / theme / core update WordPress itself offers, one item per request.
 */
final class MoxDOP_Connector_Management
{
    const LOGIN_TTL = 60;

    public static function login_user_id()
    {
        return (int) get_option('moxdop_connector_login_user', 0);
    }

    public static function updates_allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_updates', get_option('moxdop_connector_allow_updates', '0') === '1');
    }

    public function register()
    {
        add_action('init', [$this, 'consume_login'], 1);
    }

    /** Site health for MoxDOP: versions, pending updates, WordPress Site Health result, basics. */
    public function health()
    {
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH.'wp-admin/includes/update.php';
        }
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();

        $core = get_core_updates();
        $core_update = null;
        foreach (is_array($core) ? $core : [] as $offer) {
            if (isset($offer->response) && $offer->response === 'upgrade') {
                $core_update = (string) $offer->current;
                break;
            }
        }
        $plugin_updates = get_site_transient('update_plugins');
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $update = isset($plugin_updates->response[$file]) ? $plugin_updates->response[$file] : null;
            $plugins[] = [
                'file' => $file,
                'name' => (string) $data['Name'],
                'version' => (string) $data['Version'],
                'active' => is_plugin_active($file),
                'update' => $update ? (string) $update->new_version : null,
            ];
        }
        $theme_updates = get_site_transient('update_themes');
        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[] = [
                'stylesheet' => (string) $stylesheet,
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active' => get_stylesheet() === $stylesheet,
                'update' => isset($theme_updates->response[$stylesheet]['new_version']) ? (string) $theme_updates->response[$stylesheet]['new_version'] : null,
            ];
        }
        $site_health = get_transient('health-check-site-status-result');
        $site_health = is_string($site_health) ? json_decode($site_health, true) : null;

        return [
            'plugin_version' => MOXDOP_CONNECTOR_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'core_update' => $core_update,
            'plugins' => $plugins,
            'themes' => $themes,
            'site_health' => is_array($site_health) ? ['good' => (int) ($site_health['good'] ?? 0), 'recommended' => (int) ($site_health['recommended'] ?? 0), 'critical' => (int) ($site_health['critical'] ?? 0)] : null,
            'basics' => (new MoxDOP_Connector_Health())->snapshot(),
            'login_enabled' => self::login_user_id() > 0,
            'updates_enabled' => self::updates_allowed(),
            'observed_at' => gmdate('c'),
        ];
    }

    /** Single-use, 60-second login link for the user the site admin allowed. */
    public function login_link(WP_REST_Request $request)
    {
        $user_id = self::login_user_id();
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        if (! $user) {
            return new WP_Error('moxdop_login_disabled', 'One-click login is not enabled on this site.', ['status' => 403]);
        }
        $token = wp_generate_password(48, false, false);
        set_transient('moxdop_login_'.hash('sha256', $token), ['user_id' => $user_id, 'created' => time()], self::LOGIN_TTL);
        $this->log('login_link', ['user_id' => $user_id]);

        return new WP_REST_Response(['url' => add_query_arg('moxdop_login', $token, home_url('/')), 'expires_in' => self::LOGIN_TTL, 'user' => $user->user_login], 201);
    }

    public function consume_login()
    {
        if (empty($_GET['moxdop_login']) || ! is_string($_GET['moxdop_login'])) {
            return;
        }
        $token = sanitize_text_field(wp_unslash($_GET['moxdop_login']));
        $key = 'moxdop_login_'.hash('sha256', $token);
        $data = get_transient($key);
        delete_transient($key);
        $user_id = is_array($data) ? (int) ($data['user_id'] ?? 0) : 0;
        if ($user_id <= 0 || $user_id !== self::login_user_id() || (time() - (int) ($data['created'] ?? 0)) > self::LOGIN_TTL) {
            wp_die('This MoxDOP login link is invalid or expired.', 'MoxDOP', ['response' => 403]);
        }
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, false, is_ssl());
        $this->log('login_used', ['user_id' => $user_id]);
        wp_safe_redirect(admin_url());
        exit;
    }

    /** Install the one update WordPress offers for a plugin, a theme or core. */
    public function update(WP_REST_Request $request)
    {
        if (! self::updates_allowed()) {
            return new WP_Error('moxdop_updates_disabled', 'Approved updates are disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $type = is_array($body) ? (string) ($body['type'] ?? '') : '';
        $item = is_array($body) ? (string) ($body['item'] ?? '') : '';
        if (! in_array($type, ['plugin', 'theme', 'core'], true) || ($type !== 'core' && ($item === '' || strlen($item) > 200))) {
            return new WP_Error('moxdop_invalid_update', 'Update type and item are required.', ['status' => 400]);
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/update.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (! WP_Filesystem()) {
            return new WP_Error('moxdop_filesystem', 'WordPress cannot write files without FTP credentials on this host.', ['status' => 409]);
        }
        $skin = new Automatic_Upgrader_Skin();
        $before = null;
        $after = null;
        if ($type === 'plugin') {
            wp_update_plugins();
            $plugins = get_plugins();
            if (! isset($plugins[$item])) {
                return new WP_Error('moxdop_unknown_item', 'Plugin not found.', ['status' => 404]);
            }
            $updates = get_site_transient('update_plugins');
            if (! isset($updates->response[$item])) {
                return new WP_Error('moxdop_no_update', 'No update is available for this plugin.', ['status' => 409]);
            }
            $before = (string) $plugins[$item]['Version'];
            $was_active = is_plugin_active($item);
            $result = (new Plugin_Upgrader($skin))->upgrade($item);
            if ($was_active && ! is_plugin_active($item)) {
                activate_plugin($item);
            }
            wp_clean_plugins_cache();
            $plugins = get_plugins();
            $after = isset($plugins[$item]) ? (string) $plugins[$item]['Version'] : null;
        } elseif ($type === 'theme') {
            wp_update_themes();
            $theme = wp_get_theme($item);
            $updates = get_site_transient('update_themes');
            if (! $theme->exists()) {
                return new WP_Error('moxdop_unknown_item', 'Theme not found.', ['status' => 404]);
            }
            if (! isset($updates->response[$item])) {
                return new WP_Error('moxdop_no_update', 'No update is available for this theme.', ['status' => 409]);
            }
            $before = (string) $theme->get('Version');
            $result = (new Theme_Upgrader($skin))->upgrade($item);
            wp_clean_themes_cache();
            $after = (string) wp_get_theme($item)->get('Version');
        } else {
            wp_version_check();
            $offers = get_core_updates();
            $offer = null;
            foreach (is_array($offers) ? $offers : [] as $candidate) {
                if (isset($candidate->response) && $candidate->response === 'upgrade') {
                    $offer = $candidate;
                    break;
                }
            }
            if (! $offer) {
                return new WP_Error('moxdop_no_update', 'No WordPress core update is available.', ['status' => 409]);
            }
            $before = get_bloginfo('version');
            $result = (new Core_Upgrader($skin))->upgrade($offer);
            $after = is_string($result) ? $result : null;
        }
        $ok = ! is_wp_error($result) && $result !== false && $result !== null;
        $message = is_wp_error($result) ? $result->get_error_message() : implode(' ', array_map('wp_strip_all_tags', (array) $skin->get_upgrade_messages()));
        $this->log($ok ? 'update_completed' : 'update_failed', ['type' => $type, 'item' => $item, 'from' => $before, 'to' => $after]);

        return new WP_REST_Response(['ok' => $ok, 'type' => $type, 'item' => $item, 'from_version' => $before, 'to_version' => $after, 'message' => substr((string) $message, 0, 1000)], $ok ? 200 : 500);
    }

    private function log($action, array $context)
    {
        $log = get_option('moxdop_connector_management_log', []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, ['action' => $action, 'at' => gmdate('c')] + $context);
        update_option('moxdop_connector_management_log', array_slice($log, 0, 30), false);
    }
}
