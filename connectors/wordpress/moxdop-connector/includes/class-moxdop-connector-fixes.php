<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.4.0: approved SEO / technical fixes and content updates (ADR-070).
 *
 * Everything here is OFF until a site administrator enables it in Settings › MoxDOP Connector:
 * - "SEO fixes": SEO title and meta description, image alt text, JSON-LD schema, 301 redirects, noindex and
 *   canonical, adding one internal link to a page.
 * - "Content updates": a new version of an existing page is written as a separate draft copy; it replaces the
 *   live page only when MoxDOP sends a second, separately approved "apply" request.
 * Every change stores the previous value in a change log; undo restores it only if nobody changed the value since.
 */
final class MoxDOP_Connector_Fixes
{
    const LOG_OPTION = 'moxdop_connector_change_log';

    const REDIRECTS_OPTION = 'moxdop_connector_redirects';

    const SITE_SCHEMA_OPTION = 'moxdop_connector_site_schema';

    const MAX_LOG = 3000;

    const SEO_KEYS = [
        'yoast' => ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'canonical' => '_yoast_wpseo_canonical', 'noindex' => '_yoast_wpseo_meta-robots-noindex'],
        'rank_math' => ['title' => 'rank_math_title', 'description' => 'rank_math_description', 'canonical' => 'rank_math_canonical_url', 'noindex' => 'rank_math_robots'],
        'seopress' => ['title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'canonical' => '_seopress_robots_canonical', 'noindex' => '_seopress_robots_index'],
        'moxdop' => ['title' => '_moxdop_seo_title', 'description' => '_moxdop_seo_description', 'canonical' => '_moxdop_canonical', 'noindex' => '_moxdop_noindex'],
    ];

    private $auth;

    public function __construct(?MoxDOP_Connector_Auth $auth = null)
    {
        $this->auth = $auth;
    }

