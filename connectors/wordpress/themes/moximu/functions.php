<?php
/**
 * Moximu theme. Every section of every page is an ACF flexible-content row ("Sayfa bölümleri"); header and footer
 * live under Görünüm › Moximu ayarları. No page builder, no jQuery, one CSS and one deferred JS file.
 */
defined('ABSPATH') || exit;

define('MX_DIR', get_template_directory());
define('MX_URI', get_template_directory_uri());
define('MX_VER', (string) wp_get_theme(get_template())->get('Version'));

require MX_DIR.'/inc/helpers.php';
require MX_DIR.'/inc/speed.php';
require MX_DIR.'/inc/fields.php';
require MX_DIR.'/inc/schema.php';
require MX_DIR.'/inc/analiz.php';
require MX_DIR.'/inc/seed.php';

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    register_nav_menus(['primary' => 'Ana menü']);
});

// Elementor Pro must not swap our header/footer while it is still installed: the theme declares its own locations
// and prints none of them.
add_action('elementor/theme/register_locations', static function ($manager): void {
    $manager->register_all_core_location();
});
