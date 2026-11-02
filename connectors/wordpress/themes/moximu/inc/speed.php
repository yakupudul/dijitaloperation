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

/*
 * Plugins left over from the Elementor site (Elementor, Dynamic Content, Search & Filter, …) still enqueue their files
 * on every page. A page drawn by this theme needs none of them: on the front end only the theme's own files load.
 */
function mx_keep_handle(string $handle): bool
{
    return $handle === 'moximu' || str_starts_with($handle, 'moximu-') || (is_admin_bar_showing() && in_array($handle, ['admin-bar', 'dashicons', 'hoverintent-js', 'hoverIntent'], true));
}

function mx_trim_queue(): void
{
    if (is_admin() || isset($_GET['elementor-preview']) || is_customize_preview()) {
        return;
    }
    foreach ([wp_styles(), wp_scripts()] as $deps) {
        foreach ((array) $deps->queue as $handle) {
            if (! mx_keep_handle($handle)) {
                $deps->dequeue($handle);
            }
        }
    }
}
add_action('wp_print_styles', 'mx_trim_queue', 9999);
add_action('wp_print_scripts', 'mx_trim_queue', 9999);
add_action('wp_print_footer_scripts', 'mx_trim_queue', 1);
add_action('wp_footer', 'mx_trim_queue', 9999);

/*
 * Tag Manager, Meta Pixel and Clarity stay, but start after the page has loaded (or at the first tap / scroll), so
 * they never hold up the first paint. Every tag still fires on every visit.
 */
add_action('template_redirect', static function (): void {
    if (is_admin() || is_feed() || wp_doing_ajax() || isset($_GET['elementor-preview']) || is_customize_preview()) {
        return;
    }
    ob_start(static function (string $html): string {
        if (! str_contains($html, '</body>')) {
            return $html;
        }
        if (! empty($GLOBALS['mx_drawn'])) {
            // The page is drawn by this theme: the old Elementor site's CSS/JS (kept in WPCode for pages not moved
            // yet) and its Google Fonts link would only restyle it.
            $html = preg_replace([
                '#<style id="mox-site-b">.*?</style>#s',
                '#<script id="mx-(?:site|maps|prices)"[^>]*>.*?</script>#s',
                '#<link rel="preconnect" href="https://fonts\.(?:googleapis|gstatic)\.com"[^>]*>#',
                '#<link rel="stylesheet" href="https://fonts\.googleapis\.com/[^"]*"[^>]*>#',
            ], '', $html);
        }
        $count = 0;
        $html = preg_replace_callback('#<script(?![^>]*\btype=["\']?(?:application/(?:ld\+)?json|speculationrules))([^>]*)>(.*?)</script>#is', static function (array $m) use (&$count): string {
            if (! preg_match('#googletagmanager\.com|connect\.facebook\.net|clarity\.ms|bzrcdn\.openai\.com#i', $m[1].$m[2])) {
                return $m[0];
            }
            $count++;
            $attrs = preg_replace('#\s(type|defer|async)(=("[^"]*"|\'[^\']*\'|\S+))?#i', '', $m[1]);

            return '<script type="text/mx-later"'.$attrs.'>'.$m[2].'</script>';
        }, $html);
        if ($count === 0) {
            return $html;
        }
        $loader = '<script>(function(){var d=!1;function r(){if(d)return;d=!0;document.querySelectorAll(\'script[type="text/mx-later"]\').forEach(function(o){var s=document.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type")s.setAttribute(a.name,a.value)}s.text=o.text;o.parentNode.replaceChild(s,o)})}["pointerdown","keydown","scroll","touchstart"].forEach(function(e){addEventListener(e,r,{once:!0,passive:!0})});addEventListener("load",function(){setTimeout(r,2500)})})();</script>';

        return str_replace('</body>', $loader.'</body>', $html);
    });
});
