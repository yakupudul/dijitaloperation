<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.6.0: exports the HTML a page-cache plugin already stored on disk, so MoxDOP reads the site's pages
 * without making WordPress render them. Files are only read, never written; nothing is rendered.
 *
 * Supported file layouts (the first readable one wins):
 * - WP Rocket:          wp-content/cache/wp-rocket/{host}{path}/index-https.html | index.html (+ _gzip)
 * - WP Super Cache:     wp-content/cache/supercache/{host}{path}/index-https.html | index.html (+ .gz)
 * - W3 Total Cache:     wp-content/cache/page_enhanced/{host}{path}/_index_ssl.html | _index.html (+ _gzip)
 * - WP Fastest Cache:   wp-content/cache/all{path}/index.html
 * - Cache Enabler:      wp-content/cache/cache-enabler/{host}{path}/https-index.html | index.html (+ .gz)
 * LiteSpeed Cache keeps its pages in the web server's own cache (not readable by PHP): reported as unsupported.
 */
final class MoxDOP_Connector_Page_Cache
{
    /** Largest single cached page read (bytes, uncompressed). */
    const MAX_PAGE_BYTES = 3145728;

    /** Response budget: compressed + base64 HTML of one export page. */
    const MAX_RESPONSE_BYTES = 4194304;

    /** @return array<string, string> plugin id => cache directory relative to wp-content */
    public static function layouts()
    {
        return [
            'wp_rocket' => 'cache/wp-rocket',
            'wp_super_cache' => 'cache/supercache',
            'w3_total_cache' => 'cache/page_enhanced',
            'wp_fastest_cache' => 'cache/all',
            'cache_enabler' => 'cache/cache-enabler',
        ];
    }

    /**
     * The active page-cache plugin and whether its files can be read.
     *
     * @return array{plugin: ?string, readable: bool, reason: ?string, dir?: string}
     */
    public static function detect()
    {
        $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH.'wp-content';
        $active = [
            'wp_rocket' => defined('WP_ROCKET_VERSION'),
            'wp_super_cache' => defined('WPCACHEHOME') || function_exists('wp_cache_phase2'),
            'w3_total_cache' => defined('W3TC'),
            'wp_fastest_cache' => class_exists('WpFastestCache'),
            'cache_enabler' => class_exists('Cache_Enabler'),
        ];
        foreach (self::layouts() as $plugin => $relative) {
            if (empty($active[$plugin])) {
                continue;
            }
            $dir = rtrim($content_dir, '/').'/'.$relative;
            if (is_dir($dir) && is_readable($dir)) {
                return ['plugin' => $plugin, 'readable' => true, 'reason' => null, 'dir' => $dir];
            }

            return ['plugin' => $plugin, 'readable' => false, 'reason' => 'cache_dir_missing'];
        }
        if (defined('LSCWP_V')) {
            return ['plugin' => 'litespeed', 'readable' => false, 'reason' => 'server_cache'];
        }

        return ['plugin' => null, 'readable' => false, 'reason' => 'no_cache_plugin'];
    }

    /** Summary for /status (no server paths). */
    public static function summary()
    {
        $detected = self::detect();
        unset($detected['dir']);

        return $detected;
    }

