<?php

defined('ABSPATH') || exit;

/**
 * 1.37.0 "Hız turu" (yakup 2026-11-05): the speed recipe first written by hand for burcinoncul.com.tr (mobile
 * Lighthouse 45 → 80-89) as one switchable set for every MoxDOP site. It never changes the design, content, theme or
 * plugin files; it only trims what the visitor downloads. MoxDOP turns it on as a site fix (`speed`, logged and
 * undoable); the option holds the list of switched-on parts, empty = everything off.
 *
 * Parts:
 * - head_clean     emoji script/styles, RSD / WLW / generator tags
 * - no_migrate     jQuery Migrate is not sent to visitors
 * - editor_assets  BetterDocs' Elementor-editor files are not sent to visitors
 * - hero_eager     the first images of the page are not lazy-loaded (lazy loading delayed the largest image)
 * - webp           uploaded JPG / PNG files are served as a WebP copy made next to them (originals untouched;
 *                  skipped where an image plugin or LiteSpeed already serves WebP)
 * - late_tags      Google Tag Manager / gtag.js and Microsoft Clarity start after the page loaded (+1.5 s) or on the
 *                  first tap / scroll; consent-blocked scripts and the Meta pixel are left alone
 * - docs_cap       BetterDocs single Q&A pages list at most 10 questions per category in their sidebar
 * - local_fonts    Elementor serves its Google Fonts from the site itself
 */
final class MoxDOP_Connector_Speed
{
    const OPTION = 'moxdop_connector_speed';

    const PARTS = ['head_clean', 'no_migrate', 'editor_assets', 'hero_eager', 'webp', 'late_tags', 'docs_cap', 'local_fonts'];

    /** Images at the top of the page that keep eager loading. */
    const EAGER_IMAGES = 4;

    /** Plugins that already serve WebP themselves (the webp part then stays idle). */
    const WEBP_PLUGINS = ['webp-converter-for-media', 'ewww-image-optimizer', 'imagify', 'shortpixel-image-optimiser', 'optimole-wp', 'webp-express', 'wp-smushit', 'smush-pro', 'converter-for-media'];

    /** @var array<string, true>|null */
    private static $on = null;

    /** @var string[] files still waiting for their WebP copy */
    private $webp_todo = [];

    /** @return string[] the switched-on parts */
    public static function parts()
    {
        $raw = (string) get_option(self::OPTION, '');

        return array_values(array_intersect(self::PARTS, array_map('trim', explode(',', $raw))));
    }

    /** Normalises a fix value (list or comma string; "all" = every part) to the stored comma string. */
    public static function normalise($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        $value = array_map(static function ($part) {
            return sanitize_key((string) $part);
        }, (array) $value);
        if (in_array('all', $value, true)) {
            return implode(',', self::PARTS);
        }

        return implode(',', array_values(array_intersect(self::PARTS, $value)));
    }

    private static function on($part)
    {
        if (self::$on === null) {
            self::$on = array_fill_keys(self::parts(), true);
        }

        return isset(self::$on[$part]);
    }

    /** Called after the option changed (fix or undo). */
    public static function reset()
    {
        self::$on = null;
    }

    /** Summary for /status and site_check. */
    public static function summary()
    {
        return ['parts' => self::parts(), 'webp_by_other' => self::webp_by_other(), 'webp_supported' => function_exists('imagewebp')];
    }

    public function register()
    {
        if (get_option(self::OPTION, '') === '') {
            return;
        }
        add_action('init', [$this, 'head_clean']);
        add_action('wp_default_scripts', [$this, 'no_migrate']);
        add_action('wp_enqueue_scripts', [$this, 'editor_assets'], 9999);
        add_action('template_redirect', [$this, 'buffer'], 1);
        add_action('pre_get_posts', [$this, 'docs_cap']);
        add_filter('pre_option_elementor_local_google_fonts', [$this, 'local_fonts']);
        add_action('shutdown', [$this, 'make_webp'], 999);
    }

    /** A page a visitor sees (no admin, editor preview, REST, feed or login). */
    private static function front()
    {
        return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! is_feed() && ! (defined('REST_REQUEST') && REST_REQUEST)
            && ! isset($_GET['elementor-preview']) && ! isset($_GET['fl_builder']) && ! isset($_GET['et_fb']) && ! is_customize_preview()
            && ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php';
    }

    public function head_clean()
    {
        if (! self::on('head_clean')) {
            return;
        }
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
        add_filter('emoji_svg_url', '__return_false');
    }

    public function no_migrate($scripts)
    {
        if (! self::on('no_migrate') || is_admin() || ! isset($scripts->registered['jquery'])) {
            return;
        }
        $scripts->registered['jquery']->deps = array_values(array_diff($scripts->registered['jquery']->deps, ['jquery-migrate']));
    }

    public function editor_assets()
    {
        if (! self::on('editor_assets') || ! self::front()) {
            return;
        }
        wp_dequeue_style('betterdocs-elementor-editor');
        wp_dequeue_script('betterdocs-elementor-editor');
    }

    public function local_fonts($value)
    {
        return self::on('local_fonts') ? '1' : $value;
    }

    public function docs_cap($query)
    {
        if (! self::on('docs_cap') || is_admin() || $query->is_main_query() || ! is_singular('docs')) {
            return;
        }
        if (! in_array('docs', (array) $query->get('post_type'), true)) {
            return;
        }
        $n = (int) $query->get('posts_per_page');
        if ($n === -1 || $n > 10 || $query->get('nopaging')) {
            $query->set('nopaging', false);
            $query->set('posts_per_page', 10);
        }
    }

    public function buffer()
    {
        if (! self::front() || ! (self::on('hero_eager') || self::on('webp') || self::on('late_tags'))) {
            return;
        }
        ob_start([$this, 'filter_html']);
    }

