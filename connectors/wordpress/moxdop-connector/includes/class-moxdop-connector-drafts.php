<?php

defined('ABSPATH') || exit;

/**
 * Connector 1.5.0: rich drafts (ADR-064 drafts, ADR-076 language + translation link).
 *
 * A draft may carry slug, excerpt, a date, categories and tags (by name, created when missing), SEO title /
 * description / focus keyword (Yoast, Rank Math, SEOPress or the connector's own fields), a Polylang language and the
 * id of the post it translates. Without Polylang the language fields are ignored. Older MoxDOP payloads
 * (title, content_html, post_type, excerpt, reference) keep working unchanged.
 */
final class MoxDOP_Connector_Drafts
{
    const MAX_TERMS = 10;

    public static function polylang_active()
    {
        return function_exists('pll_set_post_language') && function_exists('pll_languages_list') && function_exists('pll_save_post_translations');
    }

    /** Scheduling a draft (status "future") is off until the site admin enables it. */
    public static function scheduling_allowed()
    {
        return (bool) apply_filters('moxdop_connector_allow_schedule', get_option('moxdop_connector_allow_schedule', '0') === '1');
    }

    /** @return array<int, array{slug: string, name: string, locale: string, default: bool, home_url: string}> */
    public static function languages()
    {
        if (! self::polylang_active()) {
            return [];
        }
        $default = function_exists('pll_default_language') ? (string) pll_default_language('slug') : '';
        $out = [];
        foreach ((array) pll_languages_list(['fields' => '']) as $language) {
            if (! is_object($language) || empty($language->slug)) {
                continue;
            }
            $out[] = [
                'slug' => (string) $language->slug,
                'name' => (string) ($language->name ?? $language->slug),
                'locale' => (string) ($language->locale ?? ''),
                'default' => (string) $language->slug === $default,
                'home_url' => function_exists('pll_home_url') ? (string) pll_home_url($language->slug) : home_url('/'),
            ];
        }

        return $out;
    }

    public static function valid_language($slug)
    {
        if (! self::polylang_active() || ! is_string($slug) || $slug === '') {
            return false;
        }

        return in_array($slug, (array) pll_languages_list(['fields' => 'slug']), true);
    }

