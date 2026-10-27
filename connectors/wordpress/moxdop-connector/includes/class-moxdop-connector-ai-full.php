<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.26.0: the "Tam yetki" (full) level of the Claude / ChatGPT connection, and the comments / form messages
 * tools behind their own switch.
 *
 * Full adds: publishing and scheduling (Akıllı tarih days too), trash (never a permanent delete), the site builder's
 * operations (pages, posts, custom posts, ACF, Elementor data and templates, media, menus, site and Elementor kit
 * settings), technical SEO (301, noindex, canonical, schema, llms.txt), plugins from the WordPress.org directory only
 * (search, install, activate, deactivate, update; no zip uploads, no deleting), theme / core updates, plugin settings
 * (read and write any plugin option except a few that could lock the site, secrets masked), ready professional setups
 * for common SEO / cache plugins, a cache purge and a site check.
 *
 * A plugin activation, an option change and a setup are followed by a site check (home page and the given pages load
 * without a PHP error); when the site broke, the change is put back at once and the answer says why. Every write is
 * in the connection's change log and undoable (kind "ai": the steps stored under UNDO_PREFIX; "build": the builder's
 * own undo; "fix": the SEO fix log).
 */
final class MoxDOP_Connector_AI_Full
{
    /** Options a change could lock the site with; plugins and themes are switched with their own tools. */
    const BLOCKED_OPTIONS = [
        'siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'active_plugins', 'template', 'stylesheet',
        'cron', 'db_version', 'initial_db_version', 'recently_activated', 'uninstall_plugins', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
        'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'rewrite_rules', 'can_compress_scripts', 'auto_update_plugins', 'auto_update_themes',
    ];

    const SECRET_PATTERN = '/(key|secret|token|pass|license|licence|api|auth|salt|credential)/i';