    /** @param string $html */
    public function filter_html($html)
    {
        if (! is_string($html) || stripos($html, '<html') === false || stripos($html, '</body>') === false) {
            return $html;
        }
        if (self::on('hero_eager')) {
            $html = self::eager($html);
        }
        if (self::on('webp') && function_exists('imagewebp') && ! self::webp_by_other()) {
            $html = $this->webp_swap($html);
        }
        if (self::on('late_tags')) {
            $html = self::late_tags($html);
        }

        return $html;
    }

    /** The first images after <body> load at once; lazy loading them delayed the largest image of the page. */
    public static function eager($html)
    {
        $body = stripos($html, '<body');
        if ($body === false) {
            return $html;
        }
        $count = 0;
        $tail = (string) preg_replace_callback('~<img\b[^>]*>~i', static function (array $m) use (&$count) {
            $count++;
            if ($count > self::EAGER_IMAGES) {
                return $m[0];
            }

            return (string) preg_replace('~\sloading=(["\']?)lazy\1~i', '', $m[0]);
        }, substr($html, $body), self::EAGER_IMAGES);

        return substr($html, 0, $body).$tail;
    }

    /** Google Tag Manager / gtag.js and Clarity start after load or the first interaction. */
    public static function late_tags($html)
    {
        $count = 0;
        $html = (string) preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', static function (array $m) use (&$count) {
            $attrs = $m[1];
            if (preg_match('#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $type) && ! in_array(strtolower($type[1]), ['text/javascript', 'application/javascript'], true)) {
                return $m[0];
            }
            if (! preg_match('#googletagmanager\.com|clarity\.ms#i', $attrs.$m[2])) {
                return $m[0];
            }
            $count++;
            $attrs = (string) preg_replace('#\s(type|defer|async)(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?#i', '', $attrs);

            return '<script type="text/mx-later"'.$attrs.'>'.$m[2].'</script>';
        }, $html);
        if ($count === 0) {
            return $html;
        }
        $loader = '<script>(function(){var d=!1;function r(){if(d)return;d=!0;document.querySelectorAll(\'script[type="text/mx-later"]\').forEach(function(o){var s=document.createElement("script");for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name!=="type")s.setAttribute(a.name,a.value)}s.text=o.text;o.parentNode.replaceChild(s,o)})}["click","keydown","touchstart","scroll"].forEach(function(e){addEventListener(e,r,{once:!0,passive:!0})});addEventListener("load",function(){setTimeout(function(){(window.requestIdleCallback||setTimeout)(r,{timeout:2000})},1500)})})();</script>';
        $at = strripos($html, '</body>');

        return substr($html, 0, $at).$loader.substr($html, $at);
    }

    public static function webp_by_other()
    {
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        foreach ($active as $file) {
            if (in_array(dirname((string) $file), self::WEBP_PLUGINS, true)) {
                return true;
            }
        }
        if (defined('LSCWP_V')) {
            $conf = get_option('litespeed.conf.img_optm-webp');
            if (! empty($conf)) {
                return true;
            }
        }

        return false;
    }

    private function webp_swap($html)
    {
        $up = wp_get_upload_dir();
        $baseurl = (string) $up['baseurl'];
        $basedir = (string) $up['basedir'];
        if ($baseurl === '' || $basedir === '') {
            return $html;
        }
        $missing = [];
        $variants = array_unique([$baseurl, set_url_scheme($baseurl, 'http'), set_url_scheme($baseurl, 'https')]);
        $pattern = '~('.implode('|', array_map(static function ($u) {
            return preg_quote($u, '~');
        }, $variants)).')(/[^"\'\s,)]+?\.(?:png|jpe?g))(?=["\'\s,)]|&quot;)~i';
        $html = (string) preg_replace_callback($pattern, static function (array $m) use ($basedir, &$missing) {
            $relative = rawurldecode($m[2]);
            if (strpos($relative, '..') !== false) {
                return $m[0];
            }
            $file = $basedir.$relative;
            if (is_file($file.'.webp')) {
                return $m[1].$m[2].'.webp';
            }
            if (is_file($file) && ! is_file($file.'.webp.skip')) {
                $missing[$file] = true;
            }

            return $m[0];
        }, $html);
        $this->webp_todo = array_keys($missing);

        return $html;
    }

    /** WebP copies are made after the page was sent, a few per request; the page's cache is renewed afterwards. */
    public function make_webp()
    {
        if (! $this->webp_todo || get_transient('moxdop_speed_webp_lock')) {
            return;
        }
        set_transient('moxdop_speed_webp_lock', 1, 30);
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $start = microtime(true);
        $made = 0;
        foreach ($this->webp_todo as $file) {
            if (microtime(true) - $start > 8) {
                break;
            }
            if (! is_file($file.'.webp') && ! is_file($file.'.webp.skip')) {
                self::webp_make($file);
                $made++;
            }
        }
        delete_transient('moxdop_speed_webp_lock');
        if ($made) {
            $url = home_url((string) ($_SERVER['REQUEST_URI'] ?? '/'));
            do_action('litespeed_purge_url', $url);
        }
    }

    public static function webp_make($file)
    {
        $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (filesize($file) > 15 * 1024 * 1024) {
            @touch($file.'.webp.skip');

            return;
        }
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
        if (@imagewebp($img, $tmp, $type === 'png' ? 82 : 78) && filesize($tmp) > 0 && filesize($tmp) < filesize($file)) {
            @rename($tmp, $file.'.webp');
        } else {
            @unlink($tmp);
            @touch($file.'.webp.skip');
        }
        imagedestroy($img);
    }
}
