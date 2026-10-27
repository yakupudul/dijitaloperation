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

    /** 1.29.0: the admin's own wording of every visitor text (MoxDOP › Görünüm ve metinler). */
    const TEXTS_OPTION = 'moxdop_showcase_texts';

    /** 1.29.0: who answers (name, line, photo), accent colour, font, privacy page. */
    const LOOK_OPTION = 'moxdop_showcase_look';

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
        add_filter('single_template_hierarchy', [$this, 'page_template']);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('wp_sitemaps_posts_query_args', [$this, 'sitemap_args'], 10, 2);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', [$this, 'noindexed_ids']);
        add_shortcode('moxdop_sorular', [$this, 'shortcode_questions']);
        add_shortcode('moxdop_yorumlar', [$this, 'shortcode_reviews']);
        add_action('rest_api_init', [$this, 'public_routes']);
        add_action('elementor/widgets/register', [$this, 'elementor_widget']);
    }

    /* ------------------------------------------------------------------ types and addresses */

    public function types()
    {
        register_post_type(self::QA_TYPE, [
            'labels' => ['name' => 'Soru sayfaları', 'singular_name' => 'Soru sayfası'],
            'public' => true, 'publicly_queryable' => true, 'exclude_from_search' => true, 'show_ui' => true,
            'show_in_menu' => false, 'show_in_nav_menus' => false, 'show_in_rest' => false,
            'has_archive' => false, 'rewrite' => false, 'query_var' => false, 'supports' => ['title'],
            'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true,
        ]);
        register_post_type(self::QUESTION_TYPE, [
            'labels' => ['name' => 'Sorular', 'singular_name' => 'Soru', 'menu_name' => 'Sorular', 'all_items' => 'Gelen sorular'],
            // Answered and managed in MoxDOP › Gelen sorular (1.29.0), not in a WordPress list of its own.
            'public' => false, 'show_ui' => false, 'show_in_menu' => false,
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

    public static function parse_key($key)
    {
        if (! preg_match('/^(post|term):([1-9][0-9]*)$/', (string) $key, $m)) {
            return null;
        }

        return ['kind' => $m[1], 'id' => (int) $m[2]];
    }

    public static function key(array $target)
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

    public static function target_url(array $target)
    {
        if ($target['kind'] === 'post') {
            return get_post_status($target['id']) === 'publish' ? (string) get_permalink($target['id']) : '';
        }
        $link = get_term_link($target['id']);

        return is_wp_error($link) ? '' : (string) $link;
    }

    public static function target_title(array $target)
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

    /** The question page's index state from what it shows now (MoxDOP's questions and the admin's own answers). */
    public static function refresh_index($qa_id)
    {
        $key = (string) get_post_meta($qa_id, self::TARGET_META, true);
        self::set_index($qa_id, $key !== '' ? self::merged_items($qa_id, $key) : []);
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
            $items = $qa ? self::merged_items($qa, self::key($target)) : [];
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
                    self::refresh_index($qa);
                    $purge[] = (string) get_permalink($qa);
                    $purge[] = self::target_url($target);
                }
                $out['notified'] += self::notify_askers($items, (string) get_permalink($qa));
                $out['pages']++;
                $out['questions'] += count($items);
            }
        }
        // Questions taken off the site in MoxDOP (Marka ayarları › Kaldır) that the site admin had answered here.
        foreach (array_slice((array) ($body['qa_hidden'] ?? []), 0, 500) as $local) {
            $local = (int) $local;
            if ($local > 0 && get_post_type($local) === self::QUESTION_TYPE && get_post_meta($local, '_moxdop_q_status', true) === 'local') {
                update_post_meta($local, '_moxdop_q_status', 'hidden');
                $qa = self::qa_post(self::parse_key((string) get_post_meta($local, '_moxdop_q_target', true)) ?: ['kind' => 'post', 'id' => 0]);
                if ($qa) {
                    self::refresh_index($qa);
                    $purge[] = (string) get_permalink($qa);
                }
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
            // Answered or taken down by the site admin: their state stays.
            if (! in_array(get_post_meta($local, '_moxdop_q_status', true), ['local', 'hidden'], true)) {
                update_post_meta($local, '_moxdop_q_status', 'answered');
            }
            $sent += self::notify_asker($local, (string) $item['q'], (string) $item['a'], $qa_url) ? 1 : 0;
        }

        return $sent;
    }

    /** One e-mail per question, in the admin's wording (MoxDOP › Görünüm ve metinler), through the site's own mail. */
    public static function notify_asker($question_id, $question, $answer, $qa_url)
    {
        $email = (string) get_post_meta($question_id, '_moxdop_q_email', true);
        if ($email === '' || ! is_email($email) || get_post_meta($question_id, '_moxdop_q_notified', true)) {
            return false;
        }
        update_post_meta($question_id, '_moxdop_q_notified', gmdate('c'));
        $vars = ['soru' => $question, 'cevap' => $answer, 'link' => $qa_url, 'site' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)];

        return (bool) wp_mail($email, self::plain('mail_subject', $vars), self::plain('mail_body', $vars));
    }

    public static function purge_urls(array $urls)
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
                ."\n\nCevabı hemen kendin yazabilirsin: ".MoxDOP_Connector_Admin_UI::url(MoxDOP_Connector_Showcase_Admin::QUESTIONS)
                ."\nYazmazsan cevap MoxDOP'ta hazırlanır ve onaylanınca sitede adsız yayınlanır.";
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
        $items = $qa > 0 && get_post_type($qa) === self::QA_TYPE ? self::merged_items($qa, (string) get_post_meta($qa, self::TARGET_META, true)) : [];
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

    /* ------------------------------------------------------------------ texts and look (MoxDOP › Görünüm ve metinler) */

    /**
     * Every fixed text a visitor sees, by group: key => [label, default]. "*kelime*" in a title marks that word with the
     * accent colour; {n}, {sayfa}, {puan}, {soru}, {cevap}, {link}, {site} are filled in.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function text_groups()
    {
        return [
            'Soru bölümü' => [
                'qa_title' => ['Başlık', 'Sizden gelen *sorular*'],
                'qa_count' => ['Sayaç', '{n} soru yanıtlandı'],
                'qa_sub' => ['Başlık altı', 'Sorular genellikle 1 gün içinde yanıtlanır'],
                'ask_bar' => ['Soru sorma çubuğu', '{sayfa} hakkında aklınıza takılanı sorun…'],
                'ask_button' => ['Soru sor butonu', 'Soru sor'],
                'all_button' => ['Tümünü göster butonu', 'Tümünü göster ({n})'],
                'qa_page_button' => ['Soru sayfası butonu', 'Soru sayfasına git'],
                'empty' => ['Henüz soru yokken', 'Henüz yanıtlanmış soru yok. İlk soruyu siz sorun.'],
                'privacy' => ['Gizlilik notu', 'Adınız ve iletişim bilgileriniz sitede hiçbir zaman görünmez.'],
            ],
            'Soru kartı' => [
                'asked_site' => ['Ziyaretçi sorusu etiketi', 'Bir ziyaretçi sordu'],
                'asked_data' => ['Sık sorulan soru etiketi', 'Sık sorulan soru'],
                'more' => ['Devamı', 'Devamını oku'],
                'less' => ['Daha az', 'Daha az göster'],
                'detail' => ['Ayrıntı bağlantısı', 'Ayrıntılı bilgi'],
                'vote_question' => ['Oylama sorusu', 'İşe yaradı mı?'],
                'vote_yes' => ['Evet', 'Evet'],
                'vote_no' => ['Hayır', 'Hayır'],
                'vote_count' => ['Oy sayısı', '{n} kişi işe yaradı dedi'],
                'vote_thanks' => ['Oy verince', 'Teşekkürler, geri bildiriminiz alındı.'],
            ],
            'Soru formu' => [
                'f_question' => ['Soru alanı', 'Sorunuz'],
                'f_name' => ['Ad alanı', 'Adınız'],
                'f_name_hint' => ['Ad alanı notu', 'yayınlanmaz'],
                'f_email' => ['E-posta alanı', 'E-posta'],
                'f_email_hint' => ['E-posta notu', 'cevabı haber veririz'],
                'f_phone' => ['Telefon alanı', 'Telefon'],
                'f_phone_hint' => ['Telefon notu', 'isteğe bağlı'],
                'consent' => ['Onay kutusu', 'Aydınlatma metnini okudum. Sorumun adım olmadan sitede yayınlanabileceğini biliyorum.'],
                'f_submit' => ['Gönder butonu', 'Soruyu gönder'],
                'sent' => ['Gönderince', 'Sorunuz bize ulaştı. En geç 1 gün içinde yanıtlanır ve bu sayfada adınız olmadan yayınlanır. E-posta bıraktıysanız cevap gelince haber veririz.'],
                'pending' => ['Cevap beklerken', 'Cevap bekleniyor. En geç 1 gün içinde yanıtlanır.'],
            ],
            'Soru sayfası' => [
                'page_lead' => ['Giriş cümlesi', '{sayfa} hakkında sorulan sorular ve cevapları. Sizin sorunuz burada yoksa hemen sorun.'],
                'search' => ['Arama kutusu', 'Sorularda ara'],
                'back' => ['Geri dön bağlantısı', '{sayfa} sayfasına dön'],
            ],
            'Yorumlar' => [
                'rev_title' => ['Başlık', 'Google *yorumları*'],
                'rev_badge' => ['Puan rozeti', 'Google’da {puan} · {n} yorum'],
                'rev_more' => ['Daha fazla butonu', 'Daha fazla yorum göster'],
                'rev_reply' => ['İşletme yanıtı', 'İşletmenin yanıtı'],
            ],
            'Soruyu sorana giden e-posta' => [
                'mail_subject' => ['Konu', 'Sorunuz yanıtlandı · {site}'],
                'mail_body' => ['Metin', "Merhaba,\n\nSitemizde sorduğunuz soru yanıtlandı.\n\nSorunuz: {soru}\n\nCevabımız: {cevap}\n\nTüm sorular ve cevaplar: {link}\n\n{site}"],
            ],
        ];
    }

    /** A visitor text: the admin's own wording, else the default; placeholders filled in (plain text). */
    public static function text($key, array $vars = [])
    {
        static $saved = null;
        if ($saved === null) {
            $saved = get_option(self::TEXTS_OPTION, []);
            $saved = is_array($saved) ? $saved : [];
        }
        $value = trim((string) ($saved[$key] ?? ''));
        if ($value === '') {
            foreach (self::text_groups() as $fields) {
                if (isset($fields[$key])) {
                    $value = $fields[$key][1];
                    break;
                }
            }
        }
        $replace = [];
        foreach ($vars as $name => $var) {
            $replace['{'.$name.'}'] = (string) $var;
        }

        return strtr($value, $replace);
    }

    /** A title with its "*marked*" word in the accent colour (escaped). */
    public static function title($key, array $vars = [])
    {
        return (string) preg_replace('/\*([^*]+)\*/u', '<mark>$1</mark>', esc_html(self::text($key, $vars)));
    }

    /** Plain text without the "*" marks (mail, aria labels). */
    private static function plain($key, array $vars = [])
    {
        return str_replace('*', '', self::text($key, $vars));
    }

    /**
     * Look: who answers (name, line under it, photo), the accent colour and the font. Empty fields follow the site:
     * the site name, the Elementor kit's primary colour and font.
     *
     * @return array{name: string, role: string, photo: string, accent: string, ink: string, font: string}
     */
    public static function look()
    {
        static $look = null;
        if ($look !== null) {
            return $look;
        }
        $saved = get_option(self::LOOK_OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $kit = self::elementor_kit();
        $accent = sanitize_hex_color((string) ($saved['accent'] ?? '')) ?: ($kit['color'] ?: '#fdcf3c');
        $photo = (int) ($saved['photo'] ?? 0) > 0 ? (string) wp_get_attachment_image_url((int) $saved['photo'], 'thumbnail') : '';
        $font = trim((string) ($saved['font'] ?? ''));

        $look = [
            'name' => trim((string) ($saved['name'] ?? '')) !== '' ? trim((string) $saved['name']) : wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'role' => trim((string) ($saved['role'] ?? '')) !== '' ? trim((string) $saved['role']) : 'Uzman ekibimiz',
            'photo' => $photo,
            'accent' => $accent,
            'ink' => self::ink($accent),
            'font' => preg_replace('/[^\p{L}\p{N} ,\'"_-]/u', '', $font !== '' ? $font : $kit['font']),
        ];

        return $look;
    }

    /** The active Elementor kit's primary colour and body font ('' when there is none). @return array{color: string, font: string} */
    private static function elementor_kit()
    {
        $kit = (int) get_option('elementor_active_kit');
        $settings = $kit > 0 ? get_post_meta($kit, '_elementor_page_settings', true) : [];
        $settings = is_array($settings) ? $settings : [];
        $color = '';
        foreach ((array) ($settings['system_colors'] ?? []) as $row) {
            if (($row['_id'] ?? '') === 'primary') {
                $color = (string) sanitize_hex_color((string) ($row['color'] ?? ''));
            }
        }
        $font = '';
        foreach ((array) ($settings['system_typography'] ?? []) as $row) {
            if (($row['_id'] ?? '') === 'text' && ! empty($row['typography_font_family'])) {
                $font = (string) $row['typography_font_family'];
            }
        }

        return ['color' => $color, 'font' => $font];
    }

    /** Near-black or white, whichever reads on the accent. */
    private static function ink($hex)
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return '#141416';
        }
        $l = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;

        return $l > 0.62 ? '#141416' : '#ffffff';
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

    /** 1.29.0: a question page uses the theme's page template, so no "Written by", post navigation or "More posts". */
    public function page_template($templates)
    {
        return is_singular(self::QA_TYPE) ? array_merge(['page.php'], (array) $templates) : $templates;
    }

    public function page_content($content)
    {
        if (! is_singular(self::QA_TYPE) || ! in_the_loop() || ! is_main_query()) {
            return $content;
        }
        $target = self::parse_key((string) get_post_meta(get_the_ID(), self::TARGET_META, true));

        return $target ? self::render_questions($target, 0, true) : $content;
    }

    /**
     * Visitor questions the site admin answered here (MoxDOP › Gelen sorular): they show at once and win over the
     * version MoxDOP sent for the same question; a question the admin took down never shows.
     *
     * @return array{answered: array<int, array<string, mixed>>, hidden: list<int>}
     */
    private static function local_answers($key)
    {
        $out = ['answered' => [], 'hidden' => []];
        $ids = get_posts(['post_type' => self::QUESTION_TYPE, 'post_status' => 'private', 'numberposts' => 500, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true,
            'meta_query' => [['key' => '_moxdop_q_target', 'value' => $key], ['key' => '_moxdop_q_status', 'value' => ['local', 'hidden'], 'compare' => 'IN']]]);
        foreach ($ids as $id) {
            if (get_post_meta($id, '_moxdop_q_status', true) === 'hidden') {
                $out['hidden'][] = (int) $id;

                continue;
            }
            $answer = (string) get_post_meta($id, '_moxdop_q_answer', true);
            if ($answer === '') {
                continue;
            }
            $question = (string) get_post_meta($id, '_moxdop_q_public', true);
            $out['answered'][(int) $id] = ['id' => 'l'.(int) $id, 'q' => $question !== '' ? $question : (string) get_post_field('post_content', $id), 'a' => $answer,
                'date' => (string) get_the_date('Y-m-d', $id), 'source' => 'site', 'link_url' => '', 'link_title' => '', 'local_id' => (int) $id];
        }

        return $out;
    }

    /** MoxDOP's questions of a page with the admin's own answers merged in. @return list<array<string, mixed>> */
    public static function merged_items($qa_id, $key)
    {
        $items = $qa_id ? get_post_meta($qa_id, self::ITEMS_META, true) : [];
        $items = is_array($items) ? $items : [];
        $local = self::local_answers($key);
        $out = [];
        foreach ($items as $item) {
            $id = (int) ($item['local_id'] ?? 0);
            if ($id > 0 && in_array($id, $local['hidden'], true)) {
                continue;
            }
            if ($id > 0 && isset($local['answered'][$id])) {
                $item['q'] = $local['answered'][$id]['q'];
                $item['a'] = $local['answered'][$id]['a'];
                unset($local['answered'][$id]);
            }
            $out[] = $item;
        }

        // Answered here and not back from MoxDOP yet: newest first, before MoxDOP's list.
        return array_merge(array_reverse(array_values($local['answered'])), $out);
    }

    /** @return list<array<string, mixed>> published questions of a target, most helpful first (MoxDOP's order on ties) */
    private static function items(array $target, &$qa_id)
    {
        $qa_id = self::assigned($target) ? self::qa_post($target, true) : 0;
        $items = $qa_id ? self::merged_items($qa_id, self::key($target)) : [];
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
        $page = self::target_title($target);
        $qa_url = (string) get_permalink($qa_id);
        $eyebrow = trim(($total > 0 ? self::text('qa_count', ['n' => $total]).' · ' : '').self::text('qa_sub'), ' ·');
        ob_start();
        echo self::assets();
        echo '<section class="mxs mxs-qa'.($full ? ' mxs-full' : '').'" data-target="'.esc_attr(self::key($target)).'" data-page="'.(int) $qa_id.'">';
        echo '<header class="mxs-head">';
        if ($eyebrow !== '') {
            echo '<span class="mxs-eyebrow"><i class="mxs-live" aria-hidden="true"></i>'.esc_html($eyebrow).'</span>';
        }
        if ($full) {
            echo '<p class="mxs-lead">'.esc_html(self::text('page_lead', ['sayfa' => $page])).'</p>';
        } else {
            echo '<h2 class="mxs-title">'.self::title('qa_title', ['sayfa' => $page]).'</h2>';
        }
        echo '</header>';
        if ($full && $total > 6) {
            echo '<label class="mxs-search"><span class="mxs-sr">'.esc_html(self::text('search')).'</span><input type="search" placeholder="'.esc_attr(self::text('search')).'" data-mxs-search></label>';
        }
        echo self::ask_form($target, $page);
        echo '<div class="mxs-grid'.($full ? ' mxs-list' : '').'" data-mxs-list>';
        echo '<div class="mxs-pending" data-mxs-pending hidden></div>';
        foreach ($shown as $item) {
            echo self::question_card($item, $qa_id);
        }
        echo '</div>';
        if ($total === 0) {
            echo '<p class="mxs-empty">'.esc_html(self::text('empty')).'</p>';
        }
        echo '<footer class="mxs-foot">';
        if (! $full) {
            echo '<a class="mxs-btn mxs-dark" href="'.esc_url($qa_url).'">'.esc_html($total > count($shown) ? self::text('all_button', ['n' => $total]) : self::text('qa_page_button')).' <span aria-hidden="true">→</span></a>';
        } else {
            echo '<a class="mxs-btn" href="'.esc_url(self::target_url($target)).'"><span aria-hidden="true">←</span> '.esc_html(self::text('back', ['sayfa' => $page])).'</a>';
        }
        echo '<p class="mxs-privacy">'.esc_html(self::text('privacy')).'</p></footer>';
        echo '</section>';

        return (string) ob_get_clean();
    }

    /** The answerer's photo, or their initial on the accent colour. */
    private static function avatar()
    {
        $look = self::look();
        if ($look['photo'] !== '') {
            return '<img class="mxs-avatar" src="'.esc_url($look['photo']).'" alt="" loading="lazy" width="40" height="40">';
        }

        return '<span class="mxs-avatar mxs-initial" aria-hidden="true">'.esc_html(mb_strtoupper(mb_substr($look['name'], 0, 1))).'</span>';
    }

    private static function question_card(array $item, $qa_id)
    {
        $look = self::look();
        $date = self::month((string) ($item['date'] ?? ''));
        $html = '<article class="mxs-item" data-mxs-item>';
        $html .= '<div class="mxs-meta"><span class="mxs-chip">'.esc_html(self::text($item['source'] === 'site' ? 'asked_site' : 'asked_data')).'</span>'.($date !== '' ? '<span>'.esc_html($date).'</span>' : '').'</div>';
        $html .= '<h3 class="mxs-q">'.esc_html($item['q']).'</h3>';
        $html .= '<div class="mxs-answer"><div class="mxs-by">'.self::avatar().'<span><b>'.esc_html($look['name']).'</b><small>'.esc_html($look['role']).'</small></span></div>';
        $html .= '<div class="mxs-a mxs-clamp"><p>'.nl2br(esc_html($item['a'])).'</p></div><button type="button" class="mxs-more" data-mxs-more hidden>'.esc_html(self::text('more')).'</button>';
        if ($item['link_url'] !== '') {
            $html .= '<a class="mxs-link" href="'.esc_url($item['link_url']).'">'.esc_html(self::text('detail')).': '.esc_html($item['link_title'] !== '' ? $item['link_title'] : $item['link_url']).' <span aria-hidden="true">→</span></a>';
        }
        $html .= '</div>';
        $html .= '<div class="mxs-vote" data-mxs-vote="'.esc_attr($item['id']).'" data-page="'.(int) $qa_id.'"><span>'.esc_html(self::text('vote_question')).'</span>'
            .'<button type="button" data-yes="1">'.esc_html(self::text('vote_yes')).'</button><button type="button" data-yes="0">'.esc_html(self::text('vote_no')).'</button>'
            .($item['yes'] > 0 ? '<span class="mxs-count">'.esc_html(self::text('vote_count', ['n' => (int) $item['yes']])).'</span>' : '').'</div>';

        return $html.'</article>';
    }

    private static function ask_form(array $target, $page)
    {
        $id = 'mxs-'.substr(md5(self::key($target).wp_rand()), 0, 8);
        $hint = static function ($key) {
            $text = self::text($key);

            return $text !== '' ? ' <small>('.esc_html($text).')</small>' : '';
        };
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        $saved = get_option(self::LOOK_OPTION, []);
        $privacy = (int) ($saved['privacy_page'] ?? 0) > 0 ? (int) $saved['privacy_page'] : $privacy;
        $privacy_url = $privacy > 0 && get_post_status($privacy) === 'publish' ? (string) get_permalink($privacy) : '';
        $html = '<div class="mxs-ask">';
        $html .= '<button type="button" class="mxs-askbar" data-mxs-open="'.esc_attr($id).'" aria-controls="'.esc_attr($id).'"><span class="mxs-askicon" aria-hidden="true">?</span><span class="mxs-asktext">'.esc_html(self::text('ask_bar', ['sayfa' => $page])).'</span><span class="mxs-btn mxs-primary">'.esc_html(self::text('ask_button')).'</span></button>';
        $html .= '<form class="mxs-form" id="'.esc_attr($id).'" data-mxs-form hidden novalidate>'
            .'<input type="hidden" name="target" value="'.esc_attr(self::key($target)).'"><input type="hidden" name="token" value="'.esc_attr(self::form_token()).'">'
            .'<div class="mxs-hp" aria-hidden="true"><label>Web siteniz<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            .'<label class="mxs-field"><span>'.esc_html(self::text('f_question')).'</span><textarea name="question" required maxlength="1000" rows="4"></textarea></label>'
            .'<div class="mxs-two"><label class="mxs-field"><span>'.esc_html(self::text('f_name')).$hint('f_name_hint').'</span><input type="text" name="name" autocomplete="name"></label>'
            .'<label class="mxs-field"><span>'.esc_html(self::text('f_email')).$hint('f_email_hint').'</span><input type="email" name="email" autocomplete="email"></label></div>'
            .'<label class="mxs-field"><span>'.esc_html(self::text('f_phone')).$hint('f_phone_hint').'</span><input type="tel" name="phone" autocomplete="tel"></label>'
            .'<label class="mxs-consent"><input type="checkbox" name="consent" value="1"> <span>'.esc_html(self::text('consent'))
            .($privacy_url !== '' ? ' <a href="'.esc_url($privacy_url).'" target="_blank" rel="noopener">'.esc_html(get_the_title($privacy)).'</a>' : '').'</span></label>'
            .'<p class="mxs-error" role="alert" hidden></p><div class="mxs-formfoot"><button type="submit" class="mxs-btn mxs-primary">'.esc_html(self::text('f_submit')).'</button></div></form>';

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
            .'<span class="mxs-g" aria-hidden="true">G</span><span class="mxs-stars" aria-hidden="true">'.self::stars((int) round((float) $data['rating'])).'</span>'
            .'<span>'.esc_html(self::text('rev_badge', ['puan' => $rating, 'n' => (int) $data['count']])).'</span></a>';
        if ($mode === 'badge') {
            return self::assets().'<div class="mxs mxs-rev">'.$badge.'</div>';
        }
        $items = (array) ($data['items'] ?? []);
        ob_start();
        echo self::assets();
        echo '<section class="mxs mxs-rev"><header class="mxs-head mxs-rev-head">'.$badge.'<h2 class="mxs-title">'.self::title('rev_title').'</h2></header>';
        echo '<div class="mxs-grid mxs-rev-grid" data-mxs-reviews data-step="'.(int) $limit.'">';
        foreach ($items as $i => $item) {
            $initial = mb_strtoupper(mb_substr((string) $item['name'], 0, 1));
            $place = (string) $item['place'] !== '' && count((array) $data['places']) > 1 ? ' · '.$item['place'] : '';
            echo '<article class="mxs-item"'.($i >= $limit ? ' hidden' : '').'>'
                .'<div class="mxs-stars" aria-label="'.(int) $item['stars'].' / 5">'.self::stars((int) $item['stars']).'</div>'
                .'<div class="mxs-a mxs-clamp"><p>'.nl2br(esc_html((string) $item['text'])).'</p></div><button type="button" class="mxs-more" data-mxs-more hidden>'.esc_html(self::text('more')).'</button>'
                .((string) $item['reply'] !== '' ? '<details class="mxs-reply"><summary>'.esc_html(self::text('rev_reply')).'</summary><p>'.nl2br(esc_html((string) $item['reply'])).'</p></details>' : '')
                .'<div class="mxs-by"><span class="mxs-avatar mxs-soft" aria-hidden="true">'.esc_html($initial !== '' ? $initial : '★').'</span><span><b>'.esc_html((string) $item['name']).'</b><small>'
                .esc_html(trim(self::month((string) $item['date']).$place, ' ·')).'</small></span></div>'
                .'</article>';
        }
        echo '</div>';
        if (count($items) > $limit) {
            echo '<footer class="mxs-foot"><button type="button" class="mxs-btn mxs-dark" data-mxs-reviews-more>'.esc_html(self::text('rev_more')).'</button></footer>';
        }
        echo '</section>';

        return (string) ob_get_clean();
    }

    private static function stars($n)
    {
        return str_repeat('★', max(0, min(5, $n))).'<span class="mxs-off">'.str_repeat('★', 5 - max(0, min(5, $n))).'</span>';
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

    /**
     * One style and script per page, printed with the first block. The MoxDOP panel's language (near-black text, one
     * accent colour, soft cards that lift, pill buttons, a marked word in the title); the accent and font follow
     * MoxDOP › Görünüm ve metinler, else the site's Elementor kit.
     */
    private static function assets()
    {
        if (self::$assets_printed) {
            return '';
        }
        self::$assets_printed = true;
        $look = self::look();
        $font = $look['font'] !== '' ? '"'.str_replace('"', '', $look['font']).'",' : '';
        $js_texts = wp_json_encode(['ask' => rest_url('moxdop/v1/showcase/ask'), 'vote' => rest_url('moxdop/v1/showcase/vote'), 'sent' => self::text('sent'),
            'pending' => self::text('pending'), 'thanks' => self::text('vote_thanks'), 'more' => self::text('more'), 'less' => self::text('less')]);
        $css = '.mxs{--mxs-accent:'.esc_attr($look['accent']).';--mxs-ink:'.esc_attr($look['ink']).';--mxs-text:#191918;--mxs-muted:#787774;--mxs-line:#ebebe9;--mxs-soft:#f6f6f3;'
            .'--mxs-font:'.$font.'Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;'
            .'color:var(--mxs-text);font-family:var(--mxs-font);font-size:16px;line-height:1.6;font-weight:400;letter-spacing:normal;-webkit-font-smoothing:antialiased;text-align:left}'
            .'.mxs *{box-sizing:border-box;font-family:inherit}.mxs [hidden]{display:none!important}.mxs-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
            .'.mxs mark{background:var(--mxs-accent);color:var(--mxs-ink);border-radius:.32em;padding:0 .22em;-webkit-box-decoration-break:clone;box-decoration-break:clone}'
            .'.mxs .mxs-head{display:grid;gap:12px;justify-items:start;margin:0 0 22px}'
            .'.mxs .mxs-eyebrow{display:inline-flex;align-items:center;gap:8px;min-height:30px;padding:3px 13px;border:1px solid var(--mxs-line);border-radius:999px;background:#fff;color:#5f5e5b;font-size:13px;line-height:1.4;box-shadow:0 1px 2px rgba(20,20,20,.04)}'
            .'.mxs .mxs-live{width:7px;height:7px;border-radius:50%;background:#22a35a;box-shadow:0 0 0 3px rgba(34,163,90,.16);flex:none}'
            .'.mxs .mxs-title{margin:0;padding:0;font-family:inherit;font-size:clamp(26px,3.2vw,36px);line-height:1.2;font-weight:650;letter-spacing:-.03em;color:var(--mxs-text);text-transform:none}'
            .'.mxs .mxs-lead{margin:0;max-width:70ch;color:var(--mxs-muted);font-size:17px}'
            .'.mxs .mxs-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 20px;border:1px solid #d9d9d6;border-radius:12px;background:#fff;color:var(--mxs-text);font:inherit;font-size:15px;font-weight:600;line-height:1.2;cursor:pointer;text-decoration:none;transition:transform .15s,box-shadow .15s,filter .15s;box-shadow:0 1px 2px rgba(20,20,20,.05)}'
            .'.mxs .mxs-btn:hover{transform:translateY(-1px);box-shadow:0 10px 30px -12px rgba(20,20,40,.25)}'
            .'.mxs .mxs-primary{background:var(--mxs-accent);border-color:transparent;color:var(--mxs-ink);box-shadow:0 2px 8px color-mix(in srgb,var(--mxs-accent) 40%,transparent)}.mxs .mxs-primary:hover{filter:brightness(.97)}'
            .'.mxs .mxs-dark{background:#191918;border-color:#191918;color:#fff}'
            .'.mxs .mxs-ask{margin:0 0 26px}'
            .'.mxs .mxs-askbar{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:12px;width:100%;padding:7px 7px 7px 10px;border:1px solid var(--mxs-line);border-radius:18px;background:#fff;cursor:pointer;box-shadow:0 1px 2px rgba(20,20,20,.04);transition:box-shadow .15s,border-color .15s}'
            .'.mxs .mxs-askbar:hover,.mxs .mxs-askbar:focus-visible{border-color:#d9d9d6;box-shadow:0 10px 30px -14px rgba(20,20,40,.25)}'
            .'.mxs .mxs-askicon{width:34px;height:34px;flex:none;border-radius:10px;background:var(--mxs-soft);display:grid;place-items:center;font-weight:700;color:var(--mxs-muted)}'
            .'.mxs .mxs-asktext{flex:1;min-width:0;color:var(--mxs-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:15px}'
            .'.mxs .mxs-form{display:grid;gap:14px;margin:12px 0 0;padding:22px;border:1px solid var(--mxs-line);border-radius:20px;background:#fff;box-shadow:0 10px 30px -18px rgba(20,20,40,.2)}'
            .'.mxs .mxs-field{display:grid;gap:6px;font-weight:600;font-size:14px;margin:0}.mxs .mxs-field small{font-weight:400;color:var(--mxs-muted)}'
            .'.mxs .mxs-field input,.mxs .mxs-field textarea{width:100%;margin:0;padding:11px 14px;border:1px solid #d9d9d6;border-radius:12px;font:inherit;font-size:15px;font-weight:400;background:#fff;color:var(--mxs-text);box-shadow:none}'
            .'.mxs .mxs-field input:focus,.mxs .mxs-field textarea:focus{outline:0;border-color:#191918;box-shadow:0 0 0 3px rgba(25,25,24,.08)}'
            .'.mxs .mxs-two{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:600px){.mxs .mxs-two{grid-template-columns:1fr}}'
            .'.mxs .mxs-consent{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;color:var(--mxs-muted);margin:0}.mxs .mxs-consent input{margin-top:3px;accent-color:#191918;flex:none}.mxs .mxs-consent a{color:inherit;text-decoration:underline}'
            .'.mxs .mxs-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}.mxs .mxs-error{color:#b42318;margin:0;font-size:14px}.mxs .mxs-formfoot{display:flex;justify-content:flex-end}'
            .'.mxs .mxs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start}@media(max-width:820px){.mxs .mxs-grid{grid-template-columns:1fr}}'
            .'.mxs .mxs-list{grid-template-columns:1fr;max-width:860px}.mxs .mxs-rev-grid{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:1000px){.mxs .mxs-rev-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.mxs .mxs-rev-grid{grid-template-columns:1fr}}'
            .'.mxs .mxs-item{display:grid;gap:12px;min-width:0;padding:20px;border-radius:22px;background:var(--mxs-soft);border:1px solid transparent;transition:transform .15s,box-shadow .15s}'
            .'.mxs .mxs-item:hover{transform:translateY(-2px);box-shadow:0 10px 30px -12px rgba(20,20,40,.18)}'
            .'.mxs .mxs-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:13px;color:var(--mxs-muted)}'
            .'.mxs .mxs-chip{display:inline-flex;align-items:center;height:24px;padding:0 9px;border-radius:7px;background:#fff;color:#454441;font-weight:600;font-size:12px;box-shadow:0 1px 2px rgba(20,20,20,.05)}'
            .'.mxs .mxs-q{margin:0;padding:0;font-family:inherit;font-size:19px;line-height:1.35;font-weight:650;letter-spacing:-.015em;color:var(--mxs-text);text-transform:none}'
            .'.mxs .mxs-answer{display:grid;gap:10px;padding:16px;border-radius:16px;background:#fff;box-shadow:0 1px 2px rgba(20,20,20,.04)}'
            .'.mxs .mxs-by{display:flex;align-items:center;gap:11px;min-width:0}.mxs .mxs-by b{display:block;font-weight:650;font-size:14px;line-height:1.3}.mxs .mxs-by small{display:block;color:var(--mxs-muted);font-size:13px;line-height:1.3}'
            .'.mxs .mxs-avatar{width:40px;height:40px;flex:none;border-radius:12px;object-fit:cover;display:grid;place-items:center;font-weight:700;font-size:16px;margin:0}'
            .'.mxs .mxs-initial{background:var(--mxs-accent);color:var(--mxs-ink)}.mxs .mxs-soft{background:#fff;color:var(--mxs-muted);box-shadow:0 1px 2px rgba(20,20,20,.06)}'
            .'.mxs .mxs-a p{margin:0;color:#2f2f2d;font-size:15.5px;line-height:1.65}.mxs .mxs-clamp p{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}'
            .'.mxs .mxs-more{all:unset;cursor:pointer;font-weight:600;font-size:14px;color:var(--mxs-text);text-decoration:underline;text-underline-offset:3px;justify-self:start}'
            .'.mxs .mxs-link{font-weight:600;font-size:14px;color:var(--mxs-text);text-decoration:none;border-top:1px solid var(--mxs-line);padding-top:10px}.mxs .mxs-link:hover{text-decoration:underline}'
            .'.mxs .mxs-vote{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:13px;color:var(--mxs-muted)}'
            .'.mxs .mxs-vote button{font:inherit;font-weight:600;font-size:13px;height:30px;padding:0 13px;border:1px solid var(--mxs-line);border-radius:999px;background:#fff;color:var(--mxs-text);cursor:pointer;line-height:1}'
            .'.mxs .mxs-vote button:hover{border-color:#191918}.mxs .mxs-count{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:7px;background:#eaf6ee;color:#14532d;font-weight:600;font-size:12px}'
            .'.mxs .mxs-thanks{color:#14532d;font-weight:600}.mxs .mxs-pending{display:contents}.mxs .mxs-pending .mxs-item{background:#fff;border:1px dashed #d9d9d6}'
            .'.mxs .mxs-wait{display:inline-flex;align-self:start;padding:6px 12px;border-radius:9px;background:color-mix(in srgb,var(--mxs-accent) 18%,#fff);color:#454441;font-weight:600;font-size:13px}'
            .'.mxs .mxs-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 20px;margin-top:26px}'
            .'.mxs .mxs-privacy,.mxs .mxs-empty{color:var(--mxs-muted);font-size:13px;margin:0}.mxs .mxs-empty{padding:22px;border-radius:20px;background:var(--mxs-soft);margin-top:4px}'
            .'.mxs .mxs-search{display:block;margin:0 0 16px;max-width:860px}.mxs .mxs-search input{width:100%;height:46px;padding:0 18px;border:1px solid var(--mxs-line);border-radius:14px;font:inherit;font-size:15px;background:#fff;color:var(--mxs-text)}'
            .'.mxs .mxs-badge{display:inline-flex;align-items:center;gap:10px;min-height:38px;padding:5px 14px 5px 6px;border:1px solid var(--mxs-line);border-radius:999px;background:#fff;color:var(--mxs-text);text-decoration:none;font-size:14px;font-weight:600;box-shadow:0 1px 2px rgba(20,20,20,.04)}'
            .'.mxs .mxs-g{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#fff;color:#4285f4;font-weight:800;border:1px solid var(--mxs-line)}'
            .'.mxs .mxs-stars{color:#f5a623;letter-spacing:1px;font-size:15px}.mxs .mxs-off{color:#d9d9d6}'
            .'.mxs .mxs-reply{font-size:14px;color:var(--mxs-muted);background:#fff;border-radius:12px;padding:10px 14px}.mxs .mxs-reply summary{cursor:pointer;font-weight:600;color:var(--mxs-text)}.mxs .mxs-reply p{margin:8px 0 0}';
        $js = 'var T='.$js_texts.';function k(t){return "mxs_q_"+t}'
            .'function clamp(){document.querySelectorAll(".mxs .mxs-clamp").forEach(function(a){var p=a.querySelector("p"),m=a.parentNode.querySelector("[data-mxs-more]");if(m&&p&&p.scrollHeight>p.clientHeight+2)m.hidden=false})}'
            .'function pend(s){var b=s.querySelector("[data-mxs-pending]");if(!b)return;var l=[];try{l=JSON.parse(localStorage.getItem(k(s.dataset.target))||"[]")}catch(e){}'
            .'l=l.filter(function(x){return Date.now()-x.t<2592e6});if(!l.length)return;b.hidden=false;b.innerHTML="";'
            .'l.forEach(function(x){var a=document.createElement("article");a.className="mxs-item";var h=document.createElement("h3");h.className="mxs-q";h.textContent=x.q;'
            .'var w=document.createElement("div");w.className="mxs-wait";w.textContent=T.pending;a.appendChild(h);a.appendChild(w);b.appendChild(a)})}'
            .'document.querySelectorAll(".mxs-qa").forEach(pend);clamp();'
            .'document.addEventListener("click",function(e){var o=e.target.closest("[data-mxs-open]");if(o){var f=document.getElementById(o.dataset.mxsOpen);f.hidden=!f.hidden;o.setAttribute("aria-expanded",!f.hidden);if(!f.hidden)f.querySelector("textarea").focus();return}'
            .'var m=e.target.closest("[data-mxs-more]");if(m){var a=m.parentNode.querySelector(".mxs-a");a.classList.toggle("mxs-clamp");m.textContent=a.classList.contains("mxs-clamp")?T.more:T.less;return}'
            .'var r=e.target.closest("[data-mxs-reviews-more]");if(r){var g=r.closest(".mxs-rev").querySelector("[data-mxs-reviews]"),n=+g.dataset.step||6,h=g.querySelectorAll("article[hidden]");'
            .'for(var i=0;i<h.length&&i<n;i++)h[i].hidden=false;if(h.length<=n)r.remove();clamp();return}'
            .'var v=e.target.closest("[data-mxs-vote] button");if(v){var d=v.closest("[data-mxs-vote]");try{localStorage.setItem("mxs_v_"+d.dataset.page+"_"+d.dataset.mxsVote,"1")}catch(x){}'
            .'fetch(T.vote,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({page:+d.dataset.page,item:d.dataset.mxsVote,yes:v.dataset.yes==="1"})}).catch(function(){});'
            .'d.innerHTML="";var t=document.createElement("span");t.className="mxs-thanks";t.textContent=T.thanks;d.appendChild(t)}});'
            .'document.querySelectorAll("[data-mxs-vote]").forEach(function(d){try{if(localStorage.getItem("mxs_v_"+d.dataset.page+"_"+d.dataset.mxsVote)){d.innerHTML="";var t=document.createElement("span");t.className="mxs-thanks";t.textContent=T.thanks;d.appendChild(t)}}catch(x){}});'
            .'document.querySelectorAll("[data-mxs-search]").forEach(function(i){i.addEventListener("input",function(){var q=i.value.toLocaleLowerCase("tr");'
            .'i.closest(".mxs").querySelectorAll("[data-mxs-item]").forEach(function(c){c.hidden=q!==""&&c.textContent.toLocaleLowerCase("tr").indexOf(q)<0})})});'
            .'document.addEventListener("submit",function(e){var f=e.target.closest("[data-mxs-form]");if(!f)return;e.preventDefault();var er=f.querySelector(".mxs-error"),b=f.querySelector("button[type=submit]"),d={};'
            .'new FormData(f).forEach(function(v,k){d[k]=v});d.consent=f.querySelector("[name=consent]").checked;b.disabled=true;er.hidden=true;'
            .'fetch(T.ask,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j}})}).then(function(x){b.disabled=false;'
            .'if(!x.ok){er.textContent=x.j&&x.j.message?x.j.message:"Gönderilemedi, tekrar deneyin.";er.hidden=false;return}'
            .'var s=f.closest(".mxs-qa");try{var l=JSON.parse(localStorage.getItem(k(s.dataset.target))||"[]");l.unshift({q:d.question,t:Date.now()});localStorage.setItem(k(s.dataset.target),JSON.stringify(l.slice(0,5)))}catch(z){}'
            .'f.innerHTML="";var p=document.createElement("p");p.className="mxs-wait";p.textContent=T.sent;f.appendChild(p);pend(s)})'
            .'.catch(function(){b.disabled=false;er.textContent="Bağlantı kurulamadı, tekrar deneyin.";er.hidden=false})});';

        return '<style id="moxdop-showcase-css">'.$css.'</style><script id="moxdop-showcase-js">(function(){function go(){'.$js.'}if(document.readyState!=="loading")go();else document.addEventListener("DOMContentLoaded",go)})();</script>';
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

    /** Plugin removal: the data MoxDOP sent and the question pages go with it. */
    public static function uninstall()
    {
        foreach ([self::QA_TYPE, self::QUESTION_TYPE] as $type) {
            foreach (get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]) as $id) {
                wp_delete_post($id, true);
            }
        }
        foreach ([self::TARGETS_OPTION, self::NOTIFY_OPTION, self::REVIEWS_OPTION, self::ENABLED_OPTION, self::TEXTS_OPTION, self::LOOK_OPTION] as $option) {
            delete_option($option);
        }
    }
}
