<?php

defined('ABSPATH') || exit;

/** Small local outbox. No network requests run in content-save hooks. */
final class MoxDOP_Connector_Events
{
    const HOOK = 'moxdop_connector_send_events';
    const LIMIT = 10000;
    private $pending = [];

    public function register()
    {
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action('init', [$this, 'install']);
        add_action(self::HOOK, [$this, 'send']);
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
    }

    public function schedules($schedules)
    {
        $schedules['moxdop_five_minutes'] = ['interval' => 300, 'display' => 'MoxDOP / 5 minutes'];
        return $schedules;
    }

    public function install()
    {
        global $wpdb;
        if (get_option('moxdop_connector_outbox_version') !== '1') {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $table = $wpdb->prefix.'moxdop_outbox';
            dbDelta("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id varchar(36) NOT NULL,
                payload longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_id (event_id)
            ) ".$wpdb->get_charset_collate().";");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                update_option('moxdop_connector_outbox_version', '1', false);
            }
        }
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'moxdop_five_minutes', self::HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
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
        }
    }

    public function option_changed($key, $old, $new)
    {
        if (in_array($key, ['blog_public', 'permalink_structure', 'home', 'siteurl', 'show_on_front', 'page_on_front', 'page_for_posts', 'default_role', 'users_can_register', 'seopress_titles_option_name', 'seopress_xml_sitemap_option_name', 'litespeed.conf'], true)) {
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
        if (! get_option(MoxDOP_Connector_Secrets::OPTION) || get_option('moxdop_connector_outbox_version') !== '1') {
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
        $this->pending[$key] = [
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
            if ($wpdb->insert($table, ['event_id' => $event['event_id'], 'payload' => wp_json_encode($event), 'created_at' => gmdate('Y-m-d H:i:s')]) === false) {
                update_option('moxdop_connector_events_gap_at', gmdate('c'), false);
            }
        }
        $this->pending = [];
        $cutoff = $wpdb->get_var("SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET ".self::LIMIT);
        if ($cutoff) {
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id <= %d", $cutoff));
            update_option('moxdop_connector_events_gap_at', gmdate('c'), false);
        }
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

    public function send()
    {
        global $wpdb;
        $credentials = (new MoxDOP_Connector_Secrets())->read();
        $app = (string) get_option('moxdop_connector_app_url');
        if (! is_array($credentials) || wp_parse_url($app, PHP_URL_SCHEME) !== 'https'
            || get_option('moxdop_connector_outbox_version') !== '1'
            || (int) get_option('moxdop_connector_events_retry_at', 0) > time()) {
            return;
        }
        $lock = 'moxdop_connector_events_lock';
        $old = get_option($lock);
        if ($old && (int) $old < time() - 120) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, (string) $old));
            wp_cache_delete($lock, 'options');
        }
        if (! add_option($lock, (string) time(), '', 'no')) {
            return;
        }
        try {
            $table = $wpdb->prefix.'moxdop_outbox';
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
            delete_option('moxdop_connector_events_error');
            delete_option('moxdop_connector_events_attempt');
            delete_option('moxdop_connector_events_retry_at');
        } finally {
            delete_option($lock);
        }
    }
}
