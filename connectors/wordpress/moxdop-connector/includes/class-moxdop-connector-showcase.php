<?php

defined('ABSPATH') || exit;

/**
 * 1.28.0 Sorular ve yorumlar (yakup 2026-10-27). A MoxDOP service the brand switches on in MoxDOP (Marka ayarları);
 * the site admin decides where it shows.
 *
 * - Settings › Sorular ve yorumlar: the pages, post types and taxonomies that get questions, and the e-mail a new
 *   question is sent to (through the site's own wp_mail / SMTP plugin). Every assigned page gets ONE question page,
 *   <page>/sorular/: a real post of the hidden type `moxdop_qa`, so SEO plugins, sitemaps and Polylang treat it as a
 *   normal page. It stays noindex until it has 3 answered questions.
 * - Nothing is inserted into pages: the admin places the Elementor widget "MoxDOP Sizden gelenler" or a shortcode
 *   ([moxdop_sorular], [moxdop_yorumlar], [moxdop_yorumlar gorunum="rozet"]). Colours and fonts follow the site.
 * - The ask form is a contact form: honeypot, a 3-second minimum, 3 questions an hour per visitor, no links. The
 *   question is kept here (WordPress › Sorular, with the name and contact the visitor left), mailed to the chosen
 *   address and sent to MoxDOP WITHOUT name or contact (event qa.asked). MoxDOP writes the answer, the operator
 *   approves it, MoxDOP sends the published questions back (signed POST /showcase) and the asker gets an e-mail.
 *   Until then only the asker sees the question, in their own browser.
 * - Reviews: MoxDOP sends the brand's 4–5 star Google reviews with text (no price or phone) and the rating summary.
 * - "İşe yaradı mı?": anonymous votes, one per visitor and answer; the page shows the most helpful answers first.
 * - Switching the brand off in MoxDOP removes the data; removing the plugin removes the widget, shortcodes and data.
 */
final class MoxDOP_Connector_Showcase
{
    const TARGETS_OPTION = 'moxdop_showcase_targets';

    const NOTIFY_OPTION = 'moxdop_showcase_notify';

    const REVIEWS_OPTION = 'moxdop_showcase_reviews';

    const ENABLED_OPTION = 'moxdop_showcase_enabled';

    const QA_TYPE = 'moxdop_qa';

    const QUESTION_TYPE = 'moxdop_question';

    const ITEMS_META = '_moxdop_qa_items';

    const VOTES_META = '_moxdop_qa_votes';

    const TARGET_META = '_moxdop_qa_target';

    const PATH_META = '_moxdop_qa_path';

    const NOINDEX_META = '_moxdop_qa_noindex';

    const PAGE = 'moxdop-showcase';

    const SLUG = 'sorular';

    /** A question page is indexed from this many answered questions on. */
    const INDEX_AT = 3;

    /** Questions the block on the page itself shows. */
    const SHOWN = 10;

    /** Questions one visitor may ask in an hour. */
    const RATE = 3;

    /** A form sent sooner than this after the page was made is a bot. */
    const MIN_SECONDS = 3;

    /** Assigned post types are read up to this many posts. */
    const MAX_TARGETS = 500;

    private static $assets_printed = false;