    /** Option name prefixes of well-known plugins (their slug otherwise). */
    const PLUGIN_PREFIXES = [
        'seo-by-rank-math' => ['rank-math', 'rank_math'], 'wordpress-seo' => ['wpseo'], 'litespeed-cache' => ['litespeed.'], 'autoptimize' => ['autoptimize'],
        'cache-enabler' => ['cache_enabler'], 'w3-total-cache' => ['w3tc'], 'wp-super-cache' => ['wpsupercache', 'wp_super_cache', 'ossdl'], 'polylang' => ['polylang'],
        'contact-form-7' => ['wpcf7'], 'elementor' => ['elementor_'], 'wordfence' => ['wordfence'], 'wp-optimize' => ['wp-optimize'], 'webp-converter-for-media' => ['webpc_'],
        'wp-fastest-cache' => ['WpFastestCache', 'wpfc'], 'woocommerce' => ['woocommerce_'], 'wp-mail-smtp' => ['wp_mail_smtp'], 'updraftplus' => ['updraft_'],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function definitions()
    {
        $id = ['type' => 'integer', 'description' => 'Post / page id.'];
        $obj = ['type' => 'object', 'description' => 'JSON object.'];

        return [
            'inspect_site' => ['level' => 'full', 'read' => true, 'description' => 'What the site is built with: ACF (field groups, post types), Elementor (templates, kit colors and fonts), post types, menus and theme locations, settings, and what earlier builds made. Read it before build.', 'props' => []],
            'build' => ['level' => 'full', 'read' => false, 'description' => 'Up to 25 site-building operations in order (the MoxDOP site builder). Each needs a `ref` (letters, digits, - _ . :) except acf_import, menu and settings; the same ref again updates; "ref:<ref>" points at an earlier item. '
                .'post {ref, id (an existing post to take over), post_type, title, slug, status draft|publish|pending|private, content, excerpt, parent, menu_order, template, featured_image, terms {taxonomy: [names]}, acf {field: value}, elementor [elements] (the page\'s _elementor_data), seo {title, description}, schema}; '
                .'elementor_template {ref, id, type header|footer|section|container|page|single|archive|popup|loop-item, title, template {content, page_settings}, conditions}; media {ref, url or data_base64 + filename, alt, title}; '
                .'menu {name, items [{title, ref | post_id | url, children}], location}; settings {values {blogname, blogdescription, show_on_front, page_on_front, page_for_posts, permalink_structure, elementor_cpt_support, elementor_kit {Site Settings keys}}}; acf_import {items [ACF export objects]}; trash {ref}. '
                .'To change an existing Elementor page: get_content shows its builder, take it over with post {ref, id, elementor} sending the full element tree.', 'props' => [
                'operations' => ['type' => 'array', 'items' => $obj],
            ], 'required' => ['operations']],
            'publish' => ['level' => 'full', 'read' => false, 'description' => 'Publishes or schedules a draft / pending post. when: now (default), smart (Akıllı tarih: the nearest free Monday, Wednesday or Friday of the last 60 days, else the next one ahead), smart_future (the next free Mon / Wed / Fri ahead), or a date "YYYY-MM-DD HH:MM" (site time; a future date schedules). Its unpublished translations get the same date.', 'props' => [
                'id' => $id, 'when' => ['type' => 'string'],
            ], 'required' => ['id']],
            'trash' => ['level' => 'full', 'read' => false, 'description' => 'Moves a post, page or custom post to the trash (undo brings it back; nothing is deleted for good). The front page and posts page are refused.', 'props' => ['id' => $id], 'required' => ['id']],
            'seo_technical' => ['level' => 'full', 'read' => false, 'description' => 'Technical SEO, logged and undoable: changes [{type, object_id, value, from}] with type noindex (true/false), canonical (URL on the site), schema (JSON-LD object; object_id 0 = site-wide), redirect (from = old path, value = target URL; a 301 in the SEO plugin, chains refused), llms_txt (value = the whole file).', 'props' => [
                'changes' => ['type' => 'array', 'items' => $obj],
            ], 'required' => ['changes']],
            'list_plugins' => ['level' => 'full', 'read' => true, 'description' => 'Installed plugins (file, name, version, active, update available) and themes (active one marked), with WordPress and PHP versions and the web server.', 'props' => []],
            'search_plugins' => ['level' => 'full', 'read' => true, 'description' => 'Searches the WordPress.org plugin directory: slug, name, version, rating, active installs, last update, tested WordPress version.', 'props' => ['search' => ['type' => 'string']], 'required' => ['search']],
            'install_plugin' => ['level' => 'full', 'read' => false, 'description' => 'Installs a plugin from the WordPress.org directory by slug (only there: no zip files) and activates it unless activate=false. A site check follows activation; a broken site deactivates it again.', 'props' => [
                'slug' => ['type' => 'string'], 'activate' => ['type' => 'boolean'],
            ], 'required' => ['slug']],
            'set_plugin_state' => ['level' => 'full', 'read' => false, 'description' => 'Activates or deactivates an installed plugin (file from list_plugins, e.g. "autoptimize/autoptimize.php"). Activation is followed by a site check. Deleting a plugin is left to the site admin.', 'props' => [
                'plugin' => ['type' => 'string'], 'active' => ['type' => 'boolean'],
            ], 'required' => ['plugin', 'active']],
            'run_update' => ['level' => 'full', 'read' => false, 'description' => 'Installs an update WordPress offers: type plugin (item = plugin file), theme (item = stylesheet) or core. Not undoable; a site check follows.', 'props' => [
                'type' => ['type' => 'string', 'enum' => ['plugin', 'theme', 'core']], 'item' => ['type' => 'string'],
            ], 'required' => ['type']],
            'plugin_settings' => ['level' => 'full', 'read' => true, 'description' => 'The stored settings of a plugin (its options by slug, e.g. seo-by-rank-math, wordpress-seo, litespeed-cache, autoptimize) or of any option names matching search. Secrets are masked.', 'props' => [
                'plugin' => ['type' => 'string'], 'search' => ['type' => 'string'],
            ]],
            'set_option' => ['level' => 'full', 'read' => false, 'description' => 'Writes one option (a plugin setting). value is any JSON; merge=true merges an object into the stored array instead of replacing it. A few options that could lock the site are refused. A site check follows; a broken site puts the old value back. Undoable.', 'props' => [
                'name' => ['type' => 'string'], 'value' => ['description' => 'Any JSON value.'], 'merge' => ['type' => 'boolean'],
            ], 'required' => ['name', 'value']],
            'setup_guide' => ['level' => 'full', 'read' => true, 'description' => 'Professional setup guide for a topic (seo, cache, images, security, forms, speed): which plugin to use on this site and why, what apply_setup sets, and what to check by hand.', 'props' => [
                'topic' => ['type' => 'string', 'enum' => ['seo', 'cache', 'images', 'security', 'forms', 'speed']],
            ], 'required' => ['topic']],
            'apply_setup' => ['level' => 'full', 'read' => false, 'description' => 'Applies a ready professional setup to an active plugin: rank_math, yoast, litespeed, autoptimize, cache_enabler. values (optional): name (organisation), logo_url, type (company / person), business_type. Followed by a site check; a broken site puts every value back. Undoable as one change.', 'props' => [
                'name' => ['type' => 'string', 'enum' => ['rank_math', 'yoast', 'litespeed', 'autoptimize', 'cache_enabler']], 'values' => $obj,
            ], 'required' => ['name']],
            'clear_cache' => ['level' => 'full', 'read' => false, 'description' => 'Empties the page caches of the cache plugins in use (LiteSpeed, WP Rocket, W3TC, WP Super Cache, Cache Enabler, WP Fastest Cache, Autoptimize), the object cache and the Elementor CSS.', 'props' => []],
            'site_check' => ['level' => 'full', 'read' => true, 'description' => 'Loads the home page and the given paths like a visitor: status, time, size, cache and compression headers, PHP errors.', 'props' => [
                'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'list_comments' => ['level' => 'private', 'read' => true, 'description' => 'Comments with author, e-mail, text, post and status (hold = waiting). Personal data: use it only for what the user asked.', 'props' => [
                'status' => ['type' => 'string', 'enum' => ['all', 'hold', 'approve', 'spam']], 'post_id' => ['type' => 'integer'], 'page' => ['type' => 'integer'],
            ]],
            'reply_comment' => ['level' => 'private', 'read' => false, 'description' => 'Replies to a comment in the name of the site user (approved at once). Undo trashes the reply.', 'props' => [
                'id' => ['type' => 'integer'], 'text' => ['type' => 'string'],
            ], 'required' => ['id', 'text']],
            'moderate_comment' => ['level' => 'private', 'read' => false, 'description' => 'approve, hold or spam a comment. Undoable.', 'props' => [
                'id' => ['type' => 'integer'], 'action' => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam']],
            ], 'required' => ['id', 'action']],
            'list_form_messages' => ['level' => 'private', 'read' => true, 'description' => 'The latest form messages the site stores (Flamingo for Contact Form 7, Elementor Pro form submissions). Personal data: use it only for what the user asked.', 'props' => [
                'limit' => ['type' => 'integer', 'description' => 'Max 50 (default 20).'],
            ]],
        ];
    }

    public static function owns($name)
    {
        return isset(self::definitions()[$name]);
    }

    public function run($name, array $args, array $caller)
    {
        return $this->{'tool_'.$name}($args, $caller);
    }

    /* ------------------------------------------------------------------- undo */

    private static function remember(array $steps)
    {
        $id = wp_generate_uuid4();
        add_option(MoxDOP_Connector_AI::UNDO_PREFIX.$id, ['at' => gmdate('c'), 'steps' => $steps], '', 'no');

        return $id;
    }

    /** @return array{ok: bool, error?: string} */
    public static function undo($id)
    {
        $entry = preg_match('/^[a-f0-9-]{36}$/', (string) $id) ? get_option(MoxDOP_Connector_AI::UNDO_PREFIX.$id) : null;
        if (! is_array($entry) || ! empty($entry['undone'])) {
            return ['ok' => false, 'error' => 'unknown or already undone'];
        }
        $errors = [];
        foreach (array_reverse((array) $entry['steps']) as $step) {
            $done = self::undo_step((array) $step);
            if ($done !== true) {
                $errors[] = $done;
            }
        }
        $entry['undone'] = gmdate('c');
        update_option(MoxDOP_Connector_AI::UNDO_PREFIX.$id, $entry, false);

        return $errors === [] ? ['ok' => true] : ['ok' => false, 'error' => implode('; ', $errors)];
    }

    /** @return true|string */
    private static function undo_step(array $step)
    {
        switch ($step[0] ?? '') {
            case 'status':
                $result = wp_update_post(['ID' => (int) $step[1], 'post_status' => (string) $step[2], 'post_date' => (string) $step[3], 'post_date_gmt' => (string) $step[4], 'edit_date' => true], true);

                return is_wp_error($result) ? $result->get_error_message() : true;
            case 'untrash':
                add_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3);
                $done = wp_untrash_post((int) $step[1]);
                remove_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10);

                return $done ? true : 'could not restore '.(int) $step[1].' from the trash';
            case 'option':
                if (empty($step[2])) {
                    delete_option((string) $step[1]);
                } else {
                    update_option((string) $step[1], $step[3]);
                }

                return true;
            case 'plugin':
                self::admin_includes();
                if (! empty($step[2])) {
                    $result = activate_plugin((string) $step[1]);

                    return is_wp_error($result) ? $result->get_error_message() : true;
                }
                deactivate_plugins((string) $step[1]);

                return true;
            case 'comment_status':
                return wp_set_comment_status((int) $step[1], (string) $step[2]) ? true : 'comment status not restored';
            case 'trash_comment':
                return wp_trash_comment((int) $step[1]) ? true : 'reply not removed';
        }

