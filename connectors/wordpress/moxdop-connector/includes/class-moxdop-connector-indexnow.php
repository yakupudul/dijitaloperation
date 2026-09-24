<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.4.1: tells Bing / Yandex (IndexNow) about changed public URLs of THIS site.
 *
 * The site itself sends the notice (like SEO plugins do); MoxDOP never pings search engines on its behalf.
 * The key file is served at /{key}.txt. Off when the site admin unticks it or the site discourages search engines.
 */
final class MoxDOP_Connector_IndexNow
{
    const QUEUE = 'moxdop_connector_indexnow_queue';

    const KEY = 'moxdop_connector_indexnow_key';

    const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public static function enabled()
    {
        return (bool) apply_filters('moxdop_connector_indexnow', get_option('moxdop_connector_indexnow', '1') === '1')
            && (string) get_option('blog_public', '1') === '1';
    }

    public static function key()
    {
        $key = (string) get_option(self::KEY, '');
        if (! preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            update_option(self::KEY, $key, false);
        }

        return $key;
    }

    public function register()
    {
        add_action('parse_request', [$this, 'serve_key'], 0);
    }

    /** Answers /{key}.txt with the key (IndexNow ownership proof). */
    public function serve_key()
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $key = (string) get_option(self::KEY, '');
        if ($key === '' || $path !== '/'.$key.'.txt') {
            return;
        }
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($key);
        exit;
    }

    /** Remembers a changed public URL; sent with the next event delivery. */
    public static function queue($url)
    {
        if (! self::enabled() || ! is_string($url) || $url === '') {
            return;
        }
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (wp_parse_url($url, PHP_URL_HOST) !== $home) {
            return;
        }
        $queue = get_option(self::QUEUE, []);
        $queue = is_array($queue) ? $queue : [];
        if (! in_array($url, $queue, true)) {
            $queue[] = substr($url, 0, 2048);
            update_option(self::QUEUE, array_slice($queue, -500), false);
        }
    }

    /** Sends queued URLs (max 500 per call). Never blocks a page save: runs from WP-Cron. */
    public static function flush()
    {
        $queue = get_option(self::QUEUE, []);
        if (! is_array($queue) || $queue === []) {
            return;
        }
        delete_option(self::QUEUE);
        if (! self::enabled()) {
            return;
        }
        $key = self::key();
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $response = wp_safe_remote_post(self::ENDPOINT, [
            'timeout' => 10, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode(['host' => $host, 'key' => $key, 'keyLocation' => home_url('/'.$key.'.txt'), 'urlList' => array_values($queue)]),
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        update_option('moxdop_connector_indexnow_last', ['at' => gmdate('c'), 'code' => $code, 'urls' => count($queue)], false);
    }

    public static function status()
    {
        $last = get_option('moxdop_connector_indexnow_last');

        return ['enabled' => self::enabled(), 'last' => is_array($last) ? $last : null];
    }
}
