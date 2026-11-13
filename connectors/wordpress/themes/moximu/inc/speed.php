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
    foreach (['inter-latin', 'inter-tr'] as $font) {
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
    // wp-login.php is not is_admin(); its own styles and scripts must stay or the login form loses its CSS and JS.
    if (is_admin() || did_action('login_init') || ($GLOBALS['pagenow'] ?? '') === 'wp-login.php' || isset($_GET['elementor-preview']) || is_customize_preview()) {
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
 * Tag Manager, Meta Pixel and Clarity stay, but start when the browser is idle about 4 s after load (or at the first click /
 * key press). Never at the first touch or scroll, so their script work never lands inside a swipe. Every tag still fires.
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
        // Scripts marked data-consent (Clarity, Meta pixel) wait for the cookie banner's "Kabul et".
        $loader = '<script>(function(){var d=!1;function run(all){document.querySelectorAll(\'script[type="text/mx-later"]\').forEach(function(o){if(o.hasAttribute("data-consent")&&!all)return;var s=document.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type")s.setAttribute(a.name,a.value)}s.text=o.text;o.parentNode.replaceChild(s,o)})}function r(){if(d)return;d=!0;run(/(?:^|; )mx_consent=all/.test(document.cookie))}window.mxConsentRun=function(){if(d)run(!0)};["click","keydown"].forEach(function(e){addEventListener(e,r,{once:!0,passive:!0})});addEventListener("load",function(){setTimeout(function(){(window.requestIdleCallback||setTimeout)(r,{timeout:4000})},4000)})})();</script>';

        return str_replace('</body>', $loader.'</body>', $html);
    });
});

/*
 * Mobile speed (yakup 2026-11-02 "olabilecek en iyi hıza çıkar"): every image after the first few in the page (header
 * logo and hero) loads lazily and decodes off the main thread. Images that already say how to load are left alone.
 */
function mx_lazy_images(string $html): string
{
    $seen = 0;

    return (string) preg_replace_callback('~<img\b[^>]*>~i', static function (array $m) use (&$seen): string {
        $tag = $m[0];
        $seen++;
        if (stripos($tag, ' decoding=') === false) {
            $tag = substr($tag, 0, 4).' decoding="async"'.substr($tag, 4);
        }
        if ($seen <= 8 || stripos($tag, ' loading=') !== false || stripos($tag, 'fetchpriority') !== false) {
            return $tag;
        }

        return substr($tag, 0, 4).' loading="lazy"'.substr($tag, 4);
    }, $html);
}

add_action('template_redirect', static function (): void {
    if (is_admin() || wp_doing_ajax() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || isset($_GET['elementor-preview'])) {
        return;
    }
    ob_start(static fn (string $html): string => stripos($html, '<html') !== false ? mx_webp_swap(mx_lazy_images($html)) : $html);
}, 1);

/*
 * WebP for uploaded JPG/PNG files: next to each upload a "<file>.webp" copy is made with GD (a few per request, after
 * the page is sent), and pages point at the copy once it exists. Without GD WebP support nothing changes.
 */
function mx_webp_dirs(): array
{
    static $dirs = null;
    if ($dirs === null) {
        $up = wp_get_upload_dir();
        $dirs = [(string) $up['baseurl'], (string) $up['basedir']];
    }

    return $dirs;
}

function mx_webp_swap(string $html): string
{
    if (! function_exists('imagewebp')) {
        return $html;
    }
    [$baseurl, $basedir] = mx_webp_dirs();
    $missing = [];
    // <meta> tags (og:image, twitter:image) keep the JPG/PNG: LinkedIn and some other link previews do not read WebP and
    // fall back to a small thumbnail (yakup 2026-11-13).
    $html = (string) preg_replace_callback('~<meta\b[^>]*>|'.preg_quote($baseurl, '~').'(/[^"\'\s,)]+?\.(?:png|jpe?g))(?=["\'\s,)])~i', static function (array $m) use ($baseurl, $basedir, &$missing): string {
        if (! isset($m[1]) || $m[1] === '') {
            return $m[0];
        }
        $file = $basedir.$m[1];
        if (is_file($file.'.webp')) {
            return $baseurl.$m[1].'.webp';
        }
        if (is_file($file)) {
            $missing[$file] = true;
        }

        return $m[0];
    }, $html);
    if ($missing) {
        $GLOBALS['mx_webp_todo'] = array_keys($missing);
    }

    return $html;
}

function mx_webp_make(string $file): void
{
    $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $img = $type === 'png' ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
    if (! $img) {
        @touch($file.'.webp.skip');

        return;
    }
    if ($type === 'png') {
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    $tmp = $file.'.webp.tmp';
    if (@imagewebp($img, $tmp, $type === 'png' ? 85 : 78) && filesize($tmp) > 0 && filesize($tmp) < filesize($file)) {
        @rename($tmp, $file.'.webp');
    } else {
        @unlink($tmp);
        @touch($file.'.webp.skip');
    }
    imagedestroy($img);
}

add_action('shutdown', static function (): void {
    $todo = $GLOBALS['mx_webp_todo'] ?? [];
    if (! $todo || get_transient('mx_webp_lock')) {
        return;
    }
    set_transient('mx_webp_lock', 1, 30);
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $start = microtime(true);
    foreach ($todo as $file) {
        if (microtime(true) - $start > 8) {
            break;
        }
        if (! is_file($file.'.webp') && ! is_file($file.'.webp.skip')) {
            mx_webp_make($file);
        }
    }
    delete_transient('mx_webp_lock');
}, 999);

/*
 * Sitemaps: Chrome shows a red warning bar on XML files that use an XSL stylesheet (browsers are dropping XSLT).
 * Search engines never read the stylesheet, so the sitemap is served as plain XML.
 */
add_action('init', static function (): void {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (! preg_match('#sitemaps?[a-z0-9_-]*\.xml(?:\?|$)#i', $uri)) {
        return;
    }
    ob_start(static fn (string $xml): string => (string) preg_replace('#<\?xml-stylesheet[^>]*\?>\s*#i', '', $xml));
}, 0);