    /**
     * Post fields for wp_insert_post from a draft payload (status is decided here: draft, or future when the
     * operator scheduled it and the site allows scheduling).
     *
     * @return array<string, mixed>
     */
    public static function post_fields(array $body)
    {
        $fields = [];
        $slug = sanitize_title((string) ($body['slug'] ?? ''));
        if ($slug !== '') {
            $fields['post_name'] = $slug;
        }
        $timestamp = isset($body['post_date']) && is_string($body['post_date']) && $body['post_date'] !== '' ? strtotime($body['post_date']) : false;
        $fields['post_status'] = 'draft';
        if ($timestamp !== false) {
            $fields['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
            $fields['post_date'] = get_date_from_gmt($fields['post_date_gmt']);
            $fields['edit_date'] = true;
            if (! empty($body['schedule']) && $timestamp > time() && self::scheduling_allowed()) {
                $fields['post_status'] = 'future';
            }
        }

        return $fields;
    }

    /**
     * Categories / tags, SEO meta, language and translation link of a created draft.
     *
     * @return array<string, mixed>
     */
    public static function decorate($post_id, $post_type, array $body)
    {
        $language = self::valid_language($body['language'] ?? null) ? (string) $body['language'] : '';
        $translation_of = absint($body['translation_of'] ?? 0);
        $out = ['language' => null, 'translations' => [], 'categories' => [], 'tags' => [], 'seo_provider' => null];

        if ($language !== '') {
            pll_set_post_language($post_id, $language);
            $out['language'] = $language;
            if ($translation_of > 0 && $translation_of !== (int) $post_id) {
                $out['translations'] = self::link_translation($post_id, $language, $translation_of);
            }
        }
        $source_language = $translation_of > 0 && self::polylang_active() && function_exists('pll_get_post_language') ? (string) pll_get_post_language($translation_of, 'slug') : '';

        foreach (['categories' => 'category', 'tags' => 'post_tag'] as $key => $taxonomy) {
            if (empty($body[$key]) || ! is_array($body[$key]) || ! is_object_in_taxonomy($post_type, $taxonomy)) {
                continue;
            }
            $ids = [];
            foreach (array_slice($body[$key], 0, self::MAX_TERMS) as $term) {
                $name = is_array($term) ? (string) ($term['name'] ?? '') : (string) $term;
                $source = is_array($term) ? (string) ($term['source'] ?? '') : '';
                $id = self::term_id($name, $taxonomy, $language, $source, $source_language);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
                wp_set_object_terms($post_id, array_values(array_unique($ids)), $taxonomy, false);
                $out[$key] = array_values(array_unique($ids));
            }
        }

        if (isset($body['seo']) && is_array($body['seo'])) {
            $out['seo_provider'] = self::seo_meta($post_id, $body['seo']);
        }

        return $out;
    }

    /**
     * Adds the draft to the translation group of $translation_of (existing links are kept).
     *
     * @return array<string, int>
     */
    public static function link_translation($post_id, $language, $translation_of)
    {
        $source = get_post($translation_of);
        if (! $source || ! function_exists('pll_get_post_language') || ! function_exists('pll_get_post_translations')) {
            return [];
        }
        $source_language = (string) pll_get_post_language($translation_of, 'slug');
        if ($source_language === '' || $source_language === $language) {
            return [];
        }
        $group = (array) pll_get_post_translations($translation_of);
        $group[$source_language] = (int) $translation_of;
        $group[$language] = (int) $post_id;
        pll_save_post_translations(array_map('intval', $group));

        return array_map('intval', (array) pll_get_post_translations($post_id));
    }

    /**
     * A term of the given name in the draft's language: an existing one, the translation of the named source term, or
     * a new term (linked to the source term as its translation).
     */
    public static function term_id($name, $taxonomy, $language, $source_name = '', $source_language = '')
    {
        $name = sanitize_text_field($name);
        if ($name === '' || mb_strlen($name) > 200) {
            return 0;
        }
        $args = ['taxonomy' => $taxonomy, 'name' => $name, 'hide_empty' => false, 'fields' => 'ids'];
        if (self::polylang_active()) {
            $args['lang'] = '';
        }
        $candidates = get_terms($args);
        $candidates = is_wp_error($candidates) ? [] : array_map('intval', (array) $candidates);
        if ($language === '' || ! function_exists('pll_get_term_language')) {
            if ($candidates !== []) {
                return (int) $candidates[0];
            }
            $created = wp_insert_term($name, $taxonomy);

            return is_wp_error($created) ? 0 : (int) $created['term_id'];
        }
        foreach ($candidates as $id) {
            if ((string) pll_get_term_language($id, 'slug') === $language) {
                return $id;
            }
        }
        $source_id = 0;
        if ($source_name !== '' && $source_language !== '' && $source_language !== $language) {
            $source_args = ['taxonomy' => $taxonomy, 'name' => sanitize_text_field($source_name), 'hide_empty' => false, 'fields' => 'ids', 'lang' => ''];
            $sources = get_terms($source_args);
            foreach (is_wp_error($sources) ? [] : (array) $sources as $id) {
                if ((string) pll_get_term_language((int) $id, 'slug') === $source_language) {
                    $source_id = (int) $id;
                    break;
                }
            }
            if ($source_id > 0 && function_exists('pll_get_term')) {
                $translated = (int) pll_get_term($source_id, $language);
                if ($translated > 0) {
                    return $translated;
                }
            }
        }
        foreach ($candidates as $id) {
            // A term with this name but no language yet is adopted into the draft's language.
            if ((string) pll_get_term_language($id, 'slug') === '') {
                pll_set_term_language($id, $language);

                return $id;
            }
        }
        $slug = sanitize_title($name);
        if (term_exists($slug, $taxonomy)) {
            $slug .= '-'.$language;
        }
        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        if (is_wp_error($created)) {
            return 0;
        }
        $term_id = (int) $created['term_id'];
        pll_set_term_language($term_id, $language);
        if ($source_id > 0 && function_exists('pll_save_term_translations') && function_exists('pll_get_term_translations')) {
            $group = (array) pll_get_term_translations($source_id);
            $group[$source_language] = $source_id;
            $group[$language] = $term_id;
            pll_save_term_translations(array_map('intval', $group));
        }

        return $term_id;
    }

    /** SEO title / description / focus keyword in the active SEO plugin's fields (the connector's own without one). */
    public static function seo_meta($post_id, array $seo)
    {
        $title = sanitize_text_field((string) ($seo['title'] ?? ''));
        $description = sanitize_textarea_field((string) ($seo['description'] ?? ''));
        $keyword = sanitize_text_field((string) ($seo['focus_keyword'] ?? ''));
        $keys = [];
        if (defined('WPSEO_VERSION')) {
            $keys['yoast'] = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw'];
        }
        if (defined('RANK_MATH_VERSION')) {
            $keys['rank_math'] = ['rank_math_title', 'rank_math_description', 'rank_math_focus_keyword'];
        }
        if (defined('SEOPRESS_VERSION')) {
            $keys['seopress'] = ['_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw'];
        }
        if ($keys === []) {
            $keys['moxdop'] = ['_moxdop_seo_title', '_moxdop_seo_description', '_moxdop_focus_keyword'];
        }
        foreach ($keys as $fields) {
            foreach ([$title, $description, $keyword] as $i => $value) {
                if ($value !== '') {
                    update_post_meta($post_id, $fields[$i], $value);
                }
            }
        }

        return implode(',', array_keys($keys));
    }
}
