<?php

use Elementor\Plugin;
use ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache;

defined('ABSPATH') || exit;

/**
 * Connector 1.8.0: site building, OFF until a site administrator enables "Site building" in Settings › MoxDOP Connector.
 *
 * GET /build describes the site (ACF / Elementor, post types, ACF field groups, templates, menus, settings and what the
 * builder already made). POST /build applies a list of operations in order and answers one result per operation:
 * - acf_import: ACF export JSON (field groups, post types, taxonomies, options pages), like ACF › Tools › Import.
 * - post: creates or updates a page, post or custom post: title, slug, parent, status, content, page template, ACF
 *   values, Elementor data, featured image, terms, SEO title / description.
 * - elementor_template: an Elementor library template (the Templates › Import JSON), optionally shown by conditions
 *   (Elementor Pro theme builder).
 * - media: an image from a public URL or base64, with alt text.
 * - menu: a navigation menu with its items, optionally assigned to a theme location.
 * - settings: site title, tagline, front page, posts page, permalink structure, Elementor post types.
 * - trash: removes something the builder made.
 * Everything the builder makes carries a `ref` (post meta), so sending the same operation again updates it instead of
 * making a copy, and a later operation can point at it with "ref:<ref>" (parent, featured image, menu item, ACF value,
 * Elementor image / link). Nothing edits theme or plugin files.
 */
final class MoxDOP_Connector_Builder
{
    const REF_META = '_moxdop_build_ref';

    const LOG_OPTION = 'moxdop_connector_build_log';

    const FLUSH_OPTION = 'moxdop_connector_build_flush';

    const MAX_OPERATIONS = 25;

    const MAX_LOG = 500;

    const MAX_MEDIA_BYTES = 10485760;

    const SETTINGS = ['blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'elementor_cpt_support'];

    /** Post types the "post" operation never writes (they have their own operation or belong to WordPress / plugins). */
    const BLOCKED_TYPES = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'elementor_library',
    ];

    /** Elementor library types; those after "page" need Elementor Pro. */
    const TEMPLATE_TYPES = ['section', 'container', 'page', 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'loop-item', 'popup'];

    private $auth;

    /** @var array<string, int> refs resolved in this request */
    private $refs = [];

    public function __construct(?MoxDOP_Connector_Auth $auth = null)
    {
        $this->auth = $auth;
    }

