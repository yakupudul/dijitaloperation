<?php
defined('ABSPATH') || exit;

// Only what the page needs: one stylesheet, one deferred script, the font preloaded. No emoji, embeds, block CSS.
add_action('init', static function (): void {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'rest_output_link_wp_head');
    remove_action('template_redirect', 'rest_output_link_header', 11);
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
    add_filter('emoji_svg_url', '__return_false');
    add_filter('wp_img_tag_add_auto_sizes', '__return_false');
});

add_action('wp_enqueue_scripts', static function (): void {
    foreach (['wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles', 'core-block-supports'] as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
    }
    wp_dequeue_script('wp-embed');
    $css = MX_DIR.'/assets/site.css';
    wp_enqueue_style('moximu', MX_URI.'/assets/site.css', [], MX_VER.'.'.(is_file($css) ? filemtime($css) : 0));
    $js = MX_DIR.'/assets/site.js';
    wp_enqueue_script('moximu', MX_URI.'/assets/site.js', [], MX_VER.'.'.(is_file($js) ? filemtime($js) : 0), ['in_footer' => true, 'strategy' => 'defer']);
    wp_localize_script('moximu', 'MX', ['ajax' => admin_url('admin-ajax.php')]);
}, 100);

add_action('wp_head', static function (): void {
    foreach (['inter-latin', 'inter-latin-ext'] as $font) {
        echo '<link rel="preload" href="'.esc_url(MX_URI.'/assets/fonts/'.$font.'.woff2').'" as="font" type="font/woff2" crossorigin>'."\n";
    }
}, 1);

// A theme with its own sections has no use for jQuery Migrate on the front end.
add_action('wp_default_scripts', static function ($scripts): void {
    if (! is_admin() && isset($scripts->registered['jquery'])) {
        $scripts->registered['jquery']->deps = array_diff($scripts->registered['jquery']->deps, ['jquery-migrate']);
    }
});
