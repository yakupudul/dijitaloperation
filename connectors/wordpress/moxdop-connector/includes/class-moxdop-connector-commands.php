<?php

defined('ABSPATH') || exit;

/**
 * 1.13.0 "site işi kendisi alır": for a host that refuses every request from the MoxDOP server (seen on Avrupadent,
 * IPv4 and IPv6, even the home page), the site asks MoxDOP for its work instead. Each command is a request MoxDOP
 * signed for one of the connector's own REST routes; it runs here through rest_do_request (the same permission check
 * and the same signed answer as over HTTP) and the answer goes back with the next ask. Every 15 minutes the site asks
 * once (one small request); once MoxDOP says it sends work this way, it asks every minute and keeps asking for up to
 * 25 seconds while work keeps coming.
 *
 * 1.16.0 (Avrupadent 2026-10-14: WP-Cron rarely ran, work waited for minutes and timed out): WP-Cron only runs when
 * PHP renders a page, and a page cache serves most visits without PHP. While work comes this way (or when WP-Cron has
 * not run the ask for 30 minutes) every page carries a tiny beacon: the visitor's browser posts to admin-ajax at most
 * once a minute (POST is never page-cached), the site answers at once and asks MoxDOP after the answer, at most once
 * a minute for the whole site. Each ask tells MoxDOP how it was started and the last failure the site saw.
 */
final class MoxDOP_Connector_Commands
{
    const HOOK = 'moxdop_connector_commands';

    const SCHEDULE = 'moxdop_minute';

    /** '1' while MoxDOP sends this site's work this way (autoloaded, read on init). */
    const MODE_OPTION = 'moxdop_connector_pull_mode';

    const SEEN_OPTION = 'moxdop_connector_pull_seen_at';

    const RUN_SECONDS = 25;

    /** 1.16.0: when the site last started an ask (cron or visit), unix time. */
    const ATTEMPT_OPTION = 'moxdop_connector_pull_attempt_at';

    /** 1.16.0: the last failed ask (reason + time), sent with the next ask that gets through. */
    const FAILURE_OPTION = 'moxdop_connector_pull_failure';

    const TICK_ACTION = 'moxdop_tick';

    /** Seconds between two asks started by visits while MoxDOP sends work this way. */
    const VISIT_GAP = 55;

    /** Without pull mode, visits ask only when WP-Cron has not asked for this long. */
    const IDLE_GAP = 1800;

    /** How the current ask started: cron or visit. */
    private $trigger = 'cron';

    const PATH = '/api/connectors/wordpress/commands';

