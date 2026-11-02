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
require MX_DIR.'/inc/site.php';
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

/*
 * Pages built earlier with Elementor keep its page template (Elementor Canvas / Full Width), which would render the
 * old Elementor content instead of this theme's sections. A page that has sections always uses this theme's template.
 */
add_filter('template_include', static function (string $template): string {
    if (! is_singular() || ! str_contains($template, 'elementor') || ! function_exists('get_field')) {
        return $template;
    }
    if (empty(get_field('bolumler', get_queried_object_id(), false))) {
        return $template;
    }
    $own = locate_template(is_front_page() ? ['front-page.php', 'page.php'] : (is_page() ? ['page.php'] : ['single-'.get_post_type().'.php', 'single.php']));

    return $own ?: $template;
}, 99999);