    public function register()
    {
        add_action('init', [$this, 'types']);
        add_action('parse_request', [$this, 'route']);
        add_filter('post_type_link', [$this, 'permalink'], 10, 2);
        add_filter('the_content', [$this, 'page_content'], 20);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemap_args'], 10, 2);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', [$this, 'noindexed_ids']);
        add_shortcode('moxdop_sorular', [$this, 'shortcode_questions']);
        add_shortcode('moxdop_yorumlar', [$this, 'shortcode_reviews']);
        add_action('rest_api_init', [$this, 'public_routes']);
        add_action('elementor/widgets/register', [$this, 'elementor_widget']);
        if (is_admin()) {
            add_action('admin_menu', [$this, 'menu']);
            add_action('admin_post_moxdop_showcase_save', [$this, 'save_settings']);
            add_filter('manage_'.self::QUESTION_TYPE.'_posts_columns', [$this, 'question_columns']);
            add_action('manage_'.self::QUESTION_TYPE.'_posts_custom_column', [$this, 'question_column'], 10, 2);
        }
    }

    /* ------------------------------------------------------------------ types and addresses */

    public function types()
    {
        register_post_type(self::QA_TYPE, [
            'labels' => ['name' => 'Soru sayfaları', 'singular_name' => 'Soru sayfası'],
            'public' => true, 'publicly_queryable' => true, 'exclude_from_search' => true, 'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type='.self::QUESTION_TYPE, 'show_in_nav_menus' => false, 'show_in_rest' => false,
            'has_archive' => false, 'rewrite' => false, 'query_var' => false, 'supports' => ['title'],
            'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true,
        ]);
        register_post_type(self::QUESTION_TYPE, [
            'labels' => ['name' => 'Sorular', 'singular_name' => 'Soru', 'menu_name' => 'Sorular', 'all_items' => 'Gelen sorular'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-format-chat', 'menu_position' => 26,
            'supports' => ['title', 'editor'], 'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true,
        ]);
    }

    /** <page>/sorular/ → the page's question page (no rewrite rules: works with any permalink and language setting). */
    public function route($wp)
    {
        $request = strtolower(trim(rawurldecode((string) $wp->request), '/'));
        if (! preg_match('~^(?:(.+)/)?'.self::SLUG.'$~', $request, $m)) {
            return;
        }
        $path = isset($m[1]) ? trim($m[1], '/') : '';
        $id = self::find_by_path($path);
        if (! $id) {
            $target = self::target_by_path($path);
            $id = $target ? self::qa_post($target, true) : 0;
        }
        if ($id && get_post_status($id) === 'publish') {
            $wp->query_vars = ['p' => (int) $id, 'post_type' => self::QA_TYPE];
        }
    }

    public function permalink($link, $post)
    {
        if (! $post instanceof WP_Post || $post->post_type !== self::QA_TYPE) {
            return $link;
        }
        $target = self::parse_key((string) get_post_meta($post->ID, self::TARGET_META, true));
        $url = $target ? self::target_url($target) : '';

        return $url !== '' ? trailingslashit($url).self::SLUG.'/' : $link;
    }

    /** Path of a URL under the site root, as WordPress parses requests ($wp->request). */
    private static function rel_path($url)
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $root = (string) wp_parse_url((string) get_option('home'), PHP_URL_PATH);
        $root = trim($root, '/');
        $path = trim($path, '/');
        if ($root !== '' && strpos($path.'/', $root.'/') === 0) {
            $path = trim(substr($path, strlen($root)), '/');
        }

        return strtolower(rawurldecode($path));
    }

    private static function find_by_path($path)
    {
        $ids = get_posts(['post_type' => self::QA_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'lang' => '',
            'suppress_filters' => true, 'meta_key' => self::PATH_META, 'meta_value' => $path]);

        return (int) ($ids[0] ?? 0);
    }

    /** An assigned post whose address is this path (a question page not made yet, or a page that moved). */
    private static function target_by_path($path)
    {
        $id = $path === '' ? (int) get_option('page_on_front') : url_to_postid(home_url('/'.$path.'/'));
        if ($id < 1) {
            return null;
        }
        $target = ['kind' => 'post', 'id' => $id];

        return self::assigned($target) ? $target : null;
    }

    /* ------------------------------------------------------------------ assignment */

    /** @return array{pages: list<int>, post_types: list<string>, taxonomies: list<string>} */
    public static function assignment()
    {
        $saved = get_option(self::TARGETS_OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return [
            'pages' => array_values(array_filter(array_map('intval', (array) ($saved['pages'] ?? [])))),
            'post_types' => array_values(array_filter(array_map('sanitize_key', (array) ($saved['post_types'] ?? [])))),
            'taxonomies' => array_values(array_filter(array_map('sanitize_key', (array) ($saved['taxonomies'] ?? [])))),
        ];
    }

    private static function parse_key($key)
    {
        if (! preg_match('/^(post|term):([1-9][0-9]*)$/', (string) $key, $m)) {
            return null;
        }

        return ['kind' => $m[1], 'id' => (int) $m[2]];
    }

    private static function key(array $target)
    {
        return $target['kind'].':'.$target['id'];
    }

    public static function assigned(array $target)
    {
        $set = self::assignment();
        if ($target['kind'] === 'post') {
            $post = get_post($target['id']);
            if (! $post instanceof WP_Post || $post->post_status !== 'publish' || in_array($post->post_type, [self::QA_TYPE, self::QUESTION_TYPE], true)) {
                return false;
            }

            return in_array($post->ID, $set['pages'], true) || in_array($post->post_type, $set['post_types'], true);
        }
        $term = get_term($target['id']);

        return $term instanceof WP_Term && in_array($term->taxonomy, $set['taxonomies'], true);
    }

    private static function target_url(array $target)
    {
        if ($target['kind'] === 'post') {
            return get_post_status($target['id']) === 'publish' ? (string) get_permalink($target['id']) : '';
        }
        $link = get_term_link($target['id']);

        return is_wp_error($link) ? '' : (string) $link;
    }

    private static function target_title(array $target)
    {
        if ($target['kind'] === 'post') {
            return wp_strip_all_tags((string) get_the_title($target['id']));
        }
        $term = get_term($target['id']);

        return $term instanceof WP_Term ? $term->name : '';
    }

    /** @return list<array{kind: string, id: int}> every assigned page, post and term */
    public static function targets()
    {
        $set = self::assignment();
        $out = [];
        foreach ($set['pages'] as $id) {
            $out['post:'.$id] = ['kind' => 'post', 'id' => $id];
        }
        if ($set['post_types']) {
            $ids = get_posts(['post_type' => $set['post_types'], 'post_status' => 'publish', 'numberposts' => self::MAX_TARGETS, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]);
            foreach ($ids as $id) {
                $out['post:'.$id] = ['kind' => 'post', 'id' => (int) $id];
            }
        }
        if ($set['taxonomies']) {
            $terms = get_terms(['taxonomy' => $set['taxonomies'], 'hide_empty' => false, 'number' => self::MAX_TARGETS, 'fields' => 'ids', 'lang' => '']);
            foreach (is_array($terms) ? $terms : [] as $id) {
                $out['term:'.$id] = ['kind' => 'term', 'id' => (int) $id];
            }
        }

        return array_values(array_filter($out, [self::class, 'assigned']));
    }

    /** The question page of an assigned target (made when $create and missing); 0 when there is none. */
    public static function qa_post(array $target, $create = false)
    {
        $key = self::key($target);
        $ids = get_posts(['post_type' => self::QA_TYPE, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'lang' => '',
            'suppress_filters' => true, 'meta_key' => self::TARGET_META, 'meta_value' => $key]);
        $id = (int) ($ids[0] ?? 0);
        $url = self::target_url($target);
        if ($id) {
            $path = $url !== '' ? self::rel_path($url) : '';
            if ($url !== '' && get_post_meta($id, self::PATH_META, true) !== $path) {
                update_post_meta($id, self::PATH_META, $path);
            }

            return $id;
        }
        if (! $create || $url === '' || ! self::assigned($target)) {
            return 0;
        }
        $title = self::target_title($target);
        $id = wp_insert_post([
            'post_type' => self::QA_TYPE, 'post_status' => 'publish', 'post_title' => trim($title.' Hakkında Sorular ve Cevaplar'),
            'post_name' => sanitize_title($title.'-'.self::SLUG), 'comment_status' => 'closed', 'ping_status' => 'closed',
        ], true);
        if (is_wp_error($id) || ! $id) {
            return 0;
        }
        update_post_meta($id, self::TARGET_META, $key);
        update_post_meta($id, self::PATH_META, self::rel_path($url));
        $description = $title.' hakkında en çok sorulan sorular ve cevapları. Sorunuzu sorun, uzmanlarımız yanıtlasın.';
        update_post_meta($id, 'rank_math_description', $description);
        update_post_meta($id, '_yoast_wpseo_metadesc', $description);
        if ($target['kind'] === 'post' && function_exists('pll_get_post_language') && function_exists('pll_set_post_language')) {
            $lang = pll_get_post_language($target['id']);
            if ($lang) {
                pll_set_post_language($id, $lang);
            }
        }
        self::set_index($id, []);

        return (int) $id;
    }

    /** Fewer than INDEX_AT answered questions: noindex for Rank Math, Yoast, core robots and sitemaps. */
    private static function set_index($post_id, array $items)
    {
        if (count($items) >= self::INDEX_AT) {
            delete_post_meta($post_id, self::NOINDEX_META);
            delete_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex');
            delete_post_meta($post_id, 'rank_math_robots');

            return;
        }
        update_post_meta($post_id, self::NOINDEX_META, '1');
        update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', '1');
        update_post_meta($post_id, 'rank_math_robots', ['noindex', 'follow']);
    }

    public function robots($robots)
    {
        if (is_singular(self::QA_TYPE) && get_post_meta(get_queried_object_id(), self::NOINDEX_META, true) === '1') {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }

        return $robots;
    }

    public function noindexed_ids($ids)
    {
        $more = get_posts(['post_type' => self::QA_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '',
            'suppress_filters' => true, 'meta_key' => self::NOINDEX_META, 'meta_value' => '1']);

        return array_values(array_unique(array_merge(array_map('intval', (array) $ids), array_map('intval', $more))));
    }

    public function sitemap_args($args, $post_type)
    {
        if ($post_type === self::QA_TYPE) {
            $args['post__not_in'] = $this->noindexed_ids($args['post__not_in'] ?? []);
        }

        return $args;
    }

    /* ------------------------------------------------------------------ data from MoxDOP */

    /** Signed routes MoxDOP calls: GET /showcase (targets), POST /showcase (data). */
    public function register_signed_routes($namespace, MoxDOP_Connector_Auth $auth)
    {
        register_rest_route($namespace, '/showcase', [
            ['methods' => WP_REST_Server::READABLE, 'permission_callback' => [$auth, 'authorize'], 'callback' => function (WP_REST_Request $request) use ($auth) {
                return $auth->envelope(['schema_version' => 1] + $this->state(), $request);
            }],
            ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => [$auth, 'authorize'], 'callback' => function (WP_REST_Request $request) use ($auth) {
                $body = json_decode((string) $request->get_body(), true);

                return $auth->envelope(['schema_version' => 1] + $this->apply(is_array($body) ? $body : []), $request);
            }],
        ]);
    }

    /** @return array<string, mixed> */
    public function state()
    {
        $targets = [];
        foreach (self::targets() as $target) {
            $qa = self::qa_post($target, true);
            $items = $qa ? get_post_meta($qa, self::ITEMS_META, true) : [];
            $lang = $target['kind'] === 'post' && function_exists('pll_get_post_language') ? (string) pll_get_post_language($target['id']) : '';
            $targets[] = [
                'key' => self::key($target), 'kind' => $target['kind'], 'id' => $target['id'],
                'type' => $target['kind'] === 'post' ? (string) get_post_type($target['id']) : (string) (get_term($target['id'])->taxonomy ?? ''),
                'title' => self::target_title($target), 'url' => self::target_url($target),
                'qa_url' => $qa ? (string) get_permalink($qa) : '', 'published' => is_array($items) ? count($items) : 0, 'language' => $lang,
            ];
        }

        return ['enabled' => (bool) get_option(self::ENABLED_OPTION), 'targets' => $targets, 'notify' => (string) get_option(self::NOTIFY_OPTION) !== ''];
    }

    /**
     * MoxDOP's data: `enabled` (the brand switch), `reviews` (summary + items) and `qa` (target key => published
     * questions). Only the parts sent change; enabled = false removes everything MoxDOP sent.
     *
     * @return array<string, mixed>
     */
    public function apply(array $body)
    {
        $enabled = ! empty($body['enabled']);
        update_option(self::ENABLED_OPTION, $enabled ? 1 : 0, true);
        $out = ['enabled' => $enabled, 'reviews' => 0, 'pages' => 0, 'questions' => 0, 'notified' => 0];
        $purge = [];
        if (! $enabled) {
            delete_option(self::REVIEWS_OPTION);
            foreach (get_posts(['post_type' => self::QA_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]) as $id) {
                delete_post_meta($id, self::ITEMS_META);
                self::set_index($id, []);
            }
            self::purge_all();

            return $out;
        }
        if (isset($body['reviews']) && is_array($body['reviews'])) {
            $reviews = self::clean_reviews($body['reviews']);
            $before = get_option(self::REVIEWS_OPTION, []);
            if (wp_json_encode($before) !== wp_json_encode($reviews)) {
                update_option(self::REVIEWS_OPTION, $reviews, false);
                $purge[] = '*';
            }
            $out['reviews'] = count($reviews['items']);
        }
        if (isset($body['qa']) && is_array($body['qa'])) {
            foreach ($body['qa'] as $key => $items) {
                $target = self::parse_key($key);
                if (! $target || ! is_array($items)) {
                    continue;
                }
                $qa = self::qa_post($target, true);
                if (! $qa) {
                    continue;
                }
                $items = self::clean_items($items);
                $before = get_post_meta($qa, self::ITEMS_META, true);
                if (wp_json_encode($before) !== wp_json_encode($items)) {
                    update_post_meta($qa, self::ITEMS_META, $items);
                    self::set_index($qa, $items);
                    $purge[] = (string) get_permalink($qa);
                    $purge[] = self::target_url($target);
                }
                $out['notified'] += self::notify_askers($items, (string) get_permalink($qa));
                $out['pages']++;
                $out['questions'] += count($items);
            }
        }
        if (in_array('*', $purge, true)) {
            self::purge_all();
        } else {
            self::purge_urls(array_filter(array_unique($purge)));
        }

        return $out;
    }

    private static function clean_reviews(array $in)
    {
        $items = [];
        foreach (array_slice((array) ($in['items'] ?? []), 0, 200) as $item) {
            if (! is_array($item) || trim((string) ($item['text'] ?? '')) === '') {
                continue;
            }
            $items[] = [
                'id' => sanitize_text_field((string) ($item['id'] ?? '')),
                'name' => sanitize_text_field((string) ($item['name'] ?? '')),
                'stars' => max(1, min(5, (int) ($item['stars'] ?? 5))),
                'text' => sanitize_textarea_field((string) $item['text']),
                'date' => sanitize_text_field((string) ($item['date'] ?? '')),
                'reply' => sanitize_textarea_field((string) ($item['reply'] ?? '')),
                'place' => sanitize_text_field((string) ($item['place'] ?? '')),
            ];
        }
        $places = [];
        foreach (array_slice((array) ($in['places'] ?? []), 0, 50) as $place) {
            if (is_array($place)) {
                $places[] = ['name' => sanitize_text_field((string) ($place['name'] ?? '')), 'rating' => round((float) ($place['rating'] ?? 0), 1),
                    'count' => (int) ($place['count'] ?? 0), 'url' => esc_url_raw((string) ($place['url'] ?? ''))];
            }
        }

        return ['rating' => round((float) ($in['rating'] ?? 0), 1), 'count' => (int) ($in['count'] ?? 0), 'url' => esc_url_raw((string) ($in['url'] ?? '')),
            'places' => $places, 'items' => $items];
    }

    private static function clean_items(array $in)
    {
        $items = [];
        foreach (array_slice($in, 0, 300) as $item) {
            if (! is_array($item) || trim((string) ($item['q'] ?? '')) === '' || trim((string) ($item['a'] ?? '')) === '') {
                continue;
            }
            $items[] = [
                'id' => sanitize_key((string) ($item['id'] ?? '')),
                'q' => sanitize_text_field((string) $item['q']),
                'a' => sanitize_textarea_field((string) $item['a']),
                'date' => sanitize_text_field((string) ($item['date'] ?? '')),
                'source' => ($item['source'] ?? '') === 'site' ? 'site' : 'data',
                'link_url' => esc_url_raw((string) ($item['link_url'] ?? '')),
                'link_title' => sanitize_text_field((string) ($item['link_title'] ?? '')),
                'local_id' => (int) ($item['local_id'] ?? 0),
            ];
        }

        return $items;
    }

    /** The visitor who asked gets one e-mail when the answer is published (if they left an address). */
    private static function notify_askers(array $items, $qa_url)
    {
        $sent = 0;
        foreach ($items as $item) {
            $local = (int) $item['local_id'];
            $post = $local > 0 ? get_post($local) : null;
            if (! $post instanceof WP_Post || $post->post_type !== self::QUESTION_TYPE) {
                continue;
            }
            update_post_meta($local, '_moxdop_q_status', 'answered');
            $email = (string) get_post_meta($local, '_moxdop_q_email', true);
            if ($email === '' || get_post_meta($local, '_moxdop_q_notified', true)) {
                continue;
            }
            update_post_meta($local, '_moxdop_q_notified', gmdate('c'));
            $body = "Merhaba,\n\nSitemizde sorduğunuz soru yanıtlandı.\n\nSorunuz: ".$item['q']."\n\nCevabımız: ".$item['a']
                ."\n\nTüm sorular ve cevaplar: ".$qa_url."\n\n".get_bloginfo('name');
            if (wp_mail($email, 'Sorunuz yanıtlandı · '.get_bloginfo('name'), $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    private static function purge_urls(array $urls)
    {
        foreach ($urls as $url) {
            $id = url_to_postid($url);
            if ($id) {
                clean_post_cache($id);
            }
            do_action('litespeed_purge_url', $url);
            do_action('cache_enabler_clear_page_cache_by_url', $url);
            if (function_exists('rocket_clean_files')) {
                rocket_clean_files([$url]);
            }
            if (function_exists('w3tc_flush_url')) {
                w3tc_flush_url($url);
            }
            if (function_exists('wpsc_delete_url_cache')) {
                wpsc_delete_url_cache($url);
            }
        }
    }

    /** Reviews can be on any page: a change clears the whole page cache (at most once a day, when they changed). */
    private static function purge_all()
    {
        do_action('litespeed_purge_all');
        foreach (['rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'wpfc_clear_all_cache'] as $function) {
            if (function_exists($function)) {
                $function();
            }
        }
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
            Cache_Enabler::clear_complete_cache();
        }
    }

    /* ------------------------------------------------------------------ visitors: ask and vote */

    public function public_routes()
    {
        register_rest_route('moxdop/v1', '/showcase/ask', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => '__return_true', 'callback' => [$this, 'ask']]);
        register_rest_route('moxdop/v1', '/showcase/vote', ['methods' => WP_REST_Server::CREATABLE, 'permission_callback' => '__return_true', 'callback' => [$this, 'vote']]);
    }

    private static function visitor()
    {
        $ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

        return hash_hmac('sha256', $ip, wp_salt('nonce'));
    }

    /** Signed render time of the form: the page can be cached, the minimum time still holds. */
    private static function form_token()
    {
        $time = (string) time();

        return $time.'.'.substr(hash_hmac('sha256', $time, wp_salt('nonce')), 0, 20);
    }

    private static function token_age($token)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 2 || ! hash_equals(substr(hash_hmac('sha256', $parts[0], wp_salt('nonce')), 0, 20), $parts[1])) {
            return -1;
        }

        return time() - (int) $parts[0];
    }

    /** @return true|string true, or why the question is not taken */
    public static function check_question(array $in, $age)
    {
        if (trim((string) ($in['website'] ?? '')) !== '' || $age < self::MIN_SECONDS) {
            return 'spam';
        }
        $question = trim((string) ($in['question'] ?? ''));
        if (mb_strlen($question) < 10) {
            return 'Sorunuzu biraz daha açık yazın (en az 10 harf).';
        }
        if (mb_strlen($question) > 1000) {
            return 'Soru en fazla 1000 harf olabilir.';
        }
        if (preg_match('~https?://|www\.|\b[a-z0-9-]+\.(com|net|org|info|ru|xyz|top|io)\b~i', $question)) {
            return 'Soruya link eklenemez.';
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && ! is_email($email)) {
            return 'E-posta adresi eksik görünüyor, kontrol edin.';
        }
        if (empty($in['consent'])) {
            return 'Göndermek için aydınlatma metnini onaylayın.';
        }

        return true;
    }

    public function ask(WP_REST_Request $request)
    {
        $in = (array) $request->get_json_params();
        $target = self::parse_key((string) ($in['target'] ?? ''));
        if (! get_option(self::ENABLED_OPTION) || ! $target || ! self::assigned($target)) {
            return new WP_Error('moxdop_qa_closed', 'Bu sayfada soru alınmıyor.', ['status' => 404]);
        }
        $check = self::check_question($in, self::token_age($in['token'] ?? ''));
        if ($check === 'spam') {
            // A bot is told nothing useful; nothing is kept.
            return ['ok' => true, 'id' => 0];
        }
        if ($check !== true) {
            return new WP_Error('moxdop_qa_invalid', $check, ['status' => 422]);
        }
        $rate_key = 'moxdop_qa_rate_'.substr(self::visitor(), 0, 32);
        $rate = get_transient($rate_key);
        $rate = is_array($rate) ? $rate : ['n' => 0];
        if ($rate['n'] >= self::RATE) {
            return new WP_Error('moxdop_qa_rate', 'Bir saat içinde en fazla '.self::RATE.' soru gönderebilirsiniz. Biraz sonra tekrar deneyin.', ['status' => 429]);
        }
        set_transient($rate_key, ['n' => $rate['n'] + 1], HOUR_IN_SECONDS);

        $question = sanitize_textarea_field((string) $in['question']);
        $key = self::key($target);
        $title = self::target_title($target);
        $url = self::target_url($target);
        $id = wp_insert_post(['post_type' => self::QUESTION_TYPE, 'post_status' => 'private', 'post_title' => mb_substr($question, 0, 80),
            'post_content' => $question, 'comment_status' => 'closed'], true);
        if (is_wp_error($id) || ! $id) {
            return new WP_Error('moxdop_qa_failed', 'Soru kaydedilemedi, biraz sonra tekrar deneyin.', ['status' => 500]);
        }
        foreach (['target' => $key, 'page_title' => $title, 'page_url' => $url, 'status' => 'new', 'visitor' => substr(self::visitor(), 0, 16),
            'name' => sanitize_text_field((string) ($in['name'] ?? '')), 'email' => sanitize_email((string) ($in['email'] ?? '')),
            'phone' => sanitize_text_field((string) ($in['phone'] ?? ''))] as $field => $value) {
            update_post_meta($id, '_moxdop_q_'.$field, $value);
        }
        // To MoxDOP without the name, e-mail or phone.
        MoxDOP_Connector_Events::question((int) $id, $key, $title, $url, $question);
        $to = (string) get_option(self::NOTIFY_OPTION);
        if ($to !== '' && is_email($to)) {
            $body = "Sitenizde yeni bir soru var.\n\nSayfa: ".$title."\n".$url."\n\nSoru: ".$question
                ."\n\nAd: ".(get_post_meta($id, '_moxdop_q_name', true) ?: '—')."\nE-posta: ".(get_post_meta($id, '_moxdop_q_email', true) ?: '—')
                ."\nTelefon: ".(get_post_meta($id, '_moxdop_q_phone', true) ?: '—')
                ."\n\nCevap MoxDOP'ta hazırlanır; onaylanınca sitede adsız yayınlanır.\nTüm sorular: ".admin_url('edit.php?post_type='.self::QUESTION_TYPE);
            $headers = is_email((string) get_post_meta($id, '_moxdop_q_email', true)) ? ['Reply-To: '.get_post_meta($id, '_moxdop_q_email', true)] : [];
            wp_mail($to, 'Yeni soru: '.$title, $body, $headers);
        }

        return ['ok' => true, 'id' => (int) $id];
    }

    public function vote(WP_REST_Request $request)
    {
        $in = (array) $request->get_json_params();
        $qa = (int) ($in['page'] ?? 0);
        $item = sanitize_key((string) ($in['item'] ?? ''));
        $items = $qa > 0 && get_post_type($qa) === self::QA_TYPE ? get_post_meta($qa, self::ITEMS_META, true) : [];
        $known = is_array($items) && $item !== '' && in_array($item, array_column($items, 'id'), true);
        if (! $known) {
            return new WP_Error('moxdop_qa_vote', 'Bu cevap bulunamadı.', ['status' => 404]);
        }
        $once = 'moxdop_qa_vote_'.substr(hash('sha256', self::visitor().$qa.$item), 0, 32);
        if (get_transient($once)) {
            return ['ok' => true, 'counted' => false];
        }
        set_transient($once, 1, 30 * DAY_IN_SECONDS);
        $votes = get_post_meta($qa, self::VOTES_META, true);
        $votes = is_array($votes) ? $votes : [];
        $row = $votes[$item] ?? ['yes' => 0, 'no' => 0];
        $row[! empty($in['yes']) ? 'yes' : 'no']++;
        $votes[$item] = $row;
        update_post_meta($qa, self::VOTES_META, $votes);

        return ['ok' => true, 'counted' => true, 'yes' => (int) $row['yes']];
    }

    /* ------------------------------------------------------------------ what visitors see */

    /** The queried page as a target ("post:12" / "term:4"), or null outside a single page / term archive. */
    private static function current_target()
    {
        if (is_singular(self::QA_TYPE)) {
            return self::parse_key((string) get_post_meta(get_queried_object_id(), self::TARGET_META, true));
        }
        if (is_singular()) {
            return ['kind' => 'post', 'id' => (int) get_queried_object_id()];
        }
        if (is_category() || is_tag() || is_tax()) {
            return ['kind' => 'term', 'id' => (int) get_queried_object_id()];
        }
        $id = (int) get_the_ID();

        return $id > 0 ? ['kind' => 'post', 'id' => $id] : null;
    }

    public function shortcode_questions($atts)
    {
        $atts = shortcode_atts(['sayi' => self::SHOWN, 'sayfa' => ''], (array) $atts);
        $target = $atts['sayfa'] !== '' ? ['kind' => 'post', 'id' => (int) $atts['sayfa']] : self::current_target();

        return self::render_questions($target, max(1, min(50, (int) $atts['sayi'])), false);
    }

    public function shortcode_reviews($atts)
    {
        $atts = shortcode_atts(['sayi' => 6, 'gorunum' => 'liste'], (array) $atts);

        return self::render_reviews(max(1, min(60, (int) $atts['sayi'])), $atts['gorunum'] === 'rozet' ? 'badge' : 'list');
    }

    public function page_content($content)
    {
        if (! is_singular(self::QA_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }
        $target = self::parse_key((string) get_post_meta(get_the_ID(), self::TARGET_META, true));

        return $target ? self::render_questions($target, 0, true) : $content;
    }

    /** @return list<array<string, mixed>> published questions of a target, most helpful first (MoxDOP's order on ties) */
    private static function items(array $target, &$qa_id)
    {
        $qa_id = self::assigned($target) ? self::qa_post($target, true) : 0;
        $items = $qa_id ? get_post_meta($qa_id, self::ITEMS_META, true) : [];
        $items = is_array($items) ? $items : [];
        $votes = $qa_id ? get_post_meta($qa_id, self::VOTES_META, true) : [];
        $votes = is_array($votes) ? $votes : [];
        foreach ($items as $i => $item) {
            $items[$i]['yes'] = (int) ($votes[$item['id']]['yes'] ?? 0);
            $items[$i]['order'] = $i;
        }
        usort($items, static function ($a, $b) {
            return [$b['yes'], $a['order']] <=> [$a['yes'], $b['order']];
        });

        return $items;
    }

    /** $limit 0 = every question (the question page itself). */
    public static function render_questions($target, $limit, $full)
    {
        if (! $target || ! get_option(self::ENABLED_OPTION)) {
            return '';
        }
        $qa_id = 0;
        $items = self::items($target, $qa_id);
        if (! $qa_id) {
            return '';
        }
        $total = count($items);
        $shown = $limit > 0 ? array_slice($items, 0, $limit) : $items;
        $title = self::target_title($target);
        $qa_url = (string) get_permalink($qa_id);
        ob_start();
        echo self::assets();
        echo '<section class="mxs mxs-qa'.($full ? ' mxs-full' : '').'" data-target="'.esc_attr(self::key($target)).'" data-page="'.(int) $qa_id.'">';
        if ($full) {
            echo '<p class="mxs-lead">'.esc_html($title).' hakkında sorulan sorular ve cevapları. Sizin sorunuz burada yoksa hemen sorun; en geç 1 gün içinde yanıtlıyoruz.</p>';
            if ($total > 6) {
                echo '<label class="mxs-search"><span class="screen-reader-text">Sorularda ara</span><input type="search" placeholder="Sorularda ara" data-mxs-search></label>';
            }
        } else {
            echo '<div class="mxs-head"><h2 class="mxs-title">Sizden gelen sorular</h2><p class="mxs-sub">'
                .($total > 0 ? (int) $total.' soru yanıtlandı · ' : '').'Sorular genellikle 1 gün içinde yanıtlanır</p></div>';
        }
        echo self::ask_form($target, $full);
        echo '<div class="mxs-grid'.($full ? ' mxs-list' : '').'" data-mxs-list>';
        echo '<div class="mxs-pending" data-mxs-pending hidden></div>';
        foreach ($shown as $item) {
            echo self::question_card($item, $qa_id, $full);
        }
        echo '</div>';
        if ($total === 0) {
            echo '<p class="mxs-empty">Henüz yanıtlanmış soru yok. İlk soruyu siz sorun.</p>';
        }
        if (! $full) {
            echo '<div class="mxs-actions"><a class="mxs-btn" href="'.esc_url($qa_url).'">'.($total > count($shown) ? 'Tümünü göster ('.(int) $total.')' : 'Soru sayfasına git').'</a></div>';
        } elseif ($target['kind'] === 'post' || $target['kind'] === 'term') {
            echo '<p class="mxs-back"><a href="'.esc_url(self::target_url($target)).'">'.esc_html($title).' sayfasına dön</a></p>';
        }
        echo '<p class="mxs-privacy">Adınız ve iletişim bilgileriniz sitede hiçbir zaman görünmez.</p>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    private static function question_card(array $item, $qa_id, $full)
    {
        $who = $item['source'] === 'site' ? ['Bir ziyaretçi sordu', 'Sitede soruldu'] : ['Sık sorulan soru', 'Ziyaretçilerimizin sık sorduğu'];
        $html = '<article class="mxs-card" data-mxs-item>';
        $html .= '<div class="mxs-who"><span class="mxs-avatar" aria-hidden="true">?</span><span><b>'.esc_html($who[0]).'</b><small>'.esc_html($who[1]).'</small></span></div>';
        $html .= '<h3 class="mxs-q">'.esc_html($item['q']).'</h3>';
        $html .= '<div class="mxs-a'.($full ? '' : ' mxs-clamp').'"><p>'.nl2br(esc_html($item['a'])).'</p>'
            .($full ? '' : '<button type="button" class="mxs-more" data-mxs-more>Daha fazlasını göster</button>').'</div>';
        if ($item['link_url'] !== '') {
            $html .= '<a class="mxs-link" href="'.esc_url($item['link_url']).'">Ayrıntılı bilgi: '.esc_html($item['link_title'] !== '' ? $item['link_title'] : $item['link_url']).' →</a>';
        }
        $html .= '<div class="mxs-vote" data-mxs-vote="'.esc_attr($item['id']).'" data-page="'.(int) $qa_id.'"><span>İşe yaradı mı?</span>'
            .'<button type="button" data-yes="1">Evet</button><button type="button" data-yes="0">Hayır</button>'
            .($item['yes'] > 0 ? '<span class="mxs-count">· '.(int) $item['yes'].' kişi işe yaradı dedi</span>' : '').'</div>';

        return $html.'</article>';
    }

    private static function ask_form(array $target, $open)
    {
        $id = 'mxs-'.substr(md5(self::key($target).wp_rand()), 0, 8);
        $html = '<div class="mxs-ask">';
        if (! $open) {
            $html .= '<button type="button" class="mxs-askbar" data-mxs-open="'.esc_attr($id).'"><span>'.esc_html(self::target_title($target)).' hakkında aklınıza takılanı sorun…</span><span class="mxs-btn mxs-primary">Soru sor</span></button>';
        }
        $html .= '<form class="mxs-form" id="'.esc_attr($id).'" data-mxs-form'.($open ? '' : ' hidden').' novalidate>'
            .'<input type="hidden" name="target" value="'.esc_attr(self::key($target)).'"><input type="hidden" name="token" value="'.esc_attr(self::form_token()).'">'
            .'<div class="mxs-hp" aria-hidden="true"><label>Web siteniz<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            .'<label class="mxs-field"><span>Sorunuz</span><textarea name="question" required maxlength="1000" rows="4"></textarea></label>'
            .'<div class="mxs-two"><label class="mxs-field"><span>Adınız <small>(yayınlanmaz)</small></span><input type="text" name="name" autocomplete="name"></label>'
            .'<label class="mxs-field"><span>E-posta <small>(cevabı haber veririz)</small></span><input type="email" name="email" autocomplete="email"></label></div>'
            .'<label class="mxs-field"><span>Telefon <small>(isteğe bağlı)</small></span><input type="tel" name="phone" autocomplete="tel"></label>'
            .'<label class="mxs-consent"><input type="checkbox" name="consent" value="1"> Aydınlatma metnini okudum. Sorumun adım olmadan sitede yayınlanabileceğini biliyorum.</label>'
            .'<p class="mxs-error" role="alert" hidden></p><button type="submit" class="mxs-btn mxs-primary">Soruyu gönder</button></form>';

        return $html.'</div>';
    }

    public static function render_reviews($limit, $mode)
    {
        $data = get_option(self::REVIEWS_OPTION, []);
        if (! get_option(self::ENABLED_OPTION) || ! is_array($data) || empty($data['count'])) {
            return '';
        }
        $rating = number_format_i18n((float) $data['rating'], 1);
        $badge = '<a class="mxs-badge" href="'.esc_url((string) $data['url']).'" target="_blank" rel="noopener">'
            .'<span class="mxs-g" aria-hidden="true">G</span><span>Google’da <b>'.esc_html($rating).'</b> <span class="mxs-stars" aria-hidden="true">'.self::stars((int) round((float) $data['rating'])).'</span> · '.(int) $data['count'].' yorum</span></a>';
        if ($mode === 'badge') {
            return self::assets().'<div class="mxs mxs-rev">'.$badge.'</div>';
        }
        $items = (array) ($data['items'] ?? []);
        ob_start();
        echo self::assets();
        echo '<section class="mxs mxs-rev"><div class="mxs-head mxs-rev-head"><h2 class="mxs-title">Google yorumları</h2>'.$badge.'</div><div class="mxs-grid mxs-rev-grid" data-mxs-reviews data-step="'.(int) $limit.'">';
        foreach ($items as $i => $item) {
            $initial = mb_strtoupper(mb_substr((string) $item['name'], 0, 1));
            echo '<article class="mxs-card"'.($i >= $limit ? ' hidden' : '').'><div class="mxs-who"><span class="mxs-avatar" aria-hidden="true">'.esc_html($initial !== '' ? $initial : '★').'</span><span><b>'.esc_html((string) $item['name']).'</b><small>'
                .esc_html(trim(self::month((string) $item['date']).((string) $item['place'] !== '' && count((array) $data['places']) > 1 ? ' · '.$item['place'] : ''))).'</small></span></div>'
                .'<div class="mxs-stars" aria-label="'.(int) $item['stars'].' yıldız">'.self::stars((int) $item['stars']).'</div>'
                .'<div class="mxs-a mxs-clamp"><p>'.nl2br(esc_html((string) $item['text'])).'</p><button type="button" class="mxs-more" data-mxs-more>Daha fazlasını göster</button></div>'
                .((string) $item['reply'] !== '' ? '<details class="mxs-reply"><summary>İşletmenin yanıtı</summary><p>'.nl2br(esc_html((string) $item['reply'])).'</p></details>' : '')
                .'</article>';
        }
        echo '</div>';
        if (count($items) > $limit) {
            echo '<div class="mxs-actions"><button type="button" class="mxs-btn" data-mxs-reviews-more>Daha fazla yorum göster</button></div>';
        }
        echo '</section>';

        return (string) ob_get_clean();
    }

    private static function stars($n)
    {
        return str_repeat('★', max(0, min(5, $n))).str_repeat('☆', 5 - max(0, min(5, $n)));
    }

    private static function month($date)
    {
        $time = strtotime($date);
        if (! $time) {
            return '';
        }
        $months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

        return $months[(int) gmdate('n', $time) - 1].' '.gmdate('Y', $time);
    }

    /** One style and script per page, printed with the first block. Colours and fonts come from the site (Elementor globals). */
    private static function assets()
    {
        if (self::$assets_printed) {
            return '';
        }
        self::$assets_printed = true;
        $ask = wp_json_encode(rest_url('moxdop/v1/showcase/ask'));
        $vote = wp_json_encode(rest_url('moxdop/v1/showcase/vote'));
        $css = '.mxs{--mxs-accent:var(--e-global-color-primary,#0f7c80);--mxs-text:inherit;--mxs-muted:rgba(0,0,0,.6);--mxs-line:rgba(0,0,0,.12);--mxs-soft:rgba(0,0,0,.04);color:var(--mxs-text);font:inherit}'
            .'.mxs *{box-sizing:border-box}.mxs [hidden]{display:none!important}'
            .'.mxs-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:8px 20px;margin-bottom:20px}'
            .'.mxs-title{margin:0;font-size:1.6em;line-height:1.2}.mxs-sub,.mxs-lead{margin:4px 0 0;color:var(--mxs-muted)}'
            .'.mxs-askbar{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:10px;width:100%;padding:8px 8px 8px 20px;border:1px solid var(--mxs-line);border-radius:999px;cursor:pointer;margin:6px 0 24px}'
            .'.mxs-askbar>span:first-child{flex:1;min-width:0;color:var(--mxs-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            .'.mxs-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 20px;border:1px solid currentColor;border-radius:10px;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
            .'.mxs-primary{background:var(--mxs-accent);border-color:var(--mxs-accent);color:#fff;border-radius:999px}'
            .'.mxs-form{display:grid;gap:12px;margin:0 0 28px;padding:20px;border:1px solid var(--mxs-line);border-radius:16px}'
            .'.mxs-field{display:grid;gap:6px;font-weight:600;font-size:.92em}.mxs-field small{font-weight:400;color:var(--mxs-muted)}'
            .'.mxs-field input,.mxs-field textarea{width:100%;padding:11px 13px;border:1px solid var(--mxs-line);border-radius:10px;font:inherit;font-weight:400;background:transparent;color:inherit}'
            .'.mxs-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.mxs-two{grid-template-columns:1fr}}'
            .'.mxs-consent{display:flex;gap:8px;align-items:flex-start;font-size:.88em;color:var(--mxs-muted)}.mxs-consent input{margin-top:4px;accent-color:var(--mxs-accent)}'
            .'.mxs-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}.mxs-error{color:#c0392b;margin:0;font-size:.9em}'
            .'.mxs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:36px 64px;align-items:start}@media(max-width:760px){.mxs-grid{grid-template-columns:1fr;gap:30px}}'
            .'.mxs-rev-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:28px 36px}@media(max-width:960px){.mxs-rev-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.mxs-rev-grid{grid-template-columns:1fr}}'
            .'.mxs-list{grid-template-columns:1fr;gap:0}.mxs-list .mxs-card{padding:24px 0;border-bottom:1px solid var(--mxs-line)}'
            .'.mxs-card{display:grid;gap:8px;min-width:0;align-content:start}.mxs-who{display:flex;gap:12px;align-items:center}'
            .'.mxs-who b{display:block;font-weight:600}.mxs-who small{display:block;color:var(--mxs-muted);font-size:.88em}'
            .'.mxs-avatar{width:42px;height:42px;flex:none;border-radius:50%;display:grid;place-items:center;background:var(--mxs-soft);color:var(--mxs-muted);font-weight:700}'
            .'.mxs-q{margin:4px 0 0;font-size:1.1em;line-height:1.35}.mxs-a{display:grid;gap:4px;padding-left:12px;border-left:2px solid var(--mxs-line)}.mxs-a p{margin:0}'
            .'.mxs-clamp p{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
            .'.mxs-more{all:unset;cursor:pointer;font-weight:600;text-decoration:underline;font-size:.92em;justify-self:start}'
            .'.mxs-link{font-weight:600;font-size:.94em;color:var(--mxs-accent);text-decoration:none}.mxs-link:hover{text-decoration:underline}'
            .'.mxs-vote{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:.88em;color:var(--mxs-muted)}'
            .'.mxs-vote button{font:inherit;font-weight:600;padding:4px 12px;border:1px solid var(--mxs-line);border-radius:999px;background:transparent;color:inherit;cursor:pointer}'
            .'.mxs-thanks{color:#1d7a46;font-weight:600}.mxs-pending .mxs-q{opacity:.7}.mxs-wait{padding:10px 14px;border-radius:10px;background:#fdf3e2;color:#9a5c00;font-weight:600;font-size:.92em}'
            .'.mxs-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:32px}.mxs-privacy,.mxs-empty,.mxs-back{color:var(--mxs-muted);font-size:.88em;margin:16px 0 0}'
            .'.mxs-search input{width:100%;padding:12px 14px;border:1px solid var(--mxs-line);border-radius:12px;font:inherit;margin-bottom:18px}'
            .'.mxs-badge{display:inline-flex;align-items:center;gap:10px;padding:8px 16px;border:1px solid var(--mxs-line);border-radius:999px;color:inherit;text-decoration:none}'
            .'.mxs-g{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#fff;color:#4285f4;font-weight:800;border:1px solid var(--mxs-line)}'
            .'.mxs-stars{color:#f5a623;letter-spacing:1px}.mxs-reply{font-size:.92em;color:var(--mxs-muted)}.mxs-reply summary{cursor:pointer;font-weight:600}';
        $js = '(function(){var A='.$ask.',V='.$vote.';function k(t){return "mxs_q_"+t}'
            .'function pend(s){var b=s.querySelector("[data-mxs-pending]");if(!b)return;var l=[];try{l=JSON.parse(localStorage.getItem(k(s.dataset.target))||"[]")}catch(e){}'
            .'l=l.filter(function(x){return Date.now()-x.t<2592e6});if(!l.length)return;b.hidden=false;b.className="mxs-pending";b.innerHTML="";'
            .'l.forEach(function(x){var a=document.createElement("article");a.className="mxs-card";var h=document.createElement("h3");h.className="mxs-q";h.textContent=x.q;'
            .'var w=document.createElement("div");w.className="mxs-wait";w.textContent="Cevap bekleniyor. En geç 1 gün içinde yanıtlanır.";a.appendChild(h);a.appendChild(w);b.appendChild(a)})}'
            .'document.querySelectorAll(".mxs-qa").forEach(pend);'
            .'document.addEventListener("click",function(e){var o=e.target.closest("[data-mxs-open]");if(o){var f=document.getElementById(o.dataset.mxsOpen);f.hidden=!f.hidden;if(!f.hidden)f.querySelector("textarea").focus();return}'
            .'var m=e.target.closest("[data-mxs-more]");if(m){var a=m.closest(".mxs-a");a.classList.toggle("mxs-clamp");m.textContent=a.classList.contains("mxs-clamp")?"Daha fazlasını göster":"Daha az göster";return}'
            .'var r=e.target.closest("[data-mxs-reviews-more]");if(r){var g=r.closest(".mxs-rev").querySelector("[data-mxs-reviews]"),n=+g.dataset.step||6,h=g.querySelectorAll("article[hidden]");'
            .'for(var i=0;i<h.length&&i<n;i++)h[i].hidden=false;if(h.length<=n)r.remove();return}'
            .'var v=e.target.closest("[data-mxs-vote] button");if(v){var d=v.closest("[data-mxs-vote]");try{localStorage.setItem("mxs_v_"+d.dataset.page+"_"+d.dataset.mxsVote,"1")}catch(x){}'
            .'fetch(V,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({page:+d.dataset.page,item:d.dataset.mxsVote,yes:v.dataset.yes==="1"})}).catch(function(){});'
            .'d.innerHTML="<span class=\"mxs-thanks\">Teşekkürler, geri bildiriminiz alındı.</span>"}});'
            .'document.querySelectorAll("[data-mxs-vote]").forEach(function(d){try{if(localStorage.getItem("mxs_v_"+d.dataset.page+"_"+d.dataset.mxsVote))d.innerHTML="<span class=\"mxs-thanks\">Geri bildiriminiz alındı.</span>"}catch(x){}});'
            .'document.querySelectorAll("[data-mxs-search]").forEach(function(i){i.addEventListener("input",function(){var q=i.value.toLocaleLowerCase("tr");'
            .'i.closest(".mxs").querySelectorAll("[data-mxs-item]").forEach(function(c){c.hidden=q!==""&&c.textContent.toLocaleLowerCase("tr").indexOf(q)<0})})});'
            .'document.addEventListener("submit",function(e){var f=e.target.closest("[data-mxs-form]");if(!f)return;e.preventDefault();var er=f.querySelector(".mxs-error"),b=f.querySelector("button[type=submit]"),d={};'
            .'new FormData(f).forEach(function(v,k){d[k]=v});d.consent=f.querySelector("[name=consent]").checked;b.disabled=true;er.hidden=true;'
            .'fetch(A,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j}})}).then(function(x){b.disabled=false;'
            .'if(!x.ok){er.textContent=x.j&&x.j.message?x.j.message:"Gönderilemedi, tekrar deneyin.";er.hidden=false;return}'
            .'var s=f.closest(".mxs-qa");try{var l=JSON.parse(localStorage.getItem(k(s.dataset.target))||"[]");l.unshift({q:d.question,t:Date.now()});localStorage.setItem(k(s.dataset.target),JSON.stringify(l.slice(0,5)))}catch(z){}'
            .'f.innerHTML="<p><b>Sorunuz bize ulaştı.</b> En geç 1 gün içinde yanıtlanır ve bu sayfada adınız olmadan yayınlanır. E-posta bıraktıysanız cevap gelince haber veririz.</p>";pend(s)})'
            .'.catch(function(){b.disabled=false;er.textContent="Bağlantı kurulamadı, tekrar deneyin.";er.hidden=false})})})();';

        return '<style id="moxdop-showcase-css">'.$css.'</style><script id="moxdop-showcase-js">document.addEventListener("DOMContentLoaded",function(){'.$js.'});</script>';
    }

    /* ------------------------------------------------------------------ Elementor */

    public function elementor_widget($widgets_manager)
    {
        if (! class_exists('\Elementor\Widget_Base')) {
            return;
        }
        require_once MOXDOP_CONNECTOR_DIR.'includes/class-moxdop-connector-showcase-widget.php';
        $widgets_manager->register(new MoxDOP_Connector_Showcase_Widget);
    }

    /* ------------------------------------------------------------------ admin */

    public function menu()
    {
        add_options_page('Sorular ve yorumlar', 'Sorular ve yorumlar', 'manage_options', self::PAGE, [$this, 'render_settings']);
    }

    public function save_settings()
    {
        check_admin_referer('moxdop_showcase_save');
        if (! current_user_can('manage_options')) {
            wp_die('Bu ayarı yalnız site yöneticisi değiştirir.');
        }
        $in = wp_unslash($_POST);
        $types = array_keys(self::assignable_types());
        $taxes = array_keys(self::assignable_taxonomies());
        update_option(self::TARGETS_OPTION, [
            'pages' => array_values(array_filter(array_map('intval', (array) ($in['pages'] ?? [])))),
            'post_types' => array_values(array_intersect(array_map('sanitize_key', (array) ($in['post_types'] ?? [])), $types)),
            'taxonomies' => array_values(array_intersect(array_map('sanitize_key', (array) ($in['taxonomies'] ?? [])), $taxes)),
        ], false);
        $email = sanitize_email((string) ($in['notify'] ?? ''));
        update_option(self::NOTIFY_OPTION, is_email($email) ? $email : '', false);
        $made = 0;
        foreach (self::targets() as $target) {
            $made += self::qa_post($target, true) ? 1 : 0;
        }
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'saved' => $made], admin_url('options-general.php')));
        exit;
    }

    /** @return array<string, string> public post types an admin can assign (not attachments, not ours) */
    private static function assignable_types()
    {
        $out = [];
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if (! in_array($type->name, ['attachment', self::QA_TYPE, self::QUESTION_TYPE, 'elementor_library', 'e-landing-page'], true)) {
                $out[$type->name] = $type->labels->name;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function assignable_taxonomies()
    {
        $out = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $tax) {
            if (! in_array($tax->name, ['post_format', 'elementor_library_type', 'language', 'post_translations', 'term_language', 'term_translations'], true)) {
                $out[$tax->name] = $tax->labels->name;
            }
        }

        return $out;
    }

    public function render_settings()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $set = self::assignment();
        $enabled = (bool) get_option(self::ENABLED_OPTION);
        echo '<div class="wrap"><h1>Sorular ve yorumlar</h1>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Kaydedildi. Soru sayfası olan sayfa: '.(int) $_GET['saved'].'.</p></div>';
        }
        echo '<p>'.($enabled ? '<strong style="color:#1d7a46">MoxDOP\'ta açık.</strong> Yorumlar ve cevaplanan sorular MoxDOP\'tan kendiliğinden gelir.'
            : '<strong>MoxDOP\'ta kapalı.</strong> Marka ayarlarında "Sitede yorum ve soru göster" açılınca yorumlar ve sorular gelmeye başlar.').'</p>';
        echo '<p>Seçtiğin her sayfanın bir soru sayfası olur: <code>sayfa-adresi/'.self::SLUG.'/</code>. Bloğu sayfaya sen eklersin: Elementor\'da <strong>MoxDOP Sizden gelenler</strong> parçacığı ya da kısa kodlar <code>[moxdop_sorular]</code>, <code>[moxdop_yorumlar]</code>, <code>[moxdop_yorumlar gorunum="rozet"]</code>.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="moxdop_showcase_save">';
        wp_nonce_field('moxdop_showcase_save');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Sayfalar</th><td><select name="pages[]" multiple size="10" style="min-width:420px">';
        foreach (get_pages(['sort_column' => 'menu_order,post_title', 'lang' => '']) as $page) {
            echo '<option value="'.(int) $page->ID.'"'.selected(in_array((int) $page->ID, $set['pages'], true), true, false).'>'.esc_html(str_repeat('— ', count(get_post_ancestors($page))).$page->post_title).'</option>';
        }
        echo '</select><p class="description">Ctrl (Mac\'te Cmd) ile birden çok sayfa seç.</p></td></tr>';
        echo '<tr><th scope="row">Yazı türleri</th><td>';
        foreach (self::assignable_types() as $name => $label) {
            echo '<label style="display:block"><input type="checkbox" name="post_types[]" value="'.esc_attr($name).'"'.checked(in_array($name, $set['post_types'], true), true, false).'> '.esc_html($label).' <code>'.esc_html($name).'</code></label>';
        }
        echo '<p class="description">Seçilen türün yayındaki her kaydı soru sayfası alır (örneğin bütün hizmetler).</p></td></tr>';
        echo '<tr><th scope="row">Sınıflandırmalar</th><td>';
        foreach (self::assignable_taxonomies() as $name => $label) {
            echo '<label style="display:block"><input type="checkbox" name="taxonomies[]" value="'.esc_attr($name).'"'.checked(in_array($name, $set['taxonomies'], true), true, false).'> '.esc_html($label).' <code>'.esc_html($name).'</code></label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="mxs-notify">Bildirim e-postası</label></th><td><input type="email" id="mxs-notify" name="notify" class="regular-text" value="'.esc_attr((string) get_option(self::NOTIFY_OPTION)).'">'
            .'<p class="description">Yeni soru bu adrese gider (sitenin kendi e-posta / SMTP ayarıyla). Boş bırakılabilir; sorular her durumda <a href="'.esc_url(admin_url('edit.php?post_type='.self::QUESTION_TYPE)).'">Sorular</a> menüsünde ve MoxDOP\'ta görünür.</p></td></tr>';
        echo '</table>';
        submit_button('Kaydet');
        echo '</form>';
        $targets = self::targets();
        if ($targets) {
            echo '<h2>Soru sayfaları ('.count($targets).')</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Sayfa</th><th>Soru sayfası</th><th>Yanıtlanan</th></tr></thead><tbody>';
            foreach (array_slice($targets, 0, 200) as $target) {
                $qa = self::qa_post($target);
                $items = $qa ? get_post_meta($qa, self::ITEMS_META, true) : [];
                $n = is_array($items) ? count($items) : 0;
                echo '<tr><td>'.esc_html(self::target_title($target)).'</td><td>'.($qa ? '<a href="'.esc_url(get_permalink($qa)).'" target="_blank">'.esc_html(get_permalink($qa)).'</a>' : '—').'</td><td>'.(int) $n
                    .($n < self::INDEX_AT ? ' <span style="color:#646970">(Google\'a '.self::INDEX_AT.' soruda açılır)</span>' : '').'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public function question_columns($columns)
    {
        return ['cb' => $columns['cb'] ?? '', 'title' => 'Soru', 'mxs_page' => 'Sayfa', 'mxs_contact' => 'Soran (yayınlanmaz)', 'mxs_status' => 'Durum', 'date' => 'Tarih'];
    }

    public function question_column($column, $post_id)
    {
        if ($column === 'mxs_page') {
            $url = (string) get_post_meta($post_id, '_moxdop_q_page_url', true);
            echo $url !== '' ? '<a href="'.esc_url($url).'" target="_blank">'.esc_html((string) get_post_meta($post_id, '_moxdop_q_page_title', true)).'</a>' : '—';
        } elseif ($column === 'mxs_contact') {
            echo esc_html(implode(' · ', array_filter([(string) get_post_meta($post_id, '_moxdop_q_name', true), (string) get_post_meta($post_id, '_moxdop_q_email', true), (string) get_post_meta($post_id, '_moxdop_q_phone', true)]))) ?: '—';
        } elseif ($column === 'mxs_status') {
            echo get_post_meta($post_id, '_moxdop_q_status', true) === 'answered' ? 'Yanıtlandı, sitede' : 'MoxDOP\'ta cevap hazırlanıyor';
        }
    }

    /** Plugin removal: the data MoxDOP sent and the question pages go with it. */
    public static function uninstall()
    {
        foreach ([self::QA_TYPE, self::QUESTION_TYPE] as $type) {
            foreach (get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]) as $id) {
                wp_delete_post($id, true);
            }
        }
        foreach ([self::TARGETS_OPTION, self::NOTIFY_OPTION, self::REVIEWS_OPTION, self::ENABLED_OPTION] as $option) {
            delete_option($option);
        }
    }
}