    public static function fixes_allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_fixes', get_option('moxdop_connector_allow_fixes', '0') === '1');
    }

    public static function content_allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_content', get_option('moxdop_connector_allow_content', '0') === '1');
    }

    /** Front-end output for values MoxDOP keeps itself (no SEO plugin), schema and redirects. */
    public function register()
    {
        add_action('template_redirect', [$this, 'redirect'], 1);
        add_action('wp_head', [$this, 'head'], 2);
        add_filter('pre_get_document_title', [$this, 'document_title'], 20);
        add_filter('get_canonical_url', [$this, 'canonical'], 20, 2);
        add_filter('wp_robots', [$this, 'robots'], 20);
    }

    public function register_routes($namespace)
    {
        register_rest_route($namespace, '/fixes', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'apply']]);
        register_rest_route($namespace, '/fixes/undo', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'undo']]);
        register_rest_route($namespace, '/content-drafts', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'content_draft']]);
        register_rest_route($namespace, '/content-drafts/apply', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$this->auth, 'authorize'], 'callback' => [$this, 'content_apply']]);
    }

    /* ---------------------------------------------------------------- REST: fixes */

    public function apply(WP_REST_Request $request)
    {
        if (! self::fixes_allowed()) {
            return new WP_Error('moxdop_fixes_disabled', 'SEO fixes are disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $changes = is_array($body) && is_array($body['changes'] ?? null) ? array_slice($body['changes'], 0, 100) : null;
        if ($changes === null || $changes === []) {
            return new WP_Error('moxdop_invalid_body', 'changes[] is required.', ['status' => 400]);
        }
        $results = [];
        foreach ($changes as $change) {
            $results[] = is_array($change) ? $this->apply_one($change) : ['ok' => false, 'error' => 'invalid change'];
        }

        return $this->auth->envelope(['schema_version' => 1, 'results' => $results], $request);
    }

    public function undo(WP_REST_Request $request)
    {
        $body = json_decode((string) $request->get_body(), true);
        $ids = is_array($body) && is_array($body['change_ids'] ?? null) ? array_slice(array_map('strval', $body['change_ids']), 0, 200) : [];
        $log = $this->log();
        $results = [];
        foreach ($ids as $id) {
            $entry = $log[$id] ?? null;
            if (! is_array($entry) || ! empty($entry['undone'])) {
                $results[] = ['change_id' => $id, 'ok' => false, 'error' => 'unknown or already undone'];

                continue;
            }
            $current = $this->read($entry['type'], $entry['target']);
            if ($this->fingerprint($current) !== $entry['after_hash']) {
                $results[] = ['change_id' => $id, 'ok' => false, 'error' => 'changed_since'];

                continue;
            }
            $this->write($entry['type'], $entry['target'], $this->previous($id, $entry));
            $log[$id]['undone'] = gmdate('c');
            $results[] = ['change_id' => $id, 'ok' => true];
        }
        update_option(self::LOG_OPTION, $log, false);

        return $this->auth->envelope(['schema_version' => 1, 'results' => $results], $request);
    }

    /** @return array{ok: bool, change_id?: string, before?: mixed, after?: mixed, error?: string} */
    private function apply_one(array $change)
    {
        $type = sanitize_key((string) ($change['type'] ?? ''));
        $target = $this->target($type, $change);
        if ($target === null) {
            return ['ok' => false, 'type' => $type, 'error' => 'invalid target'];
        }
        $value = $this->clean($type, $change['value'] ?? null, $target);
        if (is_wp_error($value)) {
            return ['ok' => false, 'type' => $type, 'error' => $value->get_error_message()];
        }
        $before = $this->read($type, $target);
        if ($before === $value) {
            return ['ok' => true, 'type' => $type, 'unchanged' => true, 'before' => $before, 'after' => $value];
        }
        $written = $this->write($type, $target, $value);
        if (is_wp_error($written)) {
            return ['ok' => false, 'type' => $type, 'error' => $written->get_error_message()];
        }
        $after = $this->read($type, $target);
        $id = $this->remember($type, $target, $before, $after, sanitize_text_field((string) ($change['reference'] ?? '')));
        // 1.4.1: search engines hear about the changed page (IndexNow), sent right after this request.
        if (isset($target['post_id']) && get_post_status($target['post_id']) === 'publish') {
            MoxDOP_Connector_IndexNow::queue((string) get_permalink($target['post_id']));
            (new MoxDOP_Connector_Events)->send_soon();
        } elseif (isset($target['from'])) {
            MoxDOP_Connector_IndexNow::queue(home_url($target['from']));
            (new MoxDOP_Connector_Events)->send_soon();
        }

        return ['ok' => true, 'type' => $type, 'change_id' => $id, 'before' => $this->summary($before), 'after' => $this->summary($after)];
    }

    private function target($type, array $change)
    {
        $post_id = absint($change['object_id'] ?? 0);
        switch ($type) {
            case 'seo_title':
            case 'seo_description':
            case 'noindex':
            case 'canonical':
            case 'internal_link':
                return $post_id > 0 && get_post($post_id) ? ['post_id' => $post_id] : null;
            case 'alt_text':
                return $post_id > 0 && get_post_type($post_id) === 'attachment' ? ['post_id' => $post_id] : null;
            case 'schema':
                return $post_id > 0 ? (get_post($post_id) ? ['post_id' => $post_id] : null) : ['site' => true];
            case 'redirect':
                $from = $this->path((string) ($change['from'] ?? ''));

                return $from !== '' && $from !== '/' ? ['from' => $from] : null;
        }

        return null;
    }

    private function clean($type, $value, array $target)
    {
        switch ($type) {
            case 'seo_title':
                return mb_substr(sanitize_text_field((string) $value), 0, 120);
            case 'seo_description':
                return mb_substr(sanitize_textarea_field((string) $value), 0, 320);
            case 'alt_text':
                return mb_substr(sanitize_text_field((string) $value), 0, 250);
            case 'noindex':
                return (bool) $value;
            case 'canonical':
                $url = esc_url_raw((string) $value);

                return $url === '' || wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST) ? $url : new WP_Error('bad', 'canonical must stay on this site');
            case 'schema':
                $decoded = is_string($value) ? json_decode($value, true) : $value;
                if ($value === null || $value === '') {
                    return '';
                }

                return is_array($decoded) ? wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : new WP_Error('bad', 'schema must be JSON-LD');
            case 'redirect':
                if ($value === null || $value === '') {
                    return '';
                }
                $url = esc_url_raw((string) $value);

                return $url !== '' ? $url : new WP_Error('bad', 'redirect target is not a URL');
            case 'internal_link':
                $anchor = sanitize_text_field((string) ($value['anchor'] ?? ''));
                $url = esc_url_raw((string) ($value['url'] ?? ''));
                if ($anchor === '' || $url === '' || mb_strlen($anchor) > 120) {
                    return new WP_Error('bad', 'anchor and url are required');
                }
                $post = get_post($target['post_id']);
                $content = (string) $post->post_content;
                if (strpos($content, 'href="'.$url.'"') !== false) {
                    return new WP_Error('bad', 'the page already links to this URL');
                }
                $linked = $this->insert_link($content, $anchor, $url);

                return $linked === null ? new WP_Error('bad', 'anchor text not found as plain text in the page') : $linked;
        }

        return new WP_Error('bad', 'unknown change type');
    }

    /** Current value of a target (used for before / after and the undo check). */
    private function read($type, array $target)
    {
        switch ($type) {
            case 'seo_title':
            case 'seo_description':
            case 'canonical':
                return (string) get_post_meta($target['post_id'], $this->seo_key($type === 'seo_title' ? 'title' : ($type === 'seo_description' ? 'description' : 'canonical')), true);
            case 'noindex':
                return $this->is_noindex($target['post_id']);
            case 'alt_text':
                return (string) get_post_meta($target['post_id'], '_wp_attachment_image_alt', true);
            case 'schema':
                return isset($target['site']) ? (string) get_option(self::SITE_SCHEMA_OPTION, '') : (string) get_post_meta($target['post_id'], '_moxdop_schema', true);
            case 'redirect':
                $redirects = (array) get_option(self::REDIRECTS_OPTION, []);

                return (string) ($redirects[$target['from']] ?? '');
            case 'internal_link':
                return (string) get_post_field('post_content', $target['post_id'], 'raw');
            case 'content':
                return ['title' => (string) get_post_field('post_title', $target['post_id'], 'raw'), 'content' => (string) get_post_field('post_content', $target['post_id'], 'raw')];
        }

        return null;
    }

    private function write($type, array $target, $value)
    {
        switch ($type) {
            case 'seo_title':
            case 'seo_description':
            case 'canonical':
                $key = $this->seo_key($type === 'seo_title' ? 'title' : ($type === 'seo_description' ? 'description' : 'canonical'));

                return $value === '' ? delete_post_meta($target['post_id'], $key) : update_post_meta($target['post_id'], $key, $value);
            case 'noindex':
                return $this->set_noindex($target['post_id'], (bool) $value);
            case 'alt_text':
                return update_post_meta($target['post_id'], '_wp_attachment_image_alt', $value);
            case 'schema':
                if (isset($target['site'])) {
                    return update_option(self::SITE_SCHEMA_OPTION, (string) $value, false);
                }

                return $value === '' ? delete_post_meta($target['post_id'], '_moxdop_schema') : update_post_meta($target['post_id'], '_moxdop_schema', wp_slash((string) $value));
            case 'redirect':
                $redirects = (array) get_option(self::REDIRECTS_OPTION, []);
                if ($value === '') {
                    unset($redirects[$target['from']]);
                } else {
                    $redirects[$target['from']] = (string) $value;
                }

                return update_option(self::REDIRECTS_OPTION, $redirects, true);
            case 'internal_link':
                // wp_update_post keeps a WordPress revision of the previous content.
                $result = wp_update_post(['ID' => $target['post_id'], 'post_content' => wp_slash((string) $value)], true);

                return is_wp_error($result) ? $result : true;
            case 'content':
                $result = wp_update_post(['ID' => $target['post_id'], 'post_title' => wp_slash((string) ($value['title'] ?? '')), 'post_content' => wp_slash((string) ($value['content'] ?? ''))], true);

                return is_wp_error($result) ? $result : true;
        }

        return new WP_Error('bad', 'unknown change type');
    }

    /** Wraps the first plain-text occurrence of $anchor (outside tags, links and headings) in a link. */
    private function insert_link($content, $anchor, $url)
    {
        $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $blocked = 0;
        foreach ($parts as $i => $part) {
            if ($part !== '' && $part[0] === '<') {
                if (preg_match('#^<(a|h[1-6]|script|style|button)\b#i', $part)) {
                    $blocked++;
                } elseif (preg_match('#^</(a|h[1-6]|script|style|button)>#i', $part)) {
                    $blocked = max(0, $blocked - 1);
                }

                continue;
            }
            if ($blocked > 0) {
                continue;
            }
            $pos = mb_stripos($part, $anchor);
            if ($pos !== false) {
                $found = mb_substr($part, $pos, mb_strlen($anchor));
                $parts[$i] = mb_substr($part, 0, $pos).'<a href="'.esc_url($url).'">'.$found.'</a>'.mb_substr($part, $pos + mb_strlen($anchor));

                return implode('', $parts);
            }
        }

        return null;
    }

    /* ------------------------------------------------------- REST: content updates */

    /** A new version of an existing page, saved as a separate draft copy (the live page is untouched). */
    public function content_draft(WP_REST_Request $request)
    {
        if (! self::content_allowed()) {
            return new WP_Error('moxdop_content_disabled', 'Content updates are disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $source = get_post(absint(is_array($body) ? ($body['object_id'] ?? 0) : 0));
        $content = wp_kses_post((string) (is_array($body) ? ($body['content_html'] ?? '') : ''));
        if (! $source || $content === '' || strlen($content) > 300000 || ! in_array($source->post_type, ['post', 'page'], true)) {
            return new WP_Error('moxdop_invalid_body', 'object_id of a post/page and content_html are required.', ['status' => 400]);
        }
        $copy = wp_insert_post([
            'post_type' => $source->post_type,
            'post_status' => 'draft',
            'post_title' => sanitize_text_field((string) ($body['title'] ?? $source->post_title)),
            'post_content' => $content,
            'post_author' => (int) $source->post_author,
            'post_parent' => (int) $source->post_parent,
        ], true);
        if (is_wp_error($copy)) {
            return new WP_Error('moxdop_draft_failed', $copy->get_error_message(), ['status' => 500]);
        }
        update_post_meta($copy, '_moxdop_update_of', (int) $source->ID);
        update_post_meta($copy, '_moxdop_created', '1');
        update_post_meta($copy, '_moxdop_draft_reference', sanitize_text_field((string) ($body['reference'] ?? '')));

        return $this->auth->envelope([
            'schema_version' => 1, 'post_id' => (int) $copy, 'update_of' => (int) $source->ID, 'status' => 'draft',
            'edit_url' => admin_url('post.php?post='.(int) $copy.'&action=edit'), 'preview_url' => get_preview_post_link($copy) ?: '',
        ], $request);
    }

    /**
     * Second approval: the (possibly edited) draft copy replaces the live page's title and content. WordPress keeps
     * a revision of the old version; undo is the "content" change in the change log.
     */
    public function content_apply(WP_REST_Request $request)
    {
        if (! self::content_allowed()) {
            return new WP_Error('moxdop_content_disabled', 'Content updates are disabled on this site.', ['status' => 403]);
        }
        $body = json_decode((string) $request->get_body(), true);
        $copy = get_post(absint(is_array($body) ? ($body['draft_id'] ?? 0) : 0));
        $original_id = $copy ? (int) get_post_meta($copy->ID, '_moxdop_update_of', true) : 0;
        $original = $original_id > 0 ? get_post($original_id) : null;
        if (! $copy || ! $original || $copy->post_status !== 'draft') {
            return new WP_Error('moxdop_not_found', 'No MoxDOP update draft with this id.', ['status' => 404]);
        }
        $before = ['title' => $original->post_title, 'content' => $original->post_content];
        $result = wp_update_post(['ID' => $original->ID, 'post_title' => $copy->post_title, 'post_content' => $copy->post_content], true);
        if (is_wp_error($result)) {
            return new WP_Error('moxdop_apply_failed', $result->get_error_message(), ['status' => 500]);
        }
        wp_trash_post($copy->ID);
        $id = $this->remember('content', ['post_id' => $original->ID], $before, $this->read('content', ['post_id' => $original->ID]), 'content-apply');

        return $this->auth->envelope(['schema_version' => 1, 'ok' => true, 'post_id' => (int) $original->ID, 'change_id' => $id, 'url' => get_permalink($original->ID)], $request);
    }

    /* ------------------------------------------------------------ front end */

    public function redirect()
    {
        $redirects = (array) get_option(self::REDIRECTS_OPTION, []);
        if ($redirects === [] || ! isset($_SERVER['REQUEST_URI'])) {
            return;
        }
        $path = $this->path((string) wp_unslash($_SERVER['REQUEST_URI']));
        if (isset($redirects[$path]) && $redirects[$path] !== '') {
            wp_redirect($redirects[$path], 301, 'MoxDOP');
            exit;
        }
    }

    public function head()
    {
        $site = (string) get_option(self::SITE_SCHEMA_OPTION, '');
        if ($site !== '' && (is_front_page() || is_home())) {
            echo '<script type="application/ld+json">'.$site.'</script>'."\n";
        }
        if (! is_singular()) {
            return;
        }
        $post_id = get_queried_object_id();
        $schema = (string) get_post_meta($post_id, '_moxdop_schema', true);
        if ($schema !== '') {
            echo '<script type="application/ld+json">'.$schema.'</script>'."\n";
        }
        if ($this->provider() === 'moxdop') {
            $description = (string) get_post_meta($post_id, '_moxdop_seo_description', true);
            if ($description !== '') {
                echo '<meta name="description" content="'.esc_attr($description).'">'."\n";
            }
        }
    }

    public function document_title($title)
    {
        if ($this->provider() !== 'moxdop' || ! is_singular()) {
            return $title;
        }
        $own = (string) get_post_meta(get_queried_object_id(), '_moxdop_seo_title', true);

        return $own !== '' ? $own : $title;
    }

    public function canonical($url, $post)
    {
        if ($this->provider() !== 'moxdop' || ! $post) {
            return $url;
        }
        $own = (string) get_post_meta($post->ID, '_moxdop_canonical', true);

        return $own !== '' ? $own : $url;
    }

    public function robots(array $robots)
    {
        if ($this->provider() === 'moxdop' && is_singular() && get_post_meta(get_queried_object_id(), '_moxdop_noindex', true) === '1') {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    /* -------------------------------------------------------------- helpers */

    /** Active SEO plugin whose fields are written, or MoxDOP's own fields. */
    private function provider()
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rank_math';
        }
        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }

        return 'moxdop';
    }

    private function seo_key($field)
    {
        return self::SEO_KEYS[$this->provider()][$field];
    }

    private function is_noindex($post_id)
    {
        $value = get_post_meta($post_id, $this->seo_key('noindex'), true);
        switch ($this->provider()) {
            case 'yoast':
                return (string) $value === '1';
            case 'rank_math':
                return is_array($value) && in_array('noindex', $value, true);
            case 'seopress':
                return (string) $value === 'yes';
        }

        return (string) $value === '1';
    }

    private function set_noindex($post_id, $noindex)
    {
        $key = $this->seo_key('noindex');
        switch ($this->provider()) {
            case 'yoast':
                return $noindex ? update_post_meta($post_id, $key, '1') : delete_post_meta($post_id, $key);
            case 'rank_math':
                $robots = get_post_meta($post_id, $key, true);
                $robots = array_values(array_diff(is_array($robots) ? $robots : [], ['noindex', 'index']));
                $robots[] = $noindex ? 'noindex' : 'index';

                return update_post_meta($post_id, $key, $robots);
            case 'seopress':
                return $noindex ? update_post_meta($post_id, $key, 'yes') : delete_post_meta($post_id, $key);
        }

        return $noindex ? update_post_meta($post_id, $key, '1') : delete_post_meta($post_id, $key);
    }

    private function path($url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = '/'.trim(rawurldecode($path), '/');

        return $path === '/' ? '/' : strtolower($path);
    }

    /** Records a change. Large previous values (page content) live in post meta; the log keeps hashes. */
    private function remember($type, array $target, $before, $after, $reference)
    {
        $id = wp_generate_uuid4();
        $large = strlen((string) wp_json_encode($before)) > 2000;
        if ($large && isset($target['post_id'])) {
            update_post_meta($target['post_id'], '_moxdop_before_'.$id, wp_slash(wp_json_encode($before)));
        }
        $log = $this->log();
        $log[$id] = ['type' => $type, 'target' => $target, 'before' => $large ? null : $before, 'before_in_meta' => $large, 'after_hash' => $this->fingerprint($after), 'at' => gmdate('c'), 'reference' => $reference];
        if (count($log) > self::MAX_LOG) {
            $log = array_slice($log, -self::MAX_LOG, null, true);
        }
        update_option(self::LOG_OPTION, $log, false);

        return $id;
    }

    private function previous($id, array $entry)
    {
        if (empty($entry['before_in_meta'])) {
            return $entry['before'];
        }

        return json_decode((string) get_post_meta($entry['target']['post_id'], '_moxdop_before_'.$id, true), true);
    }

    private function fingerprint($value)
    {
        return hash('sha256', (string) wp_json_encode($value));
    }

    /** Short form of a value for the response (never the whole page). */
    private function summary($value)
    {
        if (is_array($value) || (is_string($value) && strlen($value) > 600)) {
            return ['hash' => $this->fingerprint($value), 'length' => strlen((string) wp_json_encode($value))];
        }

        return $value;
    }

    private function log()
    {
        $log = get_option(self::LOG_OPTION, []);

        return is_array($log) ? $log : [];
    }
}
