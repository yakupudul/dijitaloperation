<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_REST_Controller
{
    const NAMESPACE = 'moxdop/v1';

    private $auth;

    public function __construct(MoxDOP_Connector_Auth $auth)
    {
        $this->auth = $auth;
    }

    public function register_routes()
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => [$this->auth, 'authorize'],
            'callback' => [$this, 'status'],
        ]);
        register_rest_route(self::NAMESPACE, '/snapshot', [
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => [$this->auth, 'authorize'],
            'callback' => [$this, 'snapshot'],
            'args' => [
                'section' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['site', 'extensions', 'content', 'media', 'taxonomies', 'seo'],
                ],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
    }

    public function status(WP_REST_Request $request)
    {
        return $this->auth->envelope([
            'schema_version' => 1,
            'plugin_version' => MOXDOP_CONNECTOR_VERSION,
            'installation_id' => (string) get_option('moxdop_connector_installation_id'),
            'site_url' => site_url('/'),
            'home_url' => home_url('/'),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'read_only' => true,
            'sections' => ['site', 'extensions', 'content', 'media', 'taxonomies', 'seo'],
            'server_time' => time(),
        ], $request);
    }

    public function snapshot(WP_REST_Request $request)
    {
        $section = sanitize_key((string) $request->get_param('section'));
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));

        switch ($section) {
            case 'site':
                $result = $this->site_snapshot();
                break;
            case 'extensions':
                $result = $this->extension_snapshot($page, $per_page);
                break;
            case 'content':
                $result = $this->content_snapshot($page, $per_page);
                break;
            case 'media':
                $result = $this->media_snapshot($page, $per_page);
                break;
            case 'taxonomies':
                $result = $this->taxonomy_snapshot($page, $per_page);
                break;
            case 'seo':
                $result = $this->seo_snapshot($page, $per_page);
                break;
            default:
                return new WP_Error('moxdop_unknown_section', 'Unknown connector section.', ['status' => 400]);
        }

        $result['schema_version'] = 1;
        $result['section'] = $section;
        $result['generated_at'] = gmdate('c');

        return $this->auth->envelope($result, $request);
    }

    private function site_snapshot()
    {
        $theme = wp_get_theme();
        $core_updates = get_site_transient('update_core');
        $core_offer = is_object($core_updates) && is_array($core_updates->updates ?? null)
            ? ($core_updates->updates[0] ?? null)
            : null;
        $health = get_transient('health-check-site-status-result');
        $health = is_array($health) ? [
            'good' => $this->health_count($health['good'] ?? null),
            'recommended' => $this->health_count($health['recommended'] ?? null),
            'critical' => $this->health_count($health['critical'] ?? null),
        ] : null;
        $record = [
            'site_key' => (string) get_option('moxdop_connector_installation_id'),
            'site_url' => site_url('/'),
            'home_url' => home_url('/'),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'core_update_available' => is_object($core_offer) && ($core_offer->response ?? '') === 'upgrade',
            'available_wordpress_version' => is_object($core_offer) ? ($core_offer->current ?? null) : null,
            'core_update_checked_at' => is_object($core_updates) && ! empty($core_updates->last_checked)
                ? gmdate('c', (int) $core_updates->last_checked)
                : null,
            'locale' => get_locale(),
            'timezone' => wp_timezone_string(),
            'active_theme' => $theme->get_stylesheet(),
            'active_theme_name' => $theme->get('Name'),
            'active_theme_version' => $theme->get('Version'),
            'is_multisite' => is_multisite(),
            'rest_state' => 'reachable',
            'cron_state' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'disabled' : 'enabled',
            'settings' => [
                'blog_public' => (bool) get_option('blog_public'),
                'permalink_structure' => (string) get_option('permalink_structure'),
                'show_on_front' => (string) get_option('show_on_front'),
                'page_on_front' => (int) get_option('page_on_front'),
                'page_for_posts' => (int) get_option('page_for_posts'),
                'posts_per_page' => (int) get_option('posts_per_page'),
                'uploads_use_yearmonth_folders' => (bool) get_option('uploads_use_yearmonth_folders'),
                'memory_limit' => (string) WP_MEMORY_LIMIT,
                'max_upload_size' => (int) wp_max_upload_size(),
            ],
            'features' => [
                'polylang' => defined('POLYLANG_VERSION'),
                'litespeed_cache' => defined('LSCWP_V'),
            ],
            'site_health_cached' => $health,
        ];

        return $this->page([$record], 1, 100, 1);
    }

    private function extension_snapshot($page, $per_page)
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        $updates = get_site_transient('update_plugins');
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_values(array_unique(array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])))));
        }
        $auto_plugins = (array) get_option('auto_update_plugins', []);
        $records = [];
        foreach (get_plugins() as $file => $plugin) {
            $available = isset($updates->response[$file]) ? (string) ($updates->response[$file]->new_version ?? '') : null;
            $records[] = [
                'extension_type' => 'plugin',
                'extension_id' => $file,
                'name' => (string) ($plugin['Name'] ?? $file),
                'version' => (string) ($plugin['Version'] ?? ''),
                'status' => in_array($file, $active, true) ? 'active' : 'inactive',
                'update_available' => ! empty($available),
                'available_version' => $available ?: null,
                'auto_update' => in_array($file, $auto_plugins, true),
                'update_checked_at' => is_object($updates) && ! empty($updates->last_checked)
                    ? gmdate('c', (int) $updates->last_checked)
                    : null,
            ];
        }

        $theme_updates = get_site_transient('update_themes');
        $auto_themes = (array) get_option('auto_update_themes', []);
        $active_stylesheet = get_stylesheet();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $available = isset($theme_updates->response[$stylesheet])
                ? (string) ($theme_updates->response[$stylesheet]['new_version'] ?? '')
                : null;
            $records[] = [
                'extension_type' => 'theme',
                'extension_id' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'status' => $stylesheet === $active_stylesheet ? 'active' : 'inactive',
                'update_available' => ! empty($available),
                'available_version' => $available ?: null,
                'auto_update' => in_array($stylesheet, $auto_themes, true),
                'update_checked_at' => is_object($theme_updates) && ! empty($theme_updates->last_checked)
                    ? gmdate('c', (int) $theme_updates->last_checked)
                    : null,
            ];
        }

        return $this->slice($records, $page, $per_page);
    }

    private function content_snapshot($page, $per_page)
    {
        $query = $this->content_query($page, $per_page);
        $records = [];
        foreach ($query->posts as $post) {
            // Blocks are rendered without running the entire `the_content` filter chain.
            // Public Discovery remains the source of truth for final theme/shortcode HTML.
            $rendered = function_exists('do_blocks') ? do_blocks($post->post_content) : $post->post_content;
            $elementor = (string) get_post_meta($post->ID, '_elementor_data', true);
            $records[] = [
                'object_type' => $post->post_type,
                'object_id' => (string) $post->ID,
                'status' => $post->post_status,
                'slug' => $post->post_name,
                'permalink' => get_permalink($post),
                'title' => get_the_title($post),
                'published_at' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format('c') : null,
                'modified_at' => get_post_datetime($post, 'modified', 'gmt') ? get_post_datetime($post, 'modified', 'gmt')->format('c') : null,
                'parent_id' => $post->post_parent ? (string) $post->post_parent : null,
                'template' => (string) get_page_template_slug($post),
                'featured_media_id' => get_post_thumbnail_id($post) ? (string) get_post_thumbnail_id($post) : null,
                'language' => $this->language($post->ID),
                'translations' => $this->translations($post->ID),
                'content_raw' => $post->post_content,
                'content_rendered' => $rendered,
                'content_hash' => hash('sha256', (string) $rendered),
                'content_length' => strlen((string) $rendered),
                'builder' => $elementor !== '' ? [
                    'provider' => 'elementor',
                    'content_raw' => $elementor,
                    'content_hash' => hash('sha256', $elementor),
                    'content_length' => strlen($elementor),
                ] : null,
            ];
        }

        return $this->page($records, $page, $per_page, (int) $query->found_posts);
    }

    private function media_snapshot($page, $per_page)
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
        ]);
        $records = [];
        foreach ($query->posts as $post) {
            $metadata = wp_get_attachment_metadata($post->ID);
            $records[] = [
                'object_type' => 'attachment',
                'object_id' => (string) $post->ID,
                'status' => $post->post_status,
                'slug' => $post->post_name,
                'permalink' => wp_get_attachment_url($post->ID),
                'title' => get_the_title($post),
                'published_at' => get_post_datetime($post, 'date', 'gmt') ? get_post_datetime($post, 'date', 'gmt')->format('c') : null,
                'modified_at' => get_post_datetime($post, 'modified', 'gmt') ? get_post_datetime($post, 'modified', 'gmt')->format('c') : null,
                'parent_id' => $post->post_parent ? (string) $post->post_parent : null,
                'mime_type' => $post->post_mime_type,
                'alt_text' => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'width' => is_array($metadata) ? ($metadata['width'] ?? null) : null,
                'height' => is_array($metadata) ? ($metadata['height'] ?? null) : null,
                'file' => is_array($metadata) ? ($metadata['file'] ?? null) : null,
                'file_size' => is_array($metadata) ? ($metadata['filesize'] ?? null) : null,
                'language' => $this->language($post->ID),
            ];
        }

        return $this->page($records, $page, $per_page, (int) $query->found_posts);
    }

    private function taxonomy_snapshot($page, $per_page)
    {
        $records = [];
        $taxonomies = get_taxonomies(['show_ui' => true], 'objects');
        ksort($taxonomies, SORT_STRING);
        $total = 0;
        $wanted_offset = ($page - 1) * $per_page;
        $remaining = $per_page;
        foreach ($taxonomies as $taxonomy) {
            $taxonomy_total = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);
            if (is_wp_error($taxonomy_total)) {
                continue;
            }
            $taxonomy_total = (int) $taxonomy_total;
            $taxonomy_start = $total;
            $total += $taxonomy_total;
            if ($remaining < 1 || $wanted_offset >= $taxonomy_start + $taxonomy_total) {
                continue;
            }

            $local_offset = max(0, $wanted_offset - $taxonomy_start);
            $terms = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'number' => $remaining,
                'offset' => $local_offset,
                'orderby' => 'term_id',
                'order' => 'ASC',
            ]);
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $records[] = [
                    'taxonomy' => $taxonomy->name,
                    'term_id' => (string) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent_id' => $term->parent ? (string) $term->parent : null,
                    'content_count' => (int) $term->count,
                    'language' => function_exists('pll_get_term_language') ? pll_get_term_language($term->term_id, 'slug') : null,
                ];
                $remaining--;
            }
        }

        return $this->page($records, $page, $per_page, $total);
    }

    private function seo_snapshot($page, $per_page)
    {
        $query = $this->content_query($page, $per_page);
        $records = [];
        foreach ($query->posts as $post) {
            $fields = $this->seo_fields($post->ID);
            $records[] = array_merge([
                'object_type' => $post->post_type,
                'object_id' => (string) $post->ID,
                'permalink' => get_permalink($post),
                'language' => $this->language($post->ID),
            ], $fields);
        }

        return $this->page($records, $page, $per_page, (int) $query->found_posts);
    }

    private function content_query($page, $per_page)
    {
        $types = get_post_types(['show_ui' => true], 'names');
        unset($types['attachment'], $types['wp_block'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation']);

        return new WP_Query([
            'post_type' => array_values($types),
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
        ]);
    }

    private function seo_fields($post_id)
    {
        $providers = [
            'yoast' => ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'canonical' => '_yoast_wpseo_canonical', 'robots' => '_yoast_wpseo_meta-robots-noindex'],
            'rank_math' => ['title' => 'rank_math_title', 'description' => 'rank_math_description', 'canonical' => 'rank_math_canonical_url', 'robots' => 'rank_math_robots'],
            'seopress' => ['title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'canonical' => '_seopress_robots_canonical', 'robots' => '_seopress_robots_index'],
        ];
        foreach ($providers as $provider => $keys) {
            $values = [];
            foreach ($keys as $field => $key) {
                $value = get_post_meta($post_id, $key, true);
                $values[$field] = is_array($value) ? implode(',', array_map('sanitize_text_field', $value)) : (string) $value;
            }
            if (implode('', $values) !== '') {
                return [
                    'seo_provider' => $provider,
                    'seo_title' => $values['title'] ?: null,
                    'meta_description' => $values['description'] ?: null,
                    'canonical_url' => $values['canonical'] ?: null,
                    'robots' => $values['robots'] ?: null,
                ];
            }
        }

        return ['seo_provider' => null, 'seo_title' => null, 'meta_description' => null, 'canonical_url' => null, 'robots' => null];
    }

    private function language($post_id)
    {
        return function_exists('pll_get_post_language') ? pll_get_post_language($post_id, 'slug') : null;
    }

    private function translations($post_id)
    {
        return function_exists('pll_get_post_translations') ? (array) pll_get_post_translations($post_id) : [];
    }

    private function health_count($value)
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return is_countable($value) ? count($value) : null;
    }

    private function slice(array $records, $page, $per_page)
    {
        return $this->page(array_slice($records, ($page - 1) * $per_page, $per_page), $page, $per_page, count($records));
    }

    private function page(array $records, $page, $per_page, $total)
    {
        return [
            'records' => array_values($records),
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'total' => (int) $total,
            'has_more' => $page * $per_page < $total,
        ];
    }
}
