<?php

use Elementor\Plugin;

defined('ABSPATH') || exit;

/**
 * Connector 1.7.0: the content of published pages, rendered without the theme, so MoxDOP reads a whole site in a few
 * requests instead of loading every page over HTTP. Read-only; nothing is written or cached.
 *
 * Each record is a small HTML document: the SEO title and description, canonical, language and the post content
 * rendered the way the page builder renders it (Elementor builder content, otherwise the `the_content` filters, which
 * also run Gutenberg blocks and WPBakery / Divi shortcodes). The theme's header, menu and footer are not included.
 */
final class MoxDOP_Connector_Content_Export
{
    /** Most posts asked in one request. */
    const MAX_IDS = 50;

    /** Seconds one request may spend rendering; the rest comes back as pending_ids. */
    const TIME_BUDGET = 20;

    /** Response budget: compressed + base64 HTML of one request. */
    const MAX_RESPONSE_BYTES = 4194304;

    /**
     * @param  list<int>  $ids
     * @return array{records: list<array<string, mixed>>, pending_ids: list<int>}
     */
    public static function export(array $ids)
    {
        $started = microtime(true);
        $budget = self::MAX_RESPONSE_BYTES;
        $records = [];
        $pending = [];
        self::prepare_builders();
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_IDS) as $index => $id) {
            if ($index > 0 && microtime(true) - $started > self::TIME_BUDGET) {
                $pending[] = $id;

                continue;
            }
            $post = $id > 0 ? get_post($id) : null;
            if (! $post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_password !== '' || ! is_post_type_viewable($post->post_type)) {
                $records[] = ['id' => $id, 'status' => 'not_public'];

                continue;
            }
            [$content, $builder] = self::render($post);
            $html = self::document($post, $content, $builder);
            $encoded = base64_encode((string) gzencode($html, 6));
            if (strlen($encoded) > $budget) {
                if ($records === [] || strlen($encoded) > self::MAX_RESPONSE_BYTES) {
                    $records[] = ['id' => $id, 'status' => 'skipped_size', 'url' => get_permalink($post)];
                } else {
                    $pending[] = $id;
                }

                continue;
            }
            $budget -= strlen($encoded);
            $modified = get_post_datetime($post, 'modified', 'gmt');
            $records[] = [
                'id' => $id,
                'status' => 'content',
                'url' => get_permalink($post),
                'type' => $post->post_type,
                'builder' => $builder,
                'modified_at' => $modified ? $modified->format('c') : null,
                'sha256' => hash('sha256', $html),
                'bytes' => strlen($html),
                'html_gz_b64' => $encoded,
            ];
        }

        return ['records' => $records, 'pending_ids' => $pending];
    }

    /** WPBakery registers its shortcodes only for front-end requests; a REST request needs them too. */
    private static function prepare_builders()
    {
        if (class_exists('WPBMap') && method_exists('WPBMap', 'addAllMappedShortcodes')) {
            WPBMap::addAllMappedShortcodes();
        }
    }

    /** @return string elementor, wpbakery, divi, gutenberg or classic */
    public static function builder(WP_Post $post)
    {
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            return 'elementor';
        }
        $content = (string) $post->post_content;
        if (strpos($content, '[vc_') !== false) {
            return 'wpbakery';
        }
        if (strpos($content, '[et_pb_') !== false) {
            return 'divi';
        }

        return strpos($content, '<!-- wp:') !== false ? 'gutenberg' : 'classic';
    }

    /** @return array{0: string, 1: string} rendered content and builder */
    private static function render(WP_Post $post)
    {
        $builder = self::builder($post);
        if ($builder === 'elementor' && class_exists('\Elementor\Plugin')) {
            try {
                $html = Plugin::instance()->frontend->get_builder_content_for_display($post->ID, false);
                if (is_string($html) && trim($html) !== '') {
                    return [$html, $builder];
                }
            } catch (Throwable $error) {
                // Falls back to the content filters below.
            }
        }
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        try {
            $html = (string) apply_filters('the_content', $post->post_content);
        } catch (Throwable $error) {
            $html = function_exists('do_blocks') ? do_blocks($post->post_content) : (string) $post->post_content;
        }
        $GLOBALS['post'] = $previous;
        wp_reset_postdata();
        // Shortcodes of a builder that is no longer active stay as [tags]; their text is kept, the tags are dropped.
        $html = (string) preg_replace('/\[\/?(?:vc_|et_pb_|fusion_|av_)[^\]]*\]/i', '', $html);

        return [$html, $builder];
    }

    /** The page as a minimal HTML document: what search engines read as its title, description and content. */
    private static function document(WP_Post $post, $content, $builder)
    {
        $title = (string) get_the_title($post);
        $seo = MoxDOP_Connector_REST_Controller::seo_fields($post->ID);
        $seo_title = is_string($seo['seo_title']) && $seo['seo_title'] !== '' && strpos($seo['seo_title'], '%') === false ? $seo['seo_title'] : $title;
        $description = is_string($seo['meta_description']) && strpos($seo['meta_description'], '%') === false ? $seo['meta_description'] : '';
        $language = function_exists('pll_get_post_language') ? pll_get_post_language($post->ID, 'slug') : null;
        $language = $language ?: get_bloginfo('language');
        $canonical = is_string($seo['canonical_url']) && $seo['canonical_url'] !== '' ? $seo['canonical_url'] : get_permalink($post);
        $robots = strtolower((string) $seo['robots']);
        $noindex = ($seo['seo_provider'] === 'yoast' && $robots === '1')
            || ($seo['seo_provider'] === 'rank_math' && strpos($robots, 'noindex') !== false)
            || ($seo['seo_provider'] === 'seopress' && $robots === 'yes');
        // Themes print the title as the H1 of posts and normal pages; builder pages carry their own H1 in the content.
        $heading = $builder === 'elementor' || stripos($content, '<h1') !== false ? '' : '<h1>'.esc_html($title).'</h1>';

        return '<!DOCTYPE html><html lang="'.esc_attr($language).'"><head><meta charset="utf-8">'
            .'<title>'.esc_html(wp_strip_all_tags($seo_title)).'</title>'
            .($description !== '' ? '<meta name="description" content="'.esc_attr(wp_strip_all_tags($description)).'">' : '')
            .($noindex ? '<meta name="robots" content="noindex">' : '')
            .'<link rel="canonical" href="'.esc_url($canonical).'">'
            .'<meta name="generator" content="MoxDOP content export '.esc_attr(MOXDOP_CONNECTOR_VERSION).'">'
            .'</head><body><main>'.$heading.$content.'</main></body></html>';
    }
}
