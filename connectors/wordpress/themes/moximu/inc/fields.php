<?php
defined('ABSPATH') || exit;

// Field groups ship with the theme (acf/*.json) so every site running it has the same, versioned fields.
add_action('acf/init', static function (): void {
    if (function_exists('acf_add_options_page')) {
        acf_add_options_page([
            'page_title' => 'Moximu ayarları', 'menu_title' => 'Moximu ayarları', 'menu_slug' => 'moximu-ayarlar',
            'capability' => 'edit_theme_options', 'position' => 59, 'icon_url' => 'dashicons-admin-site-alt3', 'autoload' => true,
        ]);
    }
    if (! function_exists('acf_add_local_field_group')) {
        return;
    }
    foreach (glob(MX_DIR.'/acf/*.json') ?: [] as $file) {
        $group = json_decode((string) file_get_contents($file), true);
        if (is_array($group) && ! empty($group['key'])) {
            acf_add_local_field_group($group);
        }
    }
});

add_action('admin_notices', static function (): void {
    if (! function_exists('get_field') && current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>Moximu teması için Advanced Custom Fields PRO etkin olmalı.</p></div>';
    }
});
