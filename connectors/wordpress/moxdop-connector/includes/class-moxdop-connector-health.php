<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_Health
{
    public function snapshot()
    {
        global $wpdb;
        $cron = _get_cron_array();
        $overdue = 0;
        $oldest = null;
        foreach (is_array($cron) ? $cron : [] as $timestamp => $hooks) {
            if ((int) $timestamp < time() - 3600) {
                foreach ($hooks as $events) {
                    $overdue += count($events);
                }
                $oldest = $oldest === null ? (int) $timestamp : min($oldest, (int) $timestamp);
            }
        }
        $modules = [];
        foreach (['curl', 'dom', 'fileinfo', 'json', 'mbstring', 'mysqli', 'openssl', 'zip', 'gd', 'imagick'] as $module) {
            $modules[$module] = extension_loaded($module);
        }
        return [
            'observed_at' => gmdate('c'),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : null,
            'https' => wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https',
            'debug_display' => defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            'file_editor_disabled' => (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS),
            'external_http_blocked' => defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL,
            'cron_overdue_count' => $overdue,
            'cron_oldest_overdue_at' => $oldest ? gmdate('c', $oldest) : null,
            'cron_delay_threshold_seconds' => 3600,
            'database_version' => $wpdb->db_version(),
            'php_modules' => $modules,
            'php_memory_limit' => (string) ini_get('memory_limit'),
            'object_cache_external' => wp_using_ext_object_cache(),
            'page_cache_configured' => defined('WP_CACHE') && WP_CACHE,
            'users_can_register' => (bool) get_option('users_can_register'),
            'default_role' => (string) get_option('default_role'),
            'event_delivery' => (new MoxDOP_Connector_Events())->status(),
            'adapters' => [
                'seopress' => defined('SEOPRESS_VERSION') ? SEOPRESS_VERSION : null,
                'litespeed' => defined('LSCWP_V') ? LSCWP_V : null,
                'elementor' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                'acf' => defined('ACF_VERSION') ? ACF_VERSION : null,
                'polylang' => defined('POLYLANG_VERSION') ? POLYLANG_VERSION : null,
            ],
            'management_enabled' => false,
        ];
    }
}
