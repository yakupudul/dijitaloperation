<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * 1.38.0 (yakup 2026-11-18): SEOPress gets the same ready setup as Rank Math and Yoast (apply_setup name "seopress"),
 * and the site map index fix first written by hand in the burcukisa.com theme.
 *
 * The setup is careful with sites already running SEOPress: title templates and the separator are only filled where
 * they are empty (a site's own choices stay), organisation schema, site maps, attachment redirects and archives are
 * set, and SEOPress Pro's "404 → home page" redirect is switched off (Google reads it as a soft 404). It never writes
 * a noindex.
 *
 * Pure functions (no WordPress) are kept here so they can be tested without a site.
 */
class MoxDOP_Connector_Seopress
{
    const FREE_PLUGIN = 'wp-seopress/seopress.php';

    const DEFAULT_TITLE = '%%post_title%% %%sep%% %%sitetitle%%';

    const DEFAULT_DESCRIPTION = '%%post_excerpt%%';

    public static function register()
    {
        add_filter('seopress_sitemaps_xml_index', [self::class, 'sitemap_index_filter'], 20);
    }

    /**
     * Option values of the SEOPress setup.
     *
     * @param  array<string, mixed>  $current  stored options by name (seopress_titles_option_name, ...)
     * @param  array{name: string, logo: string, type: string, business: string, pro: bool, post_types: string[]}  $site
     * @return array<string, array<string, mixed>>
     */
    public static function options(array $current, array $site)
    {
        $titles = is_array($current['seopress_titles_option_name'] ?? null) ? $current['seopress_titles_option_name'] : [];
        $sitemap = is_array($current['seopress_xml_sitemap_option_name'] ?? null) ? $current['seopress_xml_sitemap_option_name'] : [];

        $title_values = ['seopress_titles_archives_author_disable' => '1', 'seopress_titles_archives_date_disable' => '1'];
        if (self::blank($titles['seopress_titles_sep'] ?? '')) {
            $title_values['seopress_titles_sep'] = '-';
        }
        if (self::blank($titles['seopress_titles_home_site_title'] ?? '')) {
            $title_values['seopress_titles_home_site_title'] = '%%sitetitle%%';
        }
        $singles = [];
        foreach ($site['post_types'] as $type) {
            $own = is_array($titles['seopress_titles_single_titles'][$type] ?? null) ? $titles['seopress_titles_single_titles'][$type] : [];
            $fill = [];
            if (self::blank($own['title'] ?? '')) {
                $fill['title'] = self::DEFAULT_TITLE;
            }
            if (self::blank($own['description'] ?? '')) {
                $fill['description'] = self::DEFAULT_DESCRIPTION;
            }
            if ($fill !== []) {
                $singles[$type] = $fill;
            }
        }
        if ($singles !== []) {
            $title_values['seopress_titles_single_titles'] = $singles;
        }

        $sitemap_values = ['seopress_xml_sitemap_general_enable' => '1', 'seopress_xml_sitemap_img_enable' => '1', 'seopress_xml_sitemap_author_enable' => ''];
        if (empty($sitemap['seopress_xml_sitemap_post_types_list'])) {
            $sitemap_values['seopress_xml_sitemap_post_types_list'] = array_fill_keys($site['post_types'], ['include' => '1']);
        }

        $social = ['seopress_social_knowledge_type' => $site['type'] === 'person' ? 'Person' : 'Organization', 'seopress_social_knowledge_name' => $site['name']];
        if ($site['logo'] !== '') {
            $social['seopress_social_knowledge_img'] = $site['logo'];
        }

        $options = [
            'seopress_toggle' => ['toggle-titles' => '1', 'toggle-xml-sitemap' => '1', 'toggle-social' => '1', 'toggle-advanced' => '1'],
            'seopress_titles_option_name' => $title_values,
            'seopress_social_option_name' => array_filter($social, static function ($value) {
                return $value !== '';
            }),
            'seopress_xml_sitemap_option_name' => $sitemap_values,
            'seopress_advanced_option_name' => ['seopress_advanced_advanced_attachments' => '1', 'seopress_advanced_advanced_replytocom' => '1'],
        ];
        if ($site['pro']) {
            $options['seopress_pro_option_name'] = array_filter(['seopress_404_redirect_home' => 'none', 'seopress_local_business_type' => $site['business']], static function ($value) {
                return $value !== '';
            });
        }

        return $options;
    }

    /** What the setup does, in one line (shown by setup_guide and apply_setup). */
    public static function does($pro)
    {
        return 'SEOPress: title separator and title / description templates filled only where empty (the site\'s own stay), organisation schema (name, logo, type), author and date archives off, attachment pages redirected to their post, XML site map with images on (post types listed when none are), ?replytocom links removed'
            .($pro ? ', SEOPress Pro "404 → home page" redirect off (a soft 404 for Google), local business type when given' : '')
            .'. Never writes a noindex. The site map index dates are corrected by the connector itself.';
    }

    /**
     * SEOPress caches the index dates for 12 hours and reads only post_modified, so after a bulk rewrite or a
     * scheduled post the index lags. A single-page child map (…/<type>-sitemap1.xml) gets the newest publish or
     * edit date of that post type.
     *
     * @param  callable(string): ?string  $newest  post type => MySQL date or null
     */
    public static function sitemap_index($xml, callable $newest)
    {
        $out = preg_replace_callback('~(<loc>[^<]*/([a-z0-9_-]+)-sitemap1[.]xml</loc>\s*)<lastmod>[^<]*</lastmod>~', static function ($m) use ($newest) {
            $date = $newest($m[2]);
            if (! is_string($date) || $date === '' || strtotime($date) === false) {
                return $m[0];
            }

            return $m[1].'<lastmod>'.gmdate('c', strtotime($date.' UTC') ?: strtotime($date)).'</lastmod>';
        }, (string) $xml);

        return is_string($out) ? $out : $xml;
    }

    public static function sitemap_index_filter($xml)
    {
        global $wpdb;

        return self::sitemap_index($xml, static function ($type) use ($wpdb) {
            if (! post_type_exists($type)) {
                return null;
            }
            $date = $wpdb->get_var($wpdb->prepare("SELECT MAX(GREATEST(post_modified_gmt, post_date_gmt)) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $type));

            return is_string($date) && strpos($date, '0000') !== 0 ? $date : null;
        });
    }

    private static function blank($value)
    {
        return ! is_scalar($value) || trim((string) $value) === '';
    }
}