    public static function allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_build', get_option('moxdop_connector_allow_build', '0') === '1');
    }

    /** A post type imported through ACF exists from the next request on; its permalinks are flushed then, once. */
    public function register()
    {
        add_action('init', [$this, 'maybe_flush'], 999);
    }

    public function maybe_flush()
    {
        if (get_option(self::FLUSH_OPTION) === '1') {
            delete_option(self::FLUSH_OPTION);
            flush_rewrite_rules(false);
        }
    }

    public function register_routes($namespace)
    {
        register_rest_route($namespace, '/build', [
            ['methods' => WP_REST_Server::READABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'inspect']],
            ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'apply']],
        ]);
    }

    /* ------------------------------------------------------------------ REST */

    public function inspect(WP_REST_Request $request)
    {
        if (! self::allowed()) {
            return new WP_Error('moxdop_build_disabled', 'Site building is disabled on this site.', ['status' => 403]);
        }

        return $this->auth->envelope(['schema_version' => 1] + $this->describe(), $request);
    }

    public function apply(WP_REST_Request $request)
    {
        if (! self::allowed()) {
            return new WP_Error('moxdop_build_disabled', 'Site building is disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $operations = is_array($body) && is_array($body['operations'] ?? null) ? array_values($body['operations']) : [];
        if ($operations === [] || count($operations) > self::MAX_OPERATIONS) {
            return new WP_Error('moxdop_invalid_body', 'operations[] (1-'.self::MAX_OPERATIONS.') is required.', ['status' => 400]);
        }
        $results = [];
        foreach ($operations as $operation) {
            try {
                $result = is_array($operation) ? $this->run($operation) : $this->fail('invalid operation');
            } catch (Throwable $e) {
                $result = $this->fail($e->getMessage());
            }
            $result = ['op' => is_array($operation) ? sanitize_key((string) ($operation['op'] ?? '')) : ''] + $result;
            $results[] = $result;
            $this->log($result);
        }

        return $this->auth->envelope(['schema_version' => 1, 'results' => $results], $request);
    }

    /* ------------------------------------------------------------ operations */

    private function run(array $operation)
    {
        switch (sanitize_key((string) ($operation['op'] ?? ''))) {
            case 'acf_import':
                return $this->acf_import($operation);
            case 'post':
                return $this->post($operation);
            case 'elementor_template':
                return $this->elementor_template($operation);
            case 'media':
                return $this->media($operation);
            case 'menu':
                return $this->menu($operation);
            case 'settings':
                return $this->settings($operation);
            case 'trash':
                return $this->trash($operation);
        }

        return $this->fail('unknown op (acf_import, post, elementor_template, media, menu, settings, trash)');
    }

    /** ACF export JSON: the same steps as ACF › Tools › Import (an item with a known key is updated in place). */
    private function acf_import(array $operation)
    {
        if (! function_exists('acf_import_field_group') && ! function_exists('acf_import_internal_post_type')) {
            return $this->fail('ACF is not active');
        }
        $items = $operation['items'] ?? null;
        if (is_array($items) && isset($items['key'])) {
            $items = [$items];
        }
        if (! is_array($items) || $items === [] || count($items) > 50) {
            return $this->fail('items[] (1-50 ACF export objects) is required');
        }
        $out = [];
        $flush = false;
        foreach (array_values($items) as $item) {
            $key = is_array($item) ? sanitize_text_field((string) ($item['key'] ?? '')) : '';
            if ($key === '') {
                $out[] = ['ok' => false, 'error' => 'item without key'];

                continue;
            }
            $type = function_exists('acf_determine_internal_post_type') ? acf_determine_internal_post_type($key) : (strpos($key, 'group_') === 0 ? 'acf-field-group' : false);
            if (! $type || ($type !== 'acf-field-group' && ! function_exists('acf_import_internal_post_type'))) {
                $out[] = ['ok' => false, 'key' => $key, 'error' => $type ? 'needs ACF 6.1 or newer' : 'unknown ACF key prefix (group_, post_type_, taxonomy_, ui_options_page_)'];

                continue;
            }
            if (function_exists('acf_get_internal_post_type_post')) {
                $existing = acf_get_internal_post_type_post($key, $type);
            } else {
                $existing = function_exists('acf_get_field_group_post') ? acf_get_field_group_post($key) : null;
            }
            if ($existing) {
                $item['ID'] = $existing->ID;
            }
            $imported = function_exists('acf_import_internal_post_type') ? acf_import_internal_post_type($item, $type) : acf_import_field_group($item);
            $flush = $flush || in_array($type, ['acf-post-type', 'acf-taxonomy'], true);
            $out[] = ['ok' => is_array($imported) && ! empty($imported['ID']), 'key' => $key, 'type' => $type, 'id' => is_array($imported) ? (int) ($imported['ID'] ?? 0) : 0, 'updated' => (bool) $existing];
        }
        if ($flush) {
            update_option(self::FLUSH_OPTION, '1', true);
        }
        $ok = count(array_filter($out, function ($r) {
            return $r['ok'];
        }));

        return ['ok' => $ok === count($out), 'items' => $out, 'note' => $flush ? 'New post types / taxonomies exist from the next request: send their posts in a later call.' : null];
    }

    /** Creates or updates one page / post / custom post, matched by its ref. */
    private function post(array $operation)
    {
        $ref = $this->ref($operation);
        if ($ref === '') {
            return $this->fail('ref is required (letters, digits, - _ . :)');
        }
        $type = sanitize_key((string) ($operation['post_type'] ?? 'page'));
        if (! post_type_exists($type) || in_array($type, self::BLOCKED_TYPES, true)) {
            return $this->fail('post type "'.$type.'" does not exist here or cannot be written');
        }
        $existing = $this->find($ref);
        if ($existing && get_post_type($existing) !== $type) {
            return $this->fail('ref "'.$ref.'" already belongs to a '.get_post_type($existing));
        }
        $fields = ['post_type' => $type];
        $status = (string) ($operation['status'] ?? ($existing ? '' : 'draft'));
        if ($status !== '') {
            $fields['post_status'] = in_array($status, ['draft', 'publish', 'pending', 'private'], true) ? $status : 'draft';
        }
        if (isset($operation['title'])) {
            $fields['post_title'] = sanitize_text_field((string) $operation['title']);
        }
        if (isset($operation['slug'])) {
            $fields['post_name'] = sanitize_title((string) $operation['slug']);
        }
        if (isset($operation['content'])) {
            $fields['post_content'] = wp_kses_post((string) $operation['content']);
        }
        if (isset($operation['excerpt'])) {
            $fields['post_excerpt'] = sanitize_textarea_field((string) $operation['excerpt']);
        }
        if (isset($operation['menu_order'])) {
            $fields['menu_order'] = (int) $operation['menu_order'];
        }
        if (array_key_exists('parent', $operation)) {
            $parent = $this->id_of($operation['parent']);
            if ($operation['parent'] && ! $parent) {
                return $this->fail('parent not found: '.wp_json_encode($operation['parent']));
            }
            $fields['post_parent'] = (int) $parent;
        }
        if ($existing) {
            $fields['ID'] = $existing;
            $post_id = wp_update_post(wp_slash($fields), true);
        } else {
            if (! isset($fields['post_title'])) {
                return $this->fail('title is required for a new post');
            }
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $fields['post_author'] = ! empty($admins) ? (int) $admins[0] : 0;
            $post_id = wp_insert_post(wp_slash($fields), true);
        }
        if (is_wp_error($post_id) || ! $post_id) {
            return $this->fail(is_wp_error($post_id) ? $post_id->get_error_message() : 'post was not saved');
        }
        $post_id = (int) $post_id;
        update_post_meta($post_id, self::REF_META, $ref);
        $this->refs[$ref] = $post_id;
        $warnings = [];

        if (isset($operation['template'])) {
            update_post_meta($post_id, '_wp_page_template', sanitize_text_field((string) $operation['template']));
        }
        if (array_key_exists('featured_image', $operation)) {
            $image = $this->id_of($operation['featured_image']);
            $image ? set_post_thumbnail($post_id, $image) : delete_post_thumbnail($post_id);
        }
        if (is_array($operation['terms'] ?? null)) {
            foreach ($operation['terms'] as $taxonomy => $names) {
                $taxonomy = sanitize_key((string) $taxonomy);
                if (! taxonomy_exists($taxonomy) || ! is_object_in_taxonomy($type, $taxonomy)) {
                    $warnings[] = 'taxonomy '.$taxonomy.' is not available for '.$type;

                    continue;
                }
                wp_set_object_terms($post_id, array_map('sanitize_text_field', array_map('strval', (array) $names)), $taxonomy);
            }
        }
        if (is_array($operation['acf'] ?? null)) {
            if (! function_exists('update_field')) {
                $warnings[] = 'ACF is not active: acf values were not saved';
            } else {
                foreach ($operation['acf'] as $name => $value) {
                    $name = sanitize_text_field((string) $name);
                    if ($name === '' || ! acf_get_field($name)) {
                        $warnings[] = 'ACF field not found: '.$name;

                        continue;
                    }
                    update_field($name, $this->resolve($value, $name), $post_id);
                }
            }
        }
        if (isset($operation['elementor'])) {
            $written = $this->write_elementor($post_id, $operation['elementor'], 'wp-'.($type === 'page' ? 'page' : 'post'));
            if (is_wp_error($written)) {
                $warnings[] = $written->get_error_message();
            }
        }
        if (is_array($operation['seo'] ?? null)) {
            $fixes = new MoxDOP_Connector_Fixes;
            foreach (['title' => 120, 'description' => 320] as $field => $limit) {
                if (isset($operation['seo'][$field])) {
                    update_post_meta($post_id, $fixes->seo_meta_key($field), mb_substr(sanitize_text_field((string) $operation['seo'][$field]), 0, $limit));
                }
            }
        }

        return ['ok' => true, 'ref' => $ref, 'id' => $post_id, 'created' => ! $existing, 'post_type' => $type, 'status' => get_post_status($post_id),
            'url' => (string) get_permalink($post_id), 'edit_url' => admin_url('post.php?post='.$post_id.'&action=edit'), 'warnings' => $warnings];
    }

    /** An Elementor library template (header, footer, section, …) from the Templates › Import JSON or a list of elements. */
    private function elementor_template(array $operation)
    {
        if (! defined('ELEMENTOR_VERSION')) {
            return $this->fail('Elementor is not active');
        }
        $ref = $this->ref($operation);
        if ($ref === '') {
            return $this->fail('ref is required');
        }
        $template = $operation['template'] ?? null;
        if (is_string($template)) {
            $template = json_decode($template, true);
        }
        $type = sanitize_key((string) ($operation['type'] ?? (is_array($template) ? ($template['type'] ?? '') : '')));
        if (! in_array($type, self::TEMPLATE_TYPES, true)) {
            return $this->fail('type must be one of '.implode(', ', self::TEMPLATE_TYPES));
        }
        if (array_search($type, self::TEMPLATE_TYPES, true) > 2 && ! defined('ELEMENTOR_PRO_VERSION')) {
            return $this->fail('a '.$type.' template needs Elementor Pro');
        }
        $existing = $this->find($ref);
        if ($existing && get_post_type($existing) !== 'elementor_library') {
            return $this->fail('ref "'.$ref.'" already belongs to a '.get_post_type($existing));
        }
        $title = sanitize_text_field((string) ($operation['title'] ?? (is_array($template) ? ($template['title'] ?? '') : '')));
        if ($title === '' && ! $existing) {
            return $this->fail('title is required');
        }
        $fields = ['post_type' => 'elementor_library', 'post_status' => 'publish'];
        if ($title !== '') {
            $fields['post_title'] = $title;
        }
        if ($existing) {
            $fields['ID'] = $existing;
            $id = wp_update_post(wp_slash($fields), true);
        } else {
            $id = wp_insert_post(wp_slash($fields), true);
        }
        if (is_wp_error($id) || ! $id) {
            return $this->fail(is_wp_error($id) ? $id->get_error_message() : 'template was not saved');
        }
        $id = (int) $id;
        update_post_meta($id, self::REF_META, $ref);
        $this->refs[$ref] = $id;
        if (taxonomy_exists('elementor_library_type')) {
            wp_set_object_terms($id, $type, 'elementor_library_type');
        }
        // Without "template" an existing template keeps its content (for example only its conditions change).
        $written = $template === null && $existing ? true : $this->write_elementor($id, $template, $type);
        if (is_wp_error($written)) {
            return $this->fail($written->get_error_message()) + ['id' => $id, 'ref' => $ref];
        }
        $warnings = [];
        if (isset($operation['conditions'])) {
            $conditions = array_values(array_filter(array_map('strval', (array) $operation['conditions']), function ($c) {
                return (bool) preg_match('#^(include|exclude)/[a-z0-9_/\-]+$#', $c);
            }));
            if (! defined('ELEMENTOR_PRO_VERSION')) {
                $warnings[] = 'display conditions need Elementor Pro';
            } else {
                $conditions === [] ? delete_post_meta($id, '_elementor_conditions') : update_post_meta($id, '_elementor_conditions', $conditions);
                if (class_exists('\ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache')) {
                    (new Conditions_Cache)->regenerate();
                }
            }
        }

        return ['ok' => true, 'ref' => $ref, 'id' => $id, 'created' => ! $existing, 'type' => $type, 'conditions' => get_post_meta($id, '_elementor_conditions', true) ?: [],
            'edit_url' => admin_url('post.php?post='.$id.'&action=elementor'), 'warnings' => $warnings];
    }

    /** An image from a public URL (the site downloads it) or base64, matched by its ref. */
    private function media(array $operation)
    {
        $ref = $this->ref($operation);
        if ($ref === '') {
            return $this->fail('ref is required');
        }
        $existing = $this->find($ref);
        $created = ! $existing;
        if ($created) {
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            $name = sanitize_file_name((string) ($operation['filename'] ?? ''));
            if (! empty($operation['url'])) {
                $url = esc_url_raw((string) $operation['url']);
                if ($url === '' || wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    return $this->fail('url must be https');
                }
                $tmp = download_url($url, 60);
                if (is_wp_error($tmp)) {
                    return $this->fail('download failed: '.$tmp->get_error_message());
                }
                if ($name === '') {
                    $name = sanitize_file_name(wp_basename((string) wp_parse_url($url, PHP_URL_PATH)));
                }
            } elseif (! empty($operation['data_base64'])) {
                $bytes = base64_decode((string) $operation['data_base64'], true);
                if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_MEDIA_BYTES || $name === '') {
                    return $this->fail('data_base64 (at most 10 MB) and filename are required');
                }
                $tmp = wp_tempnam($name);
                file_put_contents($tmp, $bytes);
            } else {
                return $this->fail('url or data_base64 is required');
            }
            if (filesize($tmp) > self::MAX_MEDIA_BYTES) {
                @unlink($tmp);

                return $this->fail('file is larger than 10 MB');
            }
            $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0, isset($operation['title']) ? sanitize_text_field((string) $operation['title']) : null);
            if (is_wp_error($id)) {
                @unlink($tmp);

                return $this->fail($id->get_error_message());
            }
            $existing = (int) $id;
            update_post_meta($existing, self::REF_META, $ref);
        } elseif (get_post_type($existing) !== 'attachment') {
            return $this->fail('ref "'.$ref.'" already belongs to a '.get_post_type($existing));
        }
        $this->refs[$ref] = $existing;
        if (isset($operation['alt'])) {
            update_post_meta($existing, '_wp_attachment_image_alt', mb_substr(sanitize_text_field((string) $operation['alt']), 0, 250));
        }

        return ['ok' => true, 'ref' => $ref, 'id' => $existing, 'url' => (string) wp_get_attachment_url($existing), 'created' => $created];
    }

    /** A navigation menu; its items are replaced by the given ones. */
    private function menu(array $operation)
    {
        $name = sanitize_text_field((string) ($operation['name'] ?? ''));
        $items = is_array($operation['items'] ?? null) ? $operation['items'] : null;
        if ($name === '' || $items === null) {
            return $this->fail('name and items[] are required');
        }
        $menu = wp_get_nav_menu_object($name);
        $menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu($name);
        if (is_wp_error($menu_id)) {
            return $this->fail($menu_id->get_error_message());
        }
        $old_items = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
        foreach (is_array($old_items) ? $old_items : [] as $old) {
            wp_delete_post($old->ID, true);
        }
        $count = 0;
        $errors = [];
        $this->menu_items((int) $menu_id, $items, 0, $count, $errors);
        $location = sanitize_key((string) ($operation['location'] ?? ''));
        if ($location !== '') {
            if (! array_key_exists($location, get_registered_nav_menus())) {
                $errors[] = 'theme has no menu location '.$location;
            } else {
                $locations = (array) get_theme_mod('nav_menu_locations', []);
                $locations[$location] = (int) $menu_id;
                set_theme_mod('nav_menu_locations', $locations);
            }
        }

        return ['ok' => $errors === [], 'menu_id' => (int) $menu_id, 'items' => $count, 'location' => $location ?: null, 'errors' => $errors];
    }

    private function menu_items($menu_id, array $items, $parent, &$count, array &$errors)
    {
        foreach (array_values($items) as $item) {
            if (! is_array($item) || $count >= 100) {
                continue;
            }
            $data = ['menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $parent, 'menu-item-title' => sanitize_text_field((string) ($item['title'] ?? ''))];
            $target = isset($item['ref']) ? $this->find((string) $item['ref']) : absint($item['post_id'] ?? 0);
            if ($target) {
                $data += ['menu-item-type' => 'post_type', 'menu-item-object' => get_post_type($target), 'menu-item-object-id' => $target];
            } elseif (! empty($item['url'])) {
                $data += ['menu-item-type' => 'custom', 'menu-item-url' => esc_url_raw((string) $item['url'])];
            } else {
                $errors[] = 'menu item without a target: '.$data['menu-item-title'];

                continue;
            }
            $item_id = wp_update_nav_menu_item($menu_id, 0, $data);
            if (is_wp_error($item_id)) {
                $errors[] = $item_id->get_error_message();

                continue;
            }
            $count++;
            if (is_array($item['children'] ?? null)) {
                $this->menu_items($menu_id, $item['children'], (int) $item_id, $count, $errors);
            }
        }
    }

    /** A few site settings a new site needs; nothing else. */
    private function settings(array $operation)
    {
        $values = is_array($operation['values'] ?? null) ? $operation['values'] : [];
        $changed = [];
        $errors = [];
        foreach ($values as $key => $value) {
            if (! in_array($key, self::SETTINGS, true)) {
                $errors[] = 'not allowed: '.$key;

                continue;
            }
            switch ($key) {
                case 'page_on_front':
                case 'page_for_posts':
                    $value = (int) $this->id_of($value);
                    if ($value && get_post_type($value) !== 'page') {
                        $errors[] = $key.' must be a page';

                        continue 2;
                    }
                    break;
                case 'show_on_front':
                    $value = $value === 'page' ? 'page' : 'posts';
                    break;
                case 'permalink_structure':
                    $value = '/'.trim(preg_replace('#[^a-z0-9%/_\-]#i', '', (string) $value), '/').'/';
                    $value = $value === '//' ? '' : $value;
                    break;
                case 'elementor_cpt_support':
                    $value = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) $value)), 'post_type_exists'));
                    break;
                default:
                    $value = sanitize_text_field((string) $value);
            }
            if ($key === 'permalink_structure') {
                global $wp_rewrite;
                $wp_rewrite->set_permalink_structure($value);
                update_option(self::FLUSH_OPTION, '1', true);
            } else {
                update_option($key, $value);
            }
            $changed[$key] = $value;
        }

        return ['ok' => $errors === [] && $changed !== [], 'changed' => $changed, 'errors' => $errors];
    }

    /** Moves something the builder made to the trash (media is deleted: WordPress has no media trash by default). */
    private function trash(array $operation)
    {
        $ref = $this->ref($operation);
        $id = $ref !== '' ? $this->find($ref) : 0;
        if (! $id) {
            return $this->fail('nothing built with this ref');
        }
        $done = get_post_type($id) === 'attachment' ? wp_delete_attachment($id, true) : wp_trash_post($id);
        unset($this->refs[$ref]);

        return $done ? ['ok' => true, 'ref' => $ref, 'id' => $id] : $this->fail('could not remove '.$id);
    }

    /* ---------------------------------------------------------------- helpers */

    /** What the builder needs to know before it changes anything. */
    private function describe()
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $name => $object) {
            if (in_array($name, self::BLOCKED_TYPES, true)) {
                continue;
            }
            $counts = wp_count_posts($name);
            $types[] = ['name' => $name, 'label' => (string) $object->label, 'hierarchical' => (bool) $object->hierarchical, 'public' => (bool) $object->public,
                'taxonomies' => array_values(get_object_taxonomies($name)), 'published' => (int) ($counts->publish ?? 0), 'drafts' => (int) ($counts->draft ?? 0)];
        }
        $groups = [];
        if (function_exists('acf_get_field_groups')) {
            foreach (acf_get_field_groups() as $group) {
                $fields = function_exists('acf_get_fields') ? (array) acf_get_fields($group['key']) : [];
                $groups[] = ['key' => $group['key'], 'title' => $group['title'], 'active' => (bool) $group['active'], 'location' => $group['location'],
                    'fields' => array_map(function ($f) {
                        return ['key' => $f['key'], 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type']];
                    }, array_slice($fields, 0, 80))];
            }
        }
        $acf_types = [];
        foreach (['acf-post-type', 'acf-taxonomy'] as $internal) {
            if (function_exists('acf_get_internal_post_type_posts')) {
                foreach ((array) acf_get_internal_post_type_posts($internal) as $item) {
                    $acf_types[] = ['kind' => $internal, 'key' => $item['key'] ?? '', 'title' => $item['title'] ?? '', 'name' => $item['post_type'] ?? ($item['taxonomy'] ?? ''), 'active' => ! empty($item['active'])];
                }
            }
        }
        $templates = [];
        foreach (get_posts(['post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => 100, 'suppress_filters' => true]) as $template) {
            $templates[] = ['id' => $template->ID, 'title' => $template->post_title, 'type' => (string) get_post_meta($template->ID, '_elementor_template_type', true),
                'conditions' => get_post_meta($template->ID, '_elementor_conditions', true) ?: [], 'ref' => (string) get_post_meta($template->ID, self::REF_META, true)];
        }
        $menus = [];
        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = ['id' => (int) $menu->term_id, 'name' => $menu->name, 'items' => (int) $menu->count];
        }
        $built = [];
        foreach (get_posts(['post_type' => array_values(get_post_types()), 'post_status' => 'any', 'meta_key' => self::REF_META, 'numberposts' => 300, 'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'ASC']) as $post) {
            $built[] = ['ref' => (string) get_post_meta($post->ID, self::REF_META, true), 'id' => $post->ID, 'post_type' => $post->post_type, 'status' => $post->post_status,
                'title' => $post->post_title, 'parent' => (int) $post->post_parent, 'url' => $post->post_type === 'attachment' ? (string) wp_get_attachment_url($post->ID) : (string) get_permalink($post->ID)];
        }
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $settings[$key] = get_option($key);
        }
        $theme = wp_get_theme();

        return [
            'acf' => ['active' => function_exists('acf_get_field_groups'), 'version' => defined('ACF_VERSION') ? ACF_VERSION : null, 'pro' => defined('ACF_PRO') && ACF_PRO],
            'elementor' => ['active' => defined('ELEMENTOR_VERSION'), 'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                'pro' => defined('ELEMENTOR_PRO_VERSION'), 'pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null],
            'theme' => ['stylesheet' => get_stylesheet(), 'template' => get_template(), 'name' => (string) $theme->get('Name'),
                'page_templates' => $theme->get_page_templates(null, 'page'), 'menu_locations' => get_registered_nav_menus(), 'assigned_menus' => get_nav_menu_locations()],
            'settings' => $settings,
            'post_types' => $types,
            'acf_field_groups' => $groups,
            'acf_types' => $acf_types,
            'elementor_templates' => $templates,
            'menus' => $menus,
            'built' => $built,
            'limits' => ['operations_per_request' => self::MAX_OPERATIONS, 'media_bytes' => self::MAX_MEDIA_BYTES],
        ];
    }

    /** Writes Elementor data (export object with content / page_settings, or a plain element list). */
    private function write_elementor($post_id, $data, $template_type)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        $settings = [];
        if (is_array($data) && isset($data['content']) && is_array($data['content'])) {
            $settings = is_array($data['page_settings'] ?? null) ? $data['page_settings'] : [];
            $data = $data['content'];
        }
        if (! is_array($data) || array_values($data) !== $data) {
            return new WP_Error('bad', 'elementor data must be an element list or a Templates › Import object with "content"');
        }
        $data = $this->resolve($data, '');
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $template_type);
        update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        $settings === [] ? delete_post_meta($post_id, '_elementor_page_settings') : update_post_meta($post_id, '_elementor_page_settings', $this->resolve($settings, ''));
        delete_post_meta($post_id, '_elementor_css');
        if (defined('ELEMENTOR_VERSION') && class_exists('\Elementor\Plugin') && isset(Plugin::$instance->files_manager)) {
            Plugin::$instance->files_manager->clear_cache();
        }

        return true;
    }

    /** "ref:<ref>" anywhere in a value: the id of what the builder made, or its URL under a "url" key. */
    private function resolve($value, $key)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolve($v, (string) $k);
            }

            return $value;
        }
        if (! is_string($value) || strpos($value, 'ref:') !== 0) {
            return $value;
        }
        $id = $this->find(substr($value, 4));
        if (! $id) {
            return $value;
        }
        if ($key === 'url') {
            return get_post_type($id) === 'attachment' ? (string) wp_get_attachment_url($id) : (string) get_permalink($id);
        }

        return $id;
    }

    /** A post id, or "ref:<ref>" / a bare ref of something the builder made. */
    private function id_of($value)
    {
        if (is_numeric($value)) {
            return get_post((int) $value) ? (int) $value : 0;
        }
        $value = (string) $value;

        return $value === '' ? 0 : $this->find(strpos($value, 'ref:') === 0 ? substr($value, 4) : $value);
    }

    private function ref(array $operation)
    {
        $ref = (string) ($operation['ref'] ?? '');

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,79}$/', $ref) ? $ref : '';
    }

    private function find($ref)
    {
        if (isset($this->refs[$ref])) {
            return $this->refs[$ref];
        }
        $ids = get_posts(['post_type' => array_values(get_post_types()), 'post_status' => 'any', 'meta_key' => self::REF_META, 'meta_value' => $ref,
            'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'lang' => '']);

        return ! empty($ids) ? (int) $ids[0] : 0;
    }

    private function fail($message)
    {
        return ['ok' => false, 'error' => mb_substr((string) $message, 0, 500)];
    }

    private function log(array $result)
    {
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        $log[] = ['at' => gmdate('c'), 'op' => $result['op'] ?? '', 'ref' => $result['ref'] ?? null, 'id' => $result['id'] ?? null, 'ok' => (bool) ($result['ok'] ?? false), 'error' => $result['error'] ?? null];
        update_option(self::LOG_OPTION, array_slice($log, -self::MAX_LOG), false);
    }
}