        return 'unknown undo step';
    }

    /* ------------------------------------------------------------- site check */

    /** @return array{ok: bool|null, pages: list<array<string, mixed>>} ok null = the site could not load itself (loopback blocked) */
    public static function check(array $paths = [])
    {
        $urls = [home_url('/')];
        foreach (array_slice($paths, 0, 5) as $path) {
            $path = '/'.ltrim((string) wp_parse_url((string) $path, PHP_URL_PATH), '/');
            $urls[] = home_url($path);
        }
        $pages = [];
        $ok = true;
        $reached = false;
        foreach (array_values(array_unique($urls)) as $url) {
            $started = microtime(true);
            $response = wp_remote_get(add_query_arg('moxdop_check', (string) time(), $url), ['timeout' => 25, 'redirection' => 3, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);
            $ms = (int) round((microtime(true) - $started) * 1000);
            if (is_wp_error($response)) {
                $pages[] = ['url' => $url, 'ok' => null, 'error' => $response->get_error_message()];

                continue;
            }
            $reached = true;
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $broken = $code >= 500 || stripos($body, 'There has been a critical error') !== false || stripos($body, '<b>Fatal error</b>') !== false
                || stripos($body, 'Parse error') !== false || ($code === 200 && stripos($body, '</html>') === false && stripos((string) wp_remote_retrieve_header($response, 'content-type'), 'html') !== false);
            if ($broken) {
                $ok = false;
            }
            $header = static function ($name) use ($response) {
                $value = wp_remote_retrieve_header($response, $name);

                return is_array($value) ? implode(', ', $value) : (string) $value;
            };
            $pages[] = array_filter(['url' => $url, 'ok' => ! $broken, 'status' => $code, 'ms' => $ms, 'kb' => (int) round(strlen($body) / 1024),
                'cache' => trim($header('x-litespeed-cache').' '.$header('x-cache').' '.$header('cf-cache-status').' '.$header('x-wp-super-cache').' '.$header('x-cache-enabler')),
                'compressed' => $header('content-encoding'), 'cache_control' => $header('cache-control'),
                'php_error' => $broken ? mb_substr(trim(wp_strip_all_tags(substr($body, 0, 3000))), 0, 300) : null], static function ($v) {
                    return $v !== null && $v !== '';
                });
        }

        return ['ok' => $reached ? $ok : null, 'pages' => $pages];
    }

    /** Runs a change, checks the site, and undoes it when the site broke. */
    private function guarded(callable $change, callable $undo, array $paths = [])
    {
        $result = $change();
        if (is_wp_error($result)) {
            return $result;
        }
        $check = self::check($paths);
        if ($check['ok'] === false) {
            $undo();
            $broken = array_values(array_filter($check['pages'], static function ($p) {
                return ($p['ok'] ?? true) === false;
            }));

            return new WP_Error('broke', 'Bu değişiklik siteyi bozdu ve geri alındı: '.wp_json_encode($broken[0] ?? $check, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return ['result' => $result, 'site_check' => $check];
    }

    private static function admin_includes()
    {
        foreach (['file.php', 'misc.php', 'plugin.php', 'plugin-install.php', 'update.php', 'class-wp-upgrader.php', 'theme.php'] as $file) {
            require_once ABSPATH.'wp-admin/includes/'.$file;
        }
    }

    /* ------------------------------------------------------------ tools: build */

    private function tool_inspect_site()
    {
        return (new MoxDOP_Connector_Builder)->describe_site();
    }

    private function tool_build(array $args, array $caller)
    {
        $operations = is_array($args['operations'] ?? null) ? array_values($args['operations']) : [];
        if ($operations === [] || count($operations) > MoxDOP_Connector_Builder::MAX_OPERATIONS) {
            return new WP_Error('invalid', 'operations: 1-'.MoxDOP_Connector_Builder::MAX_OPERATIONS.' işlem.');
        }
        $builder = new MoxDOP_Connector_Builder;
        $results = $builder->run_operations($operations);
        foreach ($results as $result) {
            if (! empty($result['change_id'])) {
                MoxDOP_Connector_AI::record($caller, 'build', (int) ($result['id'] ?? 0), 'Kurulum: '.($result['op'] ?? '').' '.($result['ref'] ?? ''), $result['change_id'], 'build');
            }
        }
        $settings = array_filter($operations, static function ($op) {
            return is_array($op) && in_array($op['op'] ?? '', ['settings', 'elementor_template'], true);
        });
        $out = ['results' => $results];
        if ($settings !== []) {
            $out['site_check'] = self::check();
            if ($out['site_check']['ok'] === false) {
                $ids = array_values(array_filter(array_column(array_reverse($results), 'change_id')));
                $builder->undo_changes($ids, true);
                $out['undone'] = 'Site bozuldu, bu kurulumun tüm adımları geri alındı.';
            }
        }

        return $out;
    }

    private function tool_publish(array $args, array $caller)
    {
        $post = get_post((int) ($args['id'] ?? 0));
        if (! $post || ! current_user_can('publish_post', $post->ID) || in_array($post->post_type, ['attachment', 'revision'], true)) {
            return new WP_Error('not_found', 'Yazı yok ya da yayınlama yetkisi yok.');
        }
        if (! in_array($post->post_status, ['draft', 'pending', 'auto-draft', 'future'], true)) {
            return new WP_Error('state', 'Yazı zaten '.$post->post_status.'.');
        }
        $when = trim((string) ($args['when'] ?? 'now'));
        $date = null;
        if ($when === 'smart' || $when === 'smart_future') {
            $slots = MoxDOP_Connector_Smart_Date::slots($post->ID, null, [], 1, $when === 'smart_future' ? 'future' : 'all');
            if ($slots === []) {
                return new WP_Error('no_day', 'Boş Pazartesi, Çarşamba ya da Cuma yok.');
            }
            $date = str_replace('T', ' ', $slots[0]['date']);
        } elseif ($when !== 'now' && $when !== '') {
            $parsed = date_create_immutable($when, wp_timezone());
            if (! $parsed) {
                return new WP_Error('invalid', 'when: now, smart, smart_future ya da "YYYY-MM-DD HH:MM".');
            }
            $date = $parsed->format('Y-m-d H:i:s');
        }
        $undo = ['status', $post->ID, $post->post_status, $post->post_date, $post->post_date_gmt];
        $fields = ['ID' => $post->ID, 'post_status' => 'publish'];
        if ($date !== null) {
            $fields += ['post_date' => $date, 'post_date_gmt' => get_gmt_from_date($date), 'edit_date' => true];
        }
        update_post_meta($post->ID, MoxDOP_Connector_Smart_Date::PICKED_META, (string) time());
        $result = wp_update_post($fields, true);
        if (is_wp_error($result)) {
            delete_post_meta($post->ID, MoxDOP_Connector_Smart_Date::PICKED_META);

            return $result;
        }
        $change = self::remember([$undo]);
        $status = get_post_status($post->ID);
        MoxDOP_Connector_AI::record($caller, 'publish', $post->ID, ($status === 'future' ? 'Zamanlandı: ' : 'Yayınlandı: ').get_the_title($post->ID).' ('.get_post_field('post_date', $post->ID).')', $change, 'ai');

        return ['id' => $post->ID, 'status' => $status, 'date' => get_post_field('post_date', $post->ID), 'url' => get_permalink($post->ID), 'change_id' => $change];
    }

    private function tool_trash(array $args, array $caller)
    {
        $id = (int) ($args['id'] ?? 0);
        $post = get_post($id);
        if (! $post || $post->post_type === 'attachment' || ! current_user_can('delete_post', $id)) {
            return new WP_Error('not_found', 'Yazı yok ya da silme yetkisi yok.');
        }
        if (in_array($id, [(int) get_option('page_on_front'), (int) get_option('page_for_posts')], true)) {
            return new WP_Error('front', 'Ana sayfa ve yazılar sayfası çöpe atılmaz.');
        }
        $title = get_the_title($id);
        if (! wp_trash_post($id)) {
            return new WP_Error('failed', 'Çöpe atılamadı.');
        }
        $change = self::remember([['untrash', $id]]);
        MoxDOP_Connector_AI::record($caller, 'trash', $id, 'Çöpe atıldı: '.$title, $change, 'ai');

        return ['id' => $id, 'status' => 'trash', 'change_id' => $change];
    }

    private function tool_seo_technical(array $args, array $caller)
    {
        $changes = is_array($args['changes'] ?? null) ? array_slice($args['changes'], 0, 50) : [];
        $fixes = new MoxDOP_Connector_Fixes;
        $out = [];
        foreach ($changes as $change) {
            $type = is_array($change) ? (string) ($change['type'] ?? '') : '';
            if (! in_array($type, ['noindex', 'canonical', 'schema', 'redirect', 'llms_txt'], true)) {
                $out[] = ['ok' => false, 'error' => 'type: noindex, canonical, schema, redirect, llms_txt'];

                continue;
            }
            $value = $change['value'] ?? null;
            if ($type === 'schema' && is_array($value)) {
                $value = wp_json_encode($value);
            }
            $result = $fixes->apply_change(['type' => $type, 'object_id' => (int) ($change['object_id'] ?? 0), 'value' => $value, 'from' => (string) ($change['from'] ?? ''), 'reference' => MoxDOP_Connector_AI::reference($caller)]);
            if (! empty($result['change_id'])) {
                MoxDOP_Connector_AI::record($caller, 'seo_technical', (int) ($change['object_id'] ?? 0), $type.($type === 'redirect' ? ' '.($change['from'] ?? '').' → '.$value : ''), $result['change_id'], 'fix');
            }
            $out[] = $result;
        }

        return ['results' => $out];
    }

    /* ---------------------------------------------------------- tools: plugins */

    private function tool_list_plugins()
    {
        self::admin_includes();
        $updates = get_site_transient('update_plugins');
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $plugins[] = ['file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active($file),
                'update' => isset($updates->response[$file]) ? (string) $updates->response[$file]->new_version : null];
        }
        $theme_updates = get_site_transient('update_themes');
        $themes = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $themes[] = ['stylesheet' => $slug, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'active' => get_stylesheet() === $slug,
                'update' => isset($theme_updates->response[$slug]) ? (string) ($theme_updates->response[$slug]['new_version'] ?? '') : null];
        }

        return ['wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'server' => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : '',
            'litespeed_server' => self::litespeed_server(), 'plugins' => $plugins, 'themes' => $themes];
    }

    private static function litespeed_server()
    {
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';

        return stripos($software, 'litespeed') !== false || isset($_SERVER['LSWS_EDITION']) || isset($_SERVER['X-LSCACHE']);
    }

    private function tool_search_plugins(array $args)
    {
        self::admin_includes();
        $result = plugins_api('query_plugins', ['search' => sanitize_text_field((string) ($args['search'] ?? '')), 'per_page' => 10,
            'fields' => ['short_description' => true, 'icons' => false, 'banners' => false, 'description' => false, 'sections' => false, 'compatibility' => false]]);
        if (is_wp_error($result)) {
            return $result;
        }

        return ['plugins' => array_map(static function ($p) {
            $p = (array) $p;

            return ['slug' => $p['slug'] ?? '', 'name' => wp_strip_all_tags((string) ($p['name'] ?? '')), 'version' => $p['version'] ?? '', 'rating' => $p['rating'] ?? null,
                'active_installs' => $p['active_installs'] ?? null, 'last_updated' => $p['last_updated'] ?? null, 'tested' => $p['tested'] ?? null,
                'summary' => wp_strip_all_tags((string) ($p['short_description'] ?? ''))];
        }, (array) ($result->plugins ?? []))];
    }

    private function tool_install_plugin(array $args, array $caller)
    {
        if (! current_user_can('install_plugins')) {
            return new WP_Error('forbidden', 'Bu kullanıcı eklenti kuramaz (ya da sitede dosya değişikliği kapalı).');
        }
        self::admin_includes();
        $slug = sanitize_key((string) ($args['slug'] ?? ''));
        $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($info) || empty($info->download_link)) {
            return new WP_Error('not_found', 'WordPress.org\'da bu eklenti yok: '.$slug);
        }
        if (strtolower((string) wp_parse_url($info->download_link, PHP_URL_HOST)) !== 'downloads.wordpress.org') {
            return new WP_Error('source', 'Yalnız WordPress.org eklentileri kurulur.');
        }
        $installed = null;
        foreach (get_plugins() as $file => $data) {
            if (strpos($file, $slug.'/') === 0) {
                $installed = $file;
            }
        }
        if ($installed === null) {
            if (! WP_Filesystem()) {
                return new WP_Error('filesystem', 'Sunucu dosya yazmaya izin vermiyor (FTP bilgisi istiyor).');
            }
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin);
            $result = $upgrader->install($info->download_link);
            if (is_wp_error($result) || ! $result) {
                return new WP_Error('install', 'Kurulamadı: '.(is_wp_error($result) ? $result->get_error_message() : implode(' ', (array) $upgrader->skin->get_upgrade_messages())));
            }
            wp_clean_plugins_cache();
            $installed = (string) $upgrader->plugin_info();
        }
        MoxDOP_Connector_AI::record($caller, 'install_plugin', 0, 'Eklenti kuruldu: '.wp_strip_all_tags((string) $info->name).' '.$info->version);
        $out = ['plugin' => $installed, 'name' => wp_strip_all_tags((string) $info->name), 'version' => $info->version, 'active' => is_plugin_active($installed)];
        if (($args['activate'] ?? true) && ! is_plugin_active($installed)) {
            $state = $this->tool_set_plugin_state(['plugin' => $installed, 'active' => true], $caller);
            if (is_wp_error($state)) {
                return $state;
            }
            $out = array_merge($out, $state);
        }

        return $out;
    }

    private function tool_set_plugin_state(array $args, array $caller)
    {
        if (! current_user_can('activate_plugins')) {
            return new WP_Error('forbidden', 'Bu kullanıcı eklenti açıp kapatamaz.');
        }
        self::admin_includes();
        $file = (string) ($args['plugin'] ?? '');
        $plugins = get_plugins();
        if (! isset($plugins[$file])) {
            return new WP_Error('not_found', 'Eklenti yok: '.$file.' (list_plugins ile dosya adına bak).');
        }
        if (strpos($file, 'moxdop-connector') === 0) {
            return new WP_Error('self', 'MoxDOP Connector kendini kapatmaz.');
        }
        $was = is_plugin_active($file);
        $want = (bool) ($args['active'] ?? false);
        if ($was === $want) {
            return ['plugin' => $file, 'active' => $was, 'unchanged' => true];
        }
        if (! $want) {
            deactivate_plugins($file);
            $change = self::remember([['plugin', $file, true]]);
            MoxDOP_Connector_AI::record($caller, 'set_plugin_state', 0, 'Kapatıldı: '.$plugins[$file]['Name'], $change, 'ai');

            return ['plugin' => $file, 'active' => false, 'change_id' => $change];
        }
        $guarded = $this->guarded(static function () use ($file) {
            $result = activate_plugin($file);

            return is_wp_error($result) ? $result : true;
        }, static function () use ($file) {
            deactivate_plugins($file);
        });
        if (is_wp_error($guarded)) {
            return $guarded;
        }
        $change = self::remember([['plugin', $file, false]]);
        MoxDOP_Connector_AI::record($caller, 'set_plugin_state', 0, 'Açıldı: '.$plugins[$file]['Name'], $change, 'ai');

        return ['plugin' => $file, 'active' => true, 'change_id' => $change, 'site_check' => $guarded['site_check']];
    }

    private function tool_run_update(array $args, array $caller)
    {
        if (! current_user_can('update_plugins')) {
            return new WP_Error('forbidden', 'Bu kullanıcı güncelleme yapamaz.');
        }
        $response = (new MoxDOP_Connector_Management)->run_update((string) ($args['type'] ?? ''), (string) ($args['item'] ?? ''));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = $response instanceof WP_REST_Response ? (array) $response->get_data() : (array) $response;
        MoxDOP_Connector_AI::record($caller, 'run_update', 0, 'Güncelleme: '.($data['type'] ?? '').' '.($data['item'] ?? '').' '.($data['from_version'] ?? '').' → '.($data['to_version'] ?? ''));

        return $data + ['site_check' => self::check()];
    }

    /* ---------------------------------------------------------- tools: settings */

    private function tool_plugin_settings(array $args)
    {
        global $wpdb;
        $slug = sanitize_key((string) ($args['plugin'] ?? ''));
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        $prefixes = $slug !== '' ? (self::PLUGIN_PREFIXES[$slug] ?? [$slug, str_replace('-', '_', $slug)]) : [];
        if ($search !== '') {
            $prefixes[] = '%'.$search;
        }
        if ($prefixes === []) {
            return new WP_Error('invalid', 'plugin ya da search gönder.');
        }
        $where = [];
        $params = [];
        foreach (array_unique($prefixes) as $prefix) {
            $where[] = 'option_name LIKE %s';
            $params[] = (strpos($prefix, '%') === 0 ? '%'.$wpdb->esc_like(substr($prefix, 1)) : $wpdb->esc_like($prefix)).'%';
        }
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE (".implode(' OR ', $where).") AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' ORDER BY option_name LIMIT 100", $params));
        $out = [];
        foreach ((array) $names as $name) {
            $value = self::mask($name, get_option($name));
            $json = (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out[$name] = strlen($json) > 20000 ? '(çok büyük: '.strlen($json).' bayt)' : $value;
        }

        return ['options' => $out, 'count' => count($out), 'blocked' => self::BLOCKED_OPTIONS];
    }

    private static function mask($name, $value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = is_string($key) && preg_match(self::SECRET_PATTERN, $key) && is_scalar($item) && (string) $item !== '' ? '(gizli)' : self::mask('', $item);
            }

            return $value;
        }

        return $name !== '' && preg_match(self::SECRET_PATTERN, (string) $name) && is_scalar($value) && (string) $value !== '' ? '(gizli)' : $value;
    }

    private static function blocked($name)
    {
        global $wpdb;

        return in_array($name, self::BLOCKED_OPTIONS, true) || strpos($name, 'moxdop_') === 0 || strpos($name, '_transient') === 0 || strpos($name, '_site_transient') === 0
            || $name === $wpdb->prefix.'user_roles' || $name === '';
    }

    /** @return array{0: list<array>, 1: list<string>} undo steps and changed names, or WP_Error */
    private static function write_options(array $values, $merge)
    {
        $steps = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (self::blocked($name)) {
                return new WP_Error('blocked', '"'.$name.'" bu bağlantıyla değiştirilmez (siteyi kilitleyebilir).');
            }
        }
        foreach ($values as $name => $value) {
            $sentinel = new stdClass;
            $old = get_option($name, $sentinel);
            $exists = $old !== $sentinel;
            $new = $value;
            if ($merge && $exists && is_array($old) && is_array($value)) {
                $new = array_replace_recursive($old, $value);
            } elseif ($merge && $exists && is_string($old) && is_array($value) && is_array(json_decode($old, true))) {
                // WP Fastest Cache and a few others keep a JSON string.
                $new = wp_json_encode(array_replace_recursive(json_decode($old, true), $value));
            }
            $steps[] = ['option', $name, $exists, $exists ? $old : null];
            update_option($name, $new);
        }

        return [$steps, array_keys($values)];
    }

    private function tool_set_option(array $args, array $caller)
    {
        $name = (string) ($args['name'] ?? '');
        if (! array_key_exists('value', $args)) {
            return new WP_Error('invalid', 'value gerekli.');
        }
        $steps = [];
        $guarded = $this->guarded(static function () use ($name, $args, &$steps) {
            $written = self::write_options([$name => $args['value']], ! empty($args['merge']));
            if (is_wp_error($written)) {
                return $written;
            }
            $steps = $written[0];

            return true;
        }, static function () use (&$steps) {
            foreach (array_reverse($steps) as $step) {
                self::undo_step($step);
            }
        });
        if (is_wp_error($guarded)) {
            return $guarded;
        }
        $change = self::remember($steps);
        MoxDOP_Connector_AI::record($caller, 'set_option', 0, 'Ayar: '.$name, $change, 'ai');

        return ['name' => $name, 'value' => self::mask($name, get_option($name)), 'change_id' => $change, 'site_check' => $guarded['site_check']];
    }

    /** @return array<string, array{plugin: string, options: array<string, mixed>, merge: bool, does: string}> */
    private static function recipes(array $values)
    {
        $name = sanitize_text_field((string) ($values['name'] ?? wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)));
        $logo = esc_url_raw((string) ($values['logo_url'] ?? ''));
        $type = ($values['type'] ?? 'company') === 'person' ? 'person' : 'company';
        $business = sanitize_text_field((string) ($values['business_type'] ?? ''));

        return [
            'rank_math' => ['plugin' => 'seo-by-rank-math/rank-math.php', 'merge' => true,
                'does' => 'Rank Math: "-" title separator, organisation schema (name, logo, type), author and date archives off, empty categories noindex, attachment pages redirected to their post, images in the sitemap, no attachment / author sitemaps.',
                'options' => [
                    'rank-math-options-general' => ['attachment_redirect_urls' => 'on', 'attachment_redirect_default' => home_url('/')],
                    'rank-math-options-titles' => array_filter(['title_separator' => '-', 'knowledgegraph_type' => $type, 'knowledgegraph_name' => $name, 'website_name' => $name,
                        'knowledgegraph_logo' => $logo, 'local_business_type' => $business, 'disable_author_archives' => 'on', 'disable_date_archives' => 'on', 'noindex_empty_taxonomies' => 'on']),
                    'rank-math-options-sitemap' => ['include_images' => 'on', 'include_featured_image' => 'on', 'pt_attachment_sitemap' => 'off', 'authors_sitemap' => 'off'],
                ]],
            'yoast' => ['plugin' => 'wordpress-seo/wp-seo.php', 'merge' => true,
                'does' => 'Yoast: "-" separator, organisation (name, logo), author and date archives off, attachment pages redirected, XML sitemap on.',
                'options' => [
                    'wpseo_titles' => array_filter(['separator' => 'sc-dash', 'company_or_person' => $type, 'company_name' => $name, 'website_name' => $name, 'company_logo' => $logo,
                        'disable-author' => true, 'disable-date' => true, 'disable-attachment' => true, 'noindex-author-wpseo' => true]),
                    'wpseo' => ['enable_xml_sitemap' => true],
                ]],
            'litespeed' => ['plugin' => 'litespeed-cache/litespeed-cache.php', 'merge' => false,
                'does' => 'LiteSpeed Cache (LiteSpeed servers): page and browser cache on, CSS / JS / HTML minified, query strings and emoji script removed, images lazy-loaded. CSS / JS combining stays off (breaks themes most often).',
                'options' => ['litespeed.conf.cache' => 1, 'litespeed.conf.cache-browser' => 1, 'litespeed.conf.optm-css_min' => 1, 'litespeed.conf.optm-js_min' => 1,
                    'litespeed.conf.optm-html_min' => 1, 'litespeed.conf.optm-qs_rm' => 1, 'litespeed.conf.optm-emoji_rm' => 1, 'litespeed.conf.media-lazy' => 1]],
            'autoptimize' => ['plugin' => 'autoptimize/autoptimize.php', 'merge' => false,
                'does' => 'Autoptimize: HTML, CSS and JS minified without combining (HTTP/2), JS deferred, already minified files kept.',
                'options' => ['autoptimize_html' => 'on', 'autoptimize_js' => 'on', 'autoptimize_js_aggregate' => '', 'autoptimize_js_defer_not_aggregate' => 'on',
                    'autoptimize_css' => 'on', 'autoptimize_css_aggregate' => '', 'autoptimize_minify_excluded' => 'on']],
            'cache_enabler' => ['plugin' => 'cache-enabler/cache-enabler.php', 'merge' => true,
                'does' => 'Cache Enabler (Apache / Nginx): pages cached for 24 hours, gzip copies, HTML and inline CSS / JS minified, cache cleared when a post is saved.',
                'options' => ['cache_enabler' => ['cache_expires' => 1, 'cache_expiry_time' => 24, 'clear_site_cache_on_saved_post' => 1, 'compress_cache' => 1, 'minify_html' => 1, 'minify_inline_css_js' => 1]]],
        ];
    }

    private function tool_setup_guide(array $args)
    {
        $topic = (string) ($args['topic'] ?? '');
        $litespeed = self::litespeed_server();
        $guides = [
            'seo' => ['use' => defined('RANK_MATH_VERSION') ? 'rank_math (installed)' : (defined('WPSEO_VERSION') ? 'yoast (installed)' : 'seo-by-rank-math (install; one SEO plugin only)'),
                'apply_setup' => defined('WPSEO_VERSION') && ! defined('RANK_MATH_VERSION') ? 'yoast' : 'rank_math',
                'then' => ['Set the organisation name, logo and business type (values).', 'Check Settings › Reading "Discourage search engines" is off.', 'Permalinks /%postname%/.', 'Every page: unique SEO title ≤ 60, description ≤ 155 (update_seo).', 'Submit the sitemap in Search Console (the user does it).', 'Never run two SEO plugins at once.']],
            'cache' => ['use' => $litespeed ? 'litespeed-cache (the server is LiteSpeed)' : 'cache-enabler + autoptimize (the server is not LiteSpeed)',
                'apply_setup' => $litespeed ? 'litespeed' : 'cache_enabler, then autoptimize',
                'then' => ['Only one page-cache plugin; deactivate others first.', 'Run site_check before and after; compare ms and the cache header.', 'Logged-in users are never cached; test as a visitor.', 'If a page looks broken after minify, turn off JS minify first.', 'clear_cache after content or design changes.']],
            'images' => ['use' => 'webp-converter-for-media (WebP copies without touching the originals)', 'apply_setup' => null,
                'then' => ['Install and activate it, then the user presses "Start bulk optimization" once in Settings › Converter for Media (it runs in the browser).', 'Alt texts: list_media missing_alt=true, then update_alt_text.', 'Lazy loading is on in WordPress by default.']],
            'security' => ['use' => 'Keep WordPress, plugins and themes updated (run_update); remove unused plugins (the site admin deletes them).', 'apply_setup' => null,
                'then' => ['Users and passwords stay with the site admin.', 'A firewall plugin (Wordfence) only if the user asks; it can slow the site.', 'Backups: updraftplus with remote storage (the user links the storage account).']],
            'forms' => ['use' => 'contact-form-7 + flamingo (stores messages) or the Elementor Pro form', 'apply_setup' => null,
                'then' => ['Send a test message after any change.', 'Mail from the site often fails: wp-mail-smtp with the user\'s mail account (the user enters the password).', 'KVKK consent checkbox on every form.']],
            'speed' => ['use' => 'cache (see cache) + images (see images)', 'apply_setup' => $litespeed ? 'litespeed' : 'cache_enabler, autoptimize',
                'then' => ['site_check shows time per page; the home page should answer under 800 ms when cached.', 'Too many plugins and page-builder add-ons are the usual cause; list_plugins and propose what to switch off.', 'Server-side items (PHP version, Redis, CDN, DNS) are the host\'s; tell the user.']],
        ];
        if (! isset($guides[$topic])) {
            return new WP_Error('invalid', 'topic: '.implode(', ', array_keys($guides)));
        }
        $recipes = self::recipes([]);
        $out = $guides[$topic];
        foreach (array_map('trim', explode(',', (string) $out['apply_setup'])) as $recipe) {
            $recipe = preg_replace('/[^a-z_]/', '', $recipe);
            if (isset($recipes[$recipe])) {
                $out['setups'][$recipe] = $recipes[$recipe]['does'];
            }
        }

        return $out;
    }

    private function tool_apply_setup(array $args, array $caller)
    {
        $recipes = self::recipes(is_array($args['values'] ?? null) ? $args['values'] : []);
        $name = (string) ($args['name'] ?? '');
        if (! isset($recipes[$name])) {
            return new WP_Error('invalid', 'name: '.implode(', ', array_keys($recipes)));
        }
        self::admin_includes();
        $recipe = $recipes[$name];
        if (! is_plugin_active($recipe['plugin'])) {
            return new WP_Error('inactive', $recipe['plugin'].' etkin değil; önce install_plugin ile kur ve etkinleştir.');
        }
        $steps = [];
        $guarded = $this->guarded(static function () use ($recipe, &$steps) {
            $written = self::write_options($recipe['options'], $recipe['merge']);
            if (is_wp_error($written)) {
                return $written;
            }
            $steps = $written[0];

            return true;
        }, static function () use (&$steps) {
            foreach (array_reverse($steps) as $step) {
                self::undo_step($step);
            }
        });
        if (is_wp_error($guarded)) {
            return $guarded;
        }
        self::purge();
        $change = self::remember($steps);
        MoxDOP_Connector_AI::record($caller, 'apply_setup', 0, 'Hazır kurulum: '.$name, $change, 'ai');

        return ['setup' => $name, 'does' => $recipe['does'], 'options' => array_keys($recipe['options']), 'change_id' => $change, 'site_check' => $guarded['site_check']];
    }

    /** @return string[] what was emptied */
    private static function purge()
    {
        $done = [];
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $done[] = 'litespeed';
        }
        foreach (['rocket_clean_domain' => 'wp-rocket', 'w3tc_flush_all' => 'w3tc', 'wp_cache_clear_cache' => 'wp-super-cache', 'wpfc_clear_all_cache' => 'wp-fastest-cache'] as $function => $label) {
            if (function_exists($function)) {
                $function();
                $done[] = $label;
            }
        }
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
            Cache_Enabler::clear_complete_cache();
            $done[] = 'cache-enabler';
        }
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
            $done[] = 'autoptimize';
        }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
            $done[] = 'elementor-css';
        }
        wp_cache_flush();
        $done[] = 'object-cache';

        return $done;
    }

    private function tool_clear_cache(array $args, array $caller)
    {
        $done = self::purge();
        MoxDOP_Connector_AI::record($caller, 'clear_cache', 0, 'Önbellek temizlendi: '.implode(', ', $done));

        return ['cleared' => $done];
    }

    private function tool_site_check(array $args)
    {
        return self::check(is_array($args['paths'] ?? null) ? $args['paths'] : []);
    }

    /* --------------------------------------------------------- tools: private */

    private function tool_list_comments(array $args)
    {
        $status = in_array($args['status'] ?? 'all', ['all', 'hold', 'approve', 'spam'], true) ? (string) ($args['status'] ?? 'all') : 'all';
        $query = ['status' => $status, 'number' => 30, 'offset' => 30 * (max(1, (int) ($args['page'] ?? 1)) - 1), 'orderby' => 'comment_date_gmt', 'order' => 'DESC'];
        if (! empty($args['post_id'])) {
            $query['post_id'] = (int) $args['post_id'];
        }

        return ['comments' => array_map(static function ($c) {
            return ['id' => (int) $c->comment_ID, 'post_id' => (int) $c->comment_post_ID, 'post' => get_the_title((int) $c->comment_post_ID), 'author' => $c->comment_author,
                'email' => $c->comment_author_email, 'date' => $c->comment_date, 'status' => wp_get_comment_status($c), 'parent' => (int) $c->comment_parent, 'text' => $c->comment_content];
        }, get_comments($query))];
    }

    private function tool_reply_comment(array $args, array $caller)
    {
        $parent = get_comment((int) ($args['id'] ?? 0));
        $text = trim(wp_kses_post((string) ($args['text'] ?? '')));
        if (! $parent || $text === '' || ! current_user_can('moderate_comments')) {
            return new WP_Error('invalid', 'Yorum yok, metin boş ya da yetki yok.');
        }
        $user = wp_get_current_user();
        $id = wp_insert_comment(['comment_post_ID' => (int) $parent->comment_post_ID, 'comment_parent' => (int) $parent->comment_ID, 'comment_content' => $text,
            'user_id' => $user->ID, 'comment_author' => $user->display_name, 'comment_author_email' => $user->user_email, 'comment_approved' => 1]);
        if (! $id) {
            return new WP_Error('failed', 'Yanıt kaydedilmedi.');
        }
        if (wp_get_comment_status($parent) === 'unapproved') {
            wp_set_comment_status($parent, 'approve');
        }
        $change = self::remember([['trash_comment', (int) $id]]);
        MoxDOP_Connector_AI::record($caller, 'reply_comment', (int) $parent->comment_post_ID, 'Yoruma yanıt ('.$parent->comment_author.')', $change, 'ai');

        return ['id' => (int) $id, 'change_id' => $change];
    }

    private function tool_moderate_comment(array $args, array $caller)
    {
        $comment = get_comment((int) ($args['id'] ?? 0));
        $action = (string) ($args['action'] ?? '');
        if (! $comment || ! in_array($action, ['approve', 'hold', 'spam'], true) || ! current_user_can('moderate_comments')) {
            return new WP_Error('invalid', 'Yorum yok, işlem geçersiz ya da yetki yok.');
        }
        $old = wp_get_comment_status($comment);
        $old = $old === 'approved' ? 'approve' : ($old === 'unapproved' ? 'hold' : $old);
        if (! wp_set_comment_status($comment, $action)) {
            return new WP_Error('failed', 'Değiştirilemedi.');
        }
        $change = self::remember([['comment_status', (int) $comment->comment_ID, (string) $old]]);
        MoxDOP_Connector_AI::record($caller, 'moderate_comment', (int) $comment->comment_post_ID, 'Yorum '.$action.' ('.$comment->comment_author.')', $change, 'ai');

        return ['id' => (int) $comment->comment_ID, 'status' => $action, 'change_id' => $change];
    }

    private function tool_list_form_messages(array $args)
    {
        global $wpdb;
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $out = [];
        if (post_type_exists('flamingo_inbound')) {
            foreach (get_posts(['post_type' => 'flamingo_inbound', 'post_status' => 'any', 'numberposts' => $limit]) as $post) {
                $fields = [];
                foreach ((array) get_post_meta($post->ID) as $key => $value) {
                    if (strpos($key, '_field_') === 0) {
                        $fields[substr($key, 7)] = maybe_unserialize($value[0] ?? '');
                    }
                }
                $out[] = ['source' => 'flamingo', 'date' => $post->post_date, 'subject' => $post->post_title, 'from' => get_post_meta($post->ID, '_from', true), 'fields' => $fields];
            }
        }
        $table = $wpdb->prefix.'e_submissions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT id, form_name, referer, created_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit)) as $row) {
                $values = $wpdb->get_results($wpdb->prepare("SELECT `key`, `value` FROM {$table}_values WHERE submission_id = %d", (int) $row->id));
                $fields = [];
                foreach ((array) $values as $value) {
                    $fields[$value->key] = $value->value;
                }
                $out[] = ['source' => 'elementor', 'date' => $row->created_at, 'subject' => $row->form_name, 'page' => $row->referer, 'fields' => $fields];
            }
        }
        usort($out, static function ($a, $b) {
            return strcmp((string) $b['date'], (string) $a['date']);
        });

        return ['messages' => array_slice($out, 0, $limit), 'note' => $out === [] ? 'Sitede saklanan form mesajı yok (Contact Form 7 mesajları için Flamingo eklentisi gerekir).' : null];
    }
}