    /**
     * Candidate cache files of one public URL, most specific first. Pure: no WordPress calls, so it can be tested.
     * Returns [] for URLs cache plugins do not store (query strings, "..", unusual characters).
     *
     * @return list<string>
     */
    public static function candidate_paths($plugin, $base_dir, $url)
    {
        $parts = parse_url((string) $url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['query']) || isset($parts['user']) || isset($parts['pass'])) {
            return [];
        }
        $host = strtolower((string) $parts['host']);
        if (! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return [];
        }
        $https = strtolower((string) ($parts['scheme'] ?? 'https')) === 'https';
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || strpos($segment, "\0") !== false || strpos($segment, '\\') !== false) {
                return [];
            }
        }
        $path = $segments === [] ? '' : '/'.implode('/', $segments);
        $base = rtrim((string) $base_dir, '/');

        switch ($plugin) {
            case 'wp_rocket':
                $dir = $base.'/'.$host.$path;
                $files = $https ? ['index-https.html', 'index-https.html_gzip'] : ['index.html', 'index.html_gzip'];
                break;
            case 'wp_super_cache':
                $dir = $base.'/'.$host.$path;
                $files = $https ? ['index-https.html', 'index-https.html.gz', 'index.html', 'index.html.gz'] : ['index.html', 'index.html.gz'];
                break;
            case 'w3_total_cache':
                $dir = $base.'/'.$host.$path;
                $files = $https ? ['_index_ssl.html', '_index_ssl.html_gzip'] : ['_index.html', '_index.html_gzip'];
                break;
            case 'wp_fastest_cache':
                $dir = $base.$path;
                $files = ['index.html'];
                break;
            case 'cache_enabler':
                $dir = $base.'/'.$host.$path;
                $files = $https ? ['https-index.html', 'https-index.html.gz'] : ['http-index.html', 'http-index.html.gz', 'index.html'];
                break;
            default:
                return [];
        }

        $paths = [];
        foreach ($files as $file) {
            $paths[] = $dir.'/'.$file;
        }

        return $paths;
    }

    /**
     * The cached HTML of one URL, or null when no readable cache file exists (the file must stay inside $base_dir).
     *
     * @return array{html: string, file_mtime: int}|null
     */
    public static function read_cached($plugin, $base_dir, $url)
    {
        $base = realpath((string) $base_dir);
        if ($base === false) {
            return null;
        }
        foreach (self::candidate_paths($plugin, $base, $url) as $candidate) {
            if (! is_file($candidate) || ! is_readable($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || strpos($real, $base.DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }
            $size = filesize($real);
            if ($size === false || $size < 1 || $size > self::MAX_PAGE_BYTES) {
                continue;
            }
            $raw = file_get_contents($real);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            if (substr($real, -3) === '.gz' || substr($real, -5) === '_gzip') {
                $raw = function_exists('gzdecode') ? @gzdecode($raw) : false;
                if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_PAGE_BYTES) {
                    continue;
                }
            }
            if (stripos($raw, '<html') === false) {
                continue;
            }

            return ['html' => $raw, 'file_mtime' => (int) filemtime($real)];
        }

        return null;
    }

    /**
     * Published, public, password-less URLs in ID order: one paginated query of ID / type / slug / parent / dates,
     * then get_permalink() on partial post objects (no post content is loaded). Page 1 starts with the home URL.
     *
     * @return array{urls: list<string>, total: int}
     */
    public static function public_urls($page, $per_page)
    {
        global $wpdb;
        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment'], $types['wp_block'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation'], $types['elementor_library']);
        $types = array_values($types);
        if ($types === []) {
            return ['urls' => [], 'total' => 0];
        }
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $where = "post_status = 'publish' AND post_password = '' AND post_type IN ($placeholders)";
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where", $types));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type, post_name, post_parent, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_author FROM {$wpdb->posts} WHERE $where ORDER BY ID ASC LIMIT %d OFFSET %d",
            array_merge($types, [(int) $per_page, ((int) $page - 1) * (int) $per_page])
        ));
        $urls = (int) $page === 1 ? [home_url('/')] : [];
        foreach ((array) $rows as $row) {
            $row->filter = 'raw';
            $row->post_content = '';
            $row->post_title = '';
            $link = get_permalink(new WP_Post($row));
            if (is_string($link) && $link !== '') {
                $urls[] = $link;
            }
        }

        return ['urls' => array_values(array_unique($urls)), 'total' => $total];
    }

    /**
     * One export page: every URL with its cached HTML (gzip + base64) or status not_cached. Pages that would push
     * the response past MAX_RESPONSE_BYTES come back as skipped_size (MoxDOP reads them over HTTP).
     */
    public static function export($page, $per_page)
    {
        $detected = self::detect();
        $summary = $detected;
        unset($summary['dir']);
        if (! $detected['readable']) {
            return ['cache' => $summary, 'records' => [], 'page' => (int) $page, 'per_page' => (int) $per_page, 'total' => 0, 'has_more' => false];
        }
        $list = self::public_urls($page, $per_page);
        $records = [];
        $budget = self::MAX_RESPONSE_BYTES;
        foreach ($list['urls'] as $url) {
            $cached = self::read_cached($detected['plugin'], $detected['dir'], $url);
            if ($cached === null) {
                $records[] = ['url' => $url, 'status' => 'not_cached', 'cache_plugin' => $detected['plugin']];

                continue;
            }
            $encoded = base64_encode((string) gzencode($cached['html'], 6));
            if (strlen($encoded) > $budget) {
                $records[] = ['url' => $url, 'status' => 'skipped_size', 'cache_plugin' => $detected['plugin']];

                continue;
            }
            $budget -= strlen($encoded);
            $records[] = [
                'url' => $url,
                'status' => 'cached',
                'cache_plugin' => $detected['plugin'],
                'file_mtime' => gmdate('c', $cached['file_mtime']),
                'sha256' => hash('sha256', $cached['html']),
                'bytes' => strlen($cached['html']),
                'html_gz_b64' => $encoded,
            ];
        }

        return [
            'cache' => $summary,
            'records' => $records,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'total' => (int) $list['total'],
            'has_more' => (int) $page * (int) $per_page < (int) $list['total'],
        ];
    }
}