    public function register()
    {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::HOOK, [$this, 'run']);
        add_action(MoxDOP_Connector_Events::HOOK, [$this, 'run'], 20);
        add_action('init', [$this, 'schedule'], 20);
        add_action('wp_ajax_nopriv_'.self::TICK_ACTION, [$this, 'tick']);
        add_action('wp_ajax_'.self::TICK_ACTION, [$this, 'tick']);
        add_action('wp_footer', [$this, 'beacon'], 99);
        add_action('admin_footer', [$this, 'beacon'], 99);
    }

    /** Whether a visit should start an ask now (WP-Cron is not keeping up). */
    private function due()
    {
        $gap = get_option(self::MODE_OPTION) === '1' ? self::VISIT_GAP : self::IDLE_GAP;

        return (int) get_option(self::ATTEMPT_OPTION, 0) < time() - $gap;
    }

    /** Prints the beacon while work comes this way or WP-Cron is not running the ask (paired sites only). */
    public function beacon()
    {
        if (get_option(self::MODE_OPTION) !== '1' && ! $this->due()) {
            return;
        }
        if (! get_option('moxdop_connector_app_url')) {
            return;
        }
        $url = wp_json_encode(admin_url('admin-ajax.php?action='.self::TICK_ACTION));
        echo "<script>(function(){try{var k='moxdop_tick',n=Date.now(),l=+localStorage.getItem(k)||0;if(n-l<60000)return;localStorage.setItem(k,String(n));}catch(e){}var u=".$url.";try{if(navigator.sendBeacon){navigator.sendBeacon(u);}else{fetch(u,{method:'POST',keepalive:true,credentials:'omit'});}}catch(e){}})();</script>\n";
    }

    /** The beacon's request: answered at once; the ask runs after the answer when one is due. */
    public function tick()
    {
        $due = $this->due();
        nocache_headers();
        status_header(204);
        if (! $due) {
            exit;
        }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $this->trigger = 'visit';
        $this->run();
        exit;
    }

    public function schedules($schedules)
    {
        $schedules[self::SCHEDULE] = ['interval' => 60, 'display' => 'MoxDOP / 1 minute'];

        return $schedules;
    }

    public function schedule()
    {
        $on = get_option(self::MODE_OPTION) === '1';
        $next = wp_next_scheduled(self::HOOK);
        if ($on && ! $next) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
        } elseif (! $on && $next) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function run()
    {
        $lock = 'moxdop_connector_commands_lock';
        if (! MoxDOP_Connector_Lock::acquire($lock, 90)) {
            return;
        }
        update_option(self::ATTEMPT_OPTION, time(), true);
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(90);
            }
            $deadline = time() + self::RUN_SECONDS;
            $results = [];
            do {
                $commands = $this->exchange($results, true);
                $results = [];
                if ($commands === null) {
                    return;
                }
                foreach ($commands as $command) {
                    $results[] = $this->execute($command);
                }
            } while ($results !== [] && time() < $deadline);
            if ($results !== []) {
                $this->exchange($results, false);
            }
        } finally {
            MoxDOP_Connector_Lock::release($lock);
        }
    }

    /** @return array{id: int, status: int, body: string} */
    private function execute(array $command)
    {
        $id = (int) ($command['id'] ?? 0);
        $route = (string) ($command['route'] ?? '');
        $method = strtoupper((string) ($command['method'] ?? 'GET'));
        if (strpos($route, '/moxdop/v1/') !== 0 || ! in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            return ['id' => $id, 'status' => 400, 'body' => wp_json_encode(['code' => 'moxdop_invalid_command', 'message' => 'Only connector routes can run.'])];
        }
        $request = new WP_REST_Request($method, $route);
        $query = [];
        wp_parse_str((string) ($command['query'] ?? ''), $query);
        $request->set_query_params($query);
        foreach ((array) ($command['headers'] ?? []) as $name => $value) {
            $request->set_header((string) $name, (string) $value);
        }
        $body = (string) ($command['body'] ?? '');
        if ($body !== '') {
            $request->set_header('content-type', 'application/json');
            $request->set_body($body);
        }
        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);

        return ['id' => $id, 'status' => (int) $response->get_status(), 'body' => (string) wp_json_encode($data)];
    }

    /**
     * Brings the answers, gets the next commands (null when MoxDOP could not be reached or the answer is not signed).
     *
     * @return list<array<string, mixed>>|null
     */
    private function exchange(array $results, $take)
    {
        $credentials = (new MoxDOP_Connector_Secrets)->read();
        $app = (string) get_option('moxdop_connector_app_url');
        if (! is_array($credentials) || wp_parse_url($app, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $failure = get_option(self::FAILURE_OPTION);
        $body = wp_json_encode([
            'schema_version' => 1,
            'installation_id' => (string) get_option('moxdop_connector_installation_id'),
            'plugin_version' => MOXDOP_CONNECTOR_VERSION,
            'take' => (bool) $take,
            'results' => $results,
            'site' => [
                'trigger' => $this->trigger,
                'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'last_failure' => is_array($failure) ? substr((string) ($failure['reason'] ?? ''), 0, 200) : null,
                'last_failure_at' => is_array($failure) ? (string) ($failure['at'] ?? '') : null,
            ],
        ]);
        $timestamp = (string) time();
        $nonce = wp_generate_uuid4();
        $canonical = implode("\n", ['POST', self::PATH, '', $timestamp, $nonce, hash('sha256', $body)]);
        $response = wp_safe_remote_post(untrailingslashit($app).self::PATH, [
            'timeout' => 30, 'redirection' => 0, 'sslverify' => true,
            'headers' => [
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
                'X-MoxDOP-Client' => $credentials['client_id'],
                'X-MoxDOP-Installation' => (string) get_option('moxdop_connector_installation_id'),
                'X-MoxDOP-Timestamp' => $timestamp, 'X-MoxDOP-Nonce' => $nonce,
                'X-MoxDOP-Signature' => hash_hmac('sha256', $canonical, $credentials['shared_secret']),
            ],
            'body' => $body,
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->failed(is_wp_error($response) ? 'MoxDOP\'a ulaşılamadı: '.$response->get_error_message() : 'MoxDOP HTTP '.$code.' döndü');

            return null;
        }
        $decoded = $code === 200 ? json_decode(wp_remote_retrieve_body($response), true) : null;
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $meta = is_array($decoded) ? ($decoded['meta'] ?? []) : [];
        $expected = is_array($data) ? hash_hmac('sha256', implode("\n", [
            (string) ($meta['server_time'] ?? ''), $nonce,
            hash('sha256', MoxDOP_Connector_Canonical_JSON::encode($data)),
        ]), $credentials['shared_secret']) : '';
        if (! is_array($data) || ! is_array($data['commands'] ?? null) || abs(time() - (int) ($meta['server_time'] ?? 0)) > 300
            || ($meta['request_nonce'] ?? '') !== $nonce || ! hash_equals($expected, (string) ($meta['signature'] ?? ''))) {
            $this->failed(abs(time() - (int) ($meta['server_time'] ?? 0)) > 300 ? 'Sitenin saati MoxDOP\'tan farklı' : 'MoxDOP yanıtı doğrulanamadı');

            return null;
        }
        if (is_array($failure)) {
            delete_option(self::FAILURE_OPTION);
        }
        $mode = ! empty($data['pull']) ? '1' : '0';
        if (get_option(self::MODE_OPTION) !== $mode) {
            update_option(self::MODE_OPTION, $mode, true);
            $this->schedule();
        }
        update_option(self::SEEN_OPTION, gmdate('c'), false);

        return array_values(array_filter($data['commands'], 'is_array'));
    }

    private function failed($reason)
    {
        update_option(self::FAILURE_OPTION, ['reason' => (string) $reason, 'at' => gmdate('c')], false);
    }
}
