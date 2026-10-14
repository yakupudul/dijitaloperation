<?php

defined('ABSPATH') || exit;

/**
 * Small local outbox. No network requests run in content-save hooks.
 * 1.5.1 ("gentle mode" for small shared hosts): a save schedules ONE send about a minute later (saves within that
 * minute share it; no loopback request per save); the fallback schedule runs every 15 minutes and makes no request
 * at all while the outbox is empty, except a heartbeat when the last acknowledgement is older than 6 hours.
 * Setup (table, schedule) runs once per plugin version, never on every request.
 *
 * 1.17.0 (yakup 2026-10-14: "sitede paylaşım olduğunda yayına alındığı bilgisi sisteme anında iletilmeli"): the send
 * no longer waits for WP-Cron. After the response of the request that saved something (shutdown, never inside a save
 * hook) the site pokes its own admin-ajax without waiting for it (like WordPress starts its cron); that request answers
 * at once and sends the outbox, at most every 10 seconds. Where the host blocks such loopback requests, the next page
 * an admin or a visitor opens carries a tiny beacon doing the same; WP-Cron stays the fallback. An event about a post
 * MoxDOP made also carries the post's state (status, date, link), so MoxDOP knows it without reading the site.
 */
final class MoxDOP_Connector_Events
{
    const HOOK = 'moxdop_connector_send_events';

    const NOW_HOOK = 'moxdop_connector_send_events_now';

    const LIMIT = 10000;

    const SCHEDULE = 'moxdop_fifteen_minutes';

    /** Seconds between a save and the send it schedules. */
    const DEBOUNCE = 60;

    /** An empty outbox still reports in when the last acknowledgement is older than this. */
    const HEARTBEAT = 21600;

    /** Autoloaded: the plugin version whose setup already ran (no query on a normal page view). */
    const SETUP_OPTION = 'moxdop_connector_setup_version';

    const TRIM_OPTION = 'moxdop_connector_outbox_trimmed_at';

    /** 1.17.0: admin-ajax action that sends the outbox right away. */
    const SEND_ACTION = 'moxdop_events';

    /** 1.17.0 (autoloaded): unix time of the last save whose events are not yet acknowledged; cleared on an empty outbox. */
    const WAITING_OPTION = 'moxdop_connector_events_waiting_at';

    /** 1.17.0: unix time the last immediate send started. */
    const SENT_OPTION = 'moxdop_connector_events_sent_at';

    /** Seconds between two immediate sends; after a failed send, a minute. */
    const SEND_GAP = 10;

    const RETRY_GAP = 60;

    private $pending = [];

    private $paired = null;

    public function register()
    {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('init', [$this, 'install']);
        add_action(self::HOOK, [$this, 'send']);
        add_action(self::NOW_HOOK, [$this, 'send']);
        add_action('wp_after_insert_post', [$this, 'post_saved'], 10, 4);
        add_action('before_delete_post', [$this, 'post_deleted'], 10, 2);
        add_action('added_post_meta', [$this, 'meta_changed'], 10, 4);
        add_action('updated_post_meta', [$this, 'meta_changed'], 10, 4);
        add_action('deleted_post_meta', [$this, 'meta_changed'], 10, 4);
        add_action('updated_option', [$this, 'option_changed'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'upgraded'], 10, 2);
        add_action('activated_plugin', [$this, 'activated'], 10, 2);
        add_action('deactivated_plugin', [$this, 'deactivated'], 10, 2);
        add_action('switch_theme', [$this, 'theme_changed']);
        add_action('set_user_role', [$this, 'role_changed'], 10, 3);
        add_action('shutdown', [$this, 'persist'], 1);
        add_action('wp_ajax_nopriv_'.self::SEND_ACTION, [$this, 'send_now']);
        add_action('wp_ajax_'.self::SEND_ACTION, [$this, 'send_now']);
        add_action('wp_footer', [$this, 'beacon'], 99);
        add_action('admin_footer', [$this, 'beacon'], 99);
    }

    public function schedules($schedules)
    {
        $schedules[self::SCHEDULE] = ['interval' => 900, 'display' => 'MoxDOP / 15 minutes'];

        return $schedules;
    }

    /** Runs on init: after the one-time setup of this version it only reads the in-memory cron list. */
    public function install()
    {
        if (get_option(self::SETUP_OPTION) === MOXDOP_CONNECTOR_VERSION) {
            if (! wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 300, self::SCHEDULE, self::HOOK);
            }

            return;
        }
        $this->setup();
    }

    /** Once per plugin version (and again only if the outbox table could not be created, at most hourly). */
    private function setup()
    {
        global $wpdb;
        if (get_option('moxdop_connector_outbox_version') !== '1') {
            if (get_transient('moxdop_connector_setup_retry')) {
                return;
            }
            set_transient('moxdop_connector_setup_retry', 1, HOUR_IN_SECONDS);
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $table = $wpdb->prefix.'moxdop_outbox';
            dbDelta("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id varchar(36) NOT NULL,
                payload longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_id (event_id)
            ) ".$wpdb->get_charset_collate().';');
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                return;
            }
            update_option('moxdop_connector_outbox_version', '1', false);
            delete_transient('moxdop_connector_setup_retry');
        }
        // Up to 1.5.0 the fallback ran every 5 minutes: move existing installs to the 15-minute schedule.
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::HOOK) : false;
        if ($event && $event->schedule !== self::SCHEDULE) {
            wp_clear_scheduled_hook(self::HOOK);
        }
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, self::SCHEDULE, self::HOOK);
        }
        // Options read on every front-end page view are autoloaded, so they cost no extra database query.
        add_option(MoxDOP_Connector_Fixes::REDIRECTS_OPTION, [], '', 'yes');
        add_option(MoxDOP_Connector_Fixes::SITE_SCHEMA_OPTION, '', '', 'yes');
        if (function_exists('wp_set_option_autoload')) {
            wp_set_option_autoload(MoxDOP_Connector_Fixes::REDIRECTS_OPTION, true);
            wp_set_option_autoload(MoxDOP_Connector_Fixes::SITE_SCHEMA_OPTION, true);
        }
        update_option(self::SETUP_OPTION, MOXDOP_CONNECTOR_VERSION, true);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::NOW_HOOK);
        delete_option(self::SETUP_OPTION);
    }

    private function tracked($post)
    {
        if (! $post instanceof WP_Post || wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return false;
        }
        if (in_array($post->post_type, ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset'], true)) {
            return false;
        }
        $type = get_post_type_object($post->post_type);

        return $post->post_type === 'attachment' || ($type && $type->public);
    }

    public function post_saved($id, $post, $update, $before)
    {
        if (! $this->tracked($post) || $post->post_status === 'auto-draft') {
            return;
        }
        $fields = [];
        foreach (['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent'] as $field) {
            if (! $before || (string) $before->$field !== (string) $post->$field) {
                $fields[] = $field;
            }
        }
        if (! $fields) {
            return;
        }
        $action = ! $update ? 'created' : 'updated';
        if ($post->post_status === 'publish' && (! $before || $before->post_status !== 'publish')) {
            $action = 'published';
        } elseif ($post->post_status === 'trash') {
            $action = 'trashed';
        } elseif ($before && $before->post_status === 'publish' && $post->post_status !== 'publish') {
            $action = 'unpublished';
        }
        $this->record('content.'.$action, $post->post_type, (string) $id, $fields, $post);
        if ($post->post_status === 'publish' || ($before && $before->post_status === 'publish')) {
            MoxDOP_Connector_IndexNow::queue((string) get_permalink($post));
        }
    }

    public function post_deleted($id, $post)
    {
        if ($this->tracked($post)) {
            $this->record('content.deleted', $post->post_type, (string) $id, ['deleted'], $post);
        }
    }

    public function meta_changed($meta_id, $id, $key, $value)
    {
        $allowed = [
            '_seopress_titles_title', '_seopress_titles_desc', '_seopress_robots_canonical',
            '_seopress_robots_index', '_seopress_robots_follow', '_seopress_redirections_enabled',
            '_seopress_redirections_type', '_seopress_redirections_value',
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_canonical',
            '_yoast_wpseo_meta-robots-noindex', 'rank_math_title', 'rank_math_description',
            'rank_math_canonical_url', 'rank_math_robots', '_wp_attachment_image_alt',
            '_thumbnail_id', '_elementor_data', '_wp_page_template',
            'sube_adi', 'sube_telefon', 'sube_adresi', 'adres_posta_kodu', 'latitude', 'longitude',
        ];
        $post = get_post($id);
        if (in_array($key, $allowed, true) && $this->tracked($post)) {
            $seo = strpos($key, 'seopress') !== false || strpos($key, 'yoast') !== false || strpos($key, 'rank_math') === 0;
            $this->record($seo ? 'seo.updated' : 'content.fields_updated', $post->post_type, (string) $id, [$key], $post);
            if ($post->post_status === 'publish') {
                MoxDOP_Connector_IndexNow::queue((string) get_permalink($post));
            }
        }
    }

    public function option_changed($key, $old, $new)
    {
        if ($key === MoxDOP_Connector_Fixes::REDIRECTS_OPTION || in_array($key, ['blog_public', 'permalink_structure', 'home', 'siteurl', 'show_on_front', 'page_on_front', 'page_for_posts', 'default_role', 'users_can_register', 'seopress_titles_option_name', 'seopress_xml_sitemap_option_name', 'litespeed.conf'], true)) {
            $this->record('settings.updated', 'site', '0', [$key]);
        }
    }

    public function upgraded($upgrader, $options)
    {
        $type = sanitize_key($options['type'] ?? 'unknown');
        if (($options['action'] ?? '') !== 'update' || ! in_array($type, ['core', 'plugin', 'theme'], true)) {
            return;
        }
        $result = $upgrader->result ?? null;
        $state = is_wp_error($result) || $result === false ? 'failed' : 'completed';
        $this->record('maintenance.update_'.$state, $type, '0', ['version']);
    }

    public function activated($plugin, $network)
    {
        $this->record('maintenance.activated', 'plugin', (string) $plugin, ['active']);
    }

    public function deactivated($plugin, $network)
    {
        $this->record('maintenance.deactivated', 'plugin', (string) $plugin, ['active']);
    }

    public function theme_changed($name)
    {
        $this->record('maintenance.theme_changed', 'theme', get_stylesheet(), ['active']);
    }

    public function role_changed($id, $role, $old)
    {
        $this->record('access.role_changed', 'user', (string) $id, ['role']);
    }

    private function record($type, $object_type, $object_id, $fields, $post = null)
    {
        if ($this->paired === null) {
            $this->paired = get_option(MoxDOP_Connector_Secrets::OPTION) && get_option('moxdop_connector_outbox_version') === '1';
        }
        if (! $this->paired) {
            return;
        }
        $key = $type.'|'.$object_type.'|'.$object_id;
        if (isset($this->pending[$key])) {
            $this->pending[$key]['fields'] = array_values(array_unique(array_merge($this->pending[$key]['fields'], $fields)));

            return;
        }
        if (count($this->pending) >= 100) {
            update_option('moxdop_connector_events_gap_at', gmdate('c'), false);

            return;
        }
        $user = wp_get_current_user();
        // 1.17.0: a post MoxDOP made carries its state with the event (read when the event is stored, after the save).
        $moxdop = $post instanceof WP_Post && get_post_meta($post->ID, '_moxdop_created', true) === '1';
        $this->pending[$key] = [
            '_moxdop_post' => $moxdop ? (int) $post->ID : 0,
            'event_id' => wp_generate_uuid4(),
            'type' => $type,
            'occurred_at' => gmdate('c'),
            'object_type' => substr(sanitize_key($object_type), 0, 64),
            'object_id' => substr(sanitize_text_field($object_id), 0, 191),
            'title' => $post ? mb_substr(wp_strip_all_tags($post->post_title), 0, 200) : '',
            'url' => $post && $post->post_status === 'publish' ? substr((string) get_permalink($post), 0, 2048) : '',
            'actor_id' => (string) $user->ID,
            'actor_name' => $user->ID ? mb_substr(sanitize_text_field($user->display_name), 0, 100) : '',
            'origin' => $user->ID ? 'wordpress_user' : 'wordpress_automation',
            'fields' => $fields,
        ];
    }

    public function persist()
    {
        global $wpdb;
        if (! $this->pending) {
            return;
        }
        $table = $wpdb->prefix.'moxdop_outbox';
        foreach ($this->pending as $event) {
            if ($event['_moxdop_post'] > 0) {
                $event['post_state'] = MoxDOP_Connector_Posts::post_state($event['_moxdop_post']);
                if ($event['post_state']['status'] === 'missing') {
                    $event['post_state'] = ['post_id' => $event['_moxdop_post'], 'status' => 'missing'];
                }
            }
            unset($event['_moxdop_post']);
            if ($wpdb->insert($table, ['event_id' => $event['event_id'], 'payload' => wp_json_encode($event), 'created_at' => gmdate('Y-m-d H:i:s')]) === false) {
                update_option('moxdop_connector_events_gap_at', gmdate('c'), false);
            }
        }
        $this->pending = [];
        $this->send_soon();
        $this->kick();
        // The size cap is checked at most once an hour, not on every save.
        if ((int) get_option(self::TRIM_OPTION, 0) > time() - HOUR_IN_SECONDS) {
            return;
        }
        update_option(self::TRIM_OPTION, time(), true);
        $cutoff = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET ".self::LIMIT);
        if ($cutoff) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id <= %d", $cutoff));
            update_option('moxdop_connector_events_gap_at', gmdate('c'), false);
        }
    }

    /**
     * One send about a minute after a save; further saves within that minute share it. No loopback request here:
     * WordPress runs the due event on a later page view (or the host cron when DISABLE_WP_CRON is set).
     */
    public function send_soon()
    {
        if (! wp_next_scheduled(self::NOW_HOOK)) {
            wp_schedule_single_event(time() + self::DEBOUNCE, self::NOW_HOOK);
        }
    }

    /**
     * 1.17.0: starts the immediate send in a separate request and does not wait for it (0.01 s, the way WordPress
     * starts its cron). Runs on shutdown only, after the outbox rows were written.
     */
    private function kick()
    {
        update_option(self::WAITING_OPTION, time(), true);
        if (! $this->send_due() || (defined('DOING_AJAX') && DOING_AJAX && ($_REQUEST['action'] ?? '') === self::SEND_ACTION)) {
            return;
        }
        wp_remote_post(admin_url('admin-ajax.php?action='.self::SEND_ACTION), [
            'timeout' => 0.01, 'blocking' => false, 'redirection' => 0,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    /** Whether an immediate send may start now (one every 10 seconds; a minute after a failed send). */
    private function send_due()
    {
        $gap = (int) get_option('moxdop_connector_events_retry_at', 0) > time() ? self::RETRY_GAP : self::SEND_GAP;

        return (int) get_option(self::SENT_OPTION, 0) <= time() - $gap;
    }

    /** Prints the beacon while saved events wait for MoxDOP (the loopback request may be blocked on this host). */
    public function beacon()
    {
        $waiting = (int) get_option(self::WAITING_OPTION, 0);
        if ($waiting < 1 || $waiting < time() - DAY_IN_SECONDS || ! $this->send_due()) {
            return;
        }
        $url = wp_json_encode(admin_url('admin-ajax.php?action='.self::SEND_ACTION));
        echo "<script>(function(){try{var k='moxdop_events',n=Date.now(),l=+localStorage.getItem(k)||0;if(n-l<10000)return;localStorage.setItem(k,String(n));}catch(e){}var u=".$url.";try{if(navigator.sendBeacon){navigator.sendBeacon(u);}else{fetch(u,{method:'POST',keepalive:true,credentials:'omit'});}}catch(e){}})();</script>\n";
    }

    /** The loopback or beacon request: answered at once (204), the outbox goes to MoxDOP after the answer. */
    public function send_now()
    {
        $due = $this->send_due() && (int) get_option(self::WAITING_OPTION, 0) > 0;
        nocache_headers();
        status_header(204);
        if (! $due) {
            exit;
        }
        update_option(self::SENT_OPTION, time(), false);
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $this->send(true);
        exit;
    }

    public function status()
    {
        global $wpdb;
        $ready = get_option('moxdop_connector_outbox_version') === '1';

        return [
            'supported' => true,
            'storage_ready' => $ready,
            'pending' => $ready ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}moxdop_outbox") : null,
            'last_ack_at' => get_option('moxdop_connector_events_ack_at') ?: null,
            'last_error' => get_option('moxdop_connector_events_error') ?: null,
            'gap_at' => get_option('moxdop_connector_events_gap_at') ?: null,
            'next_attempt_at' => (int) get_option('moxdop_connector_events_retry_at', 0),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }

    /** @param bool $now 1.17.0: an immediate send after a save, which does not wait out the retry pause of a failed send */
    public function send($now = false)
    {
        global $wpdb;
        MoxDOP_Connector_IndexNow::flush();
        $credentials = (new MoxDOP_Connector_Secrets)->read();
        $app = (string) get_option('moxdop_connector_app_url');
        if (! is_array($credentials) || wp_parse_url($app, PHP_URL_SCHEME) !== 'https'
            || get_option('moxdop_connector_outbox_version') !== '1'
            || ($now !== true && (int) get_option('moxdop_connector_events_retry_at', 0) > time())) {
            return;
        }
        $table = $wpdb->prefix.'moxdop_outbox';
        // Nothing to send: no request at all, except a heartbeat every 6 hours so MoxDOP knows the site is fine.
        if (! $wpdb->get_var("SELECT id FROM $table LIMIT 1")) {
            delete_option(self::WAITING_OPTION);
            $ack = strtotime((string) get_option('moxdop_connector_events_ack_at'));
            if ($ack && $ack > time() - self::HEARTBEAT) {
                return;
            }
        }
        $lock = 'moxdop_connector_events_lock';
        if (! MoxDOP_Connector_Lock::acquire($lock, 120)) {
            return;
        }
        try {
            $rows = $wpdb->get_results("SELECT id, event_id, payload FROM $table ORDER BY id ASC LIMIT 50");
            $events = [];
            foreach ($rows as $row) {
                $event = json_decode($row->payload, true);
                if (is_array($event)) {
                    $events[] = $event;
                } else {
                    $wpdb->delete($table, ['id' => $row->id]);
                    update_option('moxdop_connector_events_gap_at', gmdate('c'), false);
                }
            }
            $payload = [
                'schema_version' => 1,
                'installation_id' => (string) get_option('moxdop_connector_installation_id'),
                'plugin_version' => MOXDOP_CONNECTOR_VERSION,
                'events' => $events,
                'delivery' => $this->status(),
            ];
            $body = wp_json_encode($payload);
            $timestamp = (string) time();
            $nonce = wp_generate_uuid4();
            $path = '/api/connectors/wordpress/events';
            $canonical = implode("\n", ['POST', $path, '', $timestamp, $nonce, hash('sha256', $body)]);
            $response = wp_safe_remote_post(untrailingslashit($app).$path, [
                'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
                'headers' => [
                    'Content-Type' => 'application/json', 'Accept' => 'application/json',
                    'X-MoxDOP-Client' => $credentials['client_id'],
                    'X-MoxDOP-Installation' => $payload['installation_id'],
                    'X-MoxDOP-Timestamp' => $timestamp, 'X-MoxDOP-Nonce' => $nonce,
                    'X-MoxDOP-Signature' => hash_hmac('sha256', $canonical, $credentials['shared_secret']),
                ],
                'body' => $body,
            ]);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            $decoded = ! is_wp_error($response) ? json_decode(wp_remote_retrieve_body($response), true) : null;
            $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
            $meta = is_array($decoded) ? ($decoded['meta'] ?? []) : [];
            $expected = is_array($data) ? hash_hmac('sha256', implode("\n", [
                (string) ($meta['server_time'] ?? ''), $nonce,
                hash('sha256', MoxDOP_Connector_Canonical_JSON::encode($data)),
            ]), $credentials['shared_secret']) : '';
            if ($code !== 200 || ! is_array($data) || ! is_array($data['accepted_event_ids'] ?? null)
                || abs(time() - (int) ($meta['server_time'] ?? 0)) > 300
                || ($meta['request_nonce'] ?? '') !== $nonce
                || ! hash_equals($expected, (string) ($meta['signature'] ?? ''))) {
                $attempt = min(8, (int) get_option('moxdop_connector_events_attempt', 0) + 1);
                update_option('moxdop_connector_events_attempt', $attempt, false);
                update_option('moxdop_connector_events_retry_at', time() + min(21600, 300 * (2 ** ($attempt - 1))), false);
                update_option('moxdop_connector_events_error', $code ? 'HTTP '.$code.' / acknowledgement not verified' : 'Connection unavailable', false);

                return;
            }
            $accepted = $data['accepted_event_ids'];
            foreach ($rows as $row) {
                if (in_array($row->event_id, $accepted, true)) {
                    $wpdb->delete($table, ['id' => $row->id, 'event_id' => $row->event_id]);
                }
            }
            update_option('moxdop_connector_events_ack_at', gmdate('c'), false);
            if (! $wpdb->get_var("SELECT id FROM $table LIMIT 1")) {
                delete_option(self::WAITING_OPTION);
            }
            delete_option('moxdop_connector_events_error');
            delete_option('moxdop_connector_events_attempt');
            delete_option('moxdop_connector_events_retry_at');
        } finally {
            MoxDOP_Connector_Lock::release($lock);
        }
    }
}
