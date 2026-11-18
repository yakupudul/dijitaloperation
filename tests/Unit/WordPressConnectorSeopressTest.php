<?php

namespace Tests\Unit;

use MoxDOP_Connector_Seopress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Connector 1.38.0 (yakup 2026-11-18): SEOPress ready setup at the level of Rank Math / Yoast, and the site map
 * index date fix first written in the burcukisa.com theme. Pure functions, no WordPress.
 */
final class WordPressConnectorSeopressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir().'/moxdop-wp-stub/');
        }
        require_once dirname(__DIR__, 2).'/connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-seopress.php';
    }

    private function site(array $overrides = []): array
    {
        return array_merge(['name' => 'Prof. Dr. Burcu Kısa', 'logo' => '', 'type' => 'company', 'business' => '', 'pro' => true, 'post_types' => ['post', 'page', 'docs']], $overrides);
    }

    #[Test]
    public function an_empty_seopress_gets_the_full_setup(): void
    {
        $options = MoxDOP_Connector_Seopress::options([], $this->site(['logo' => 'https://x.test/logo.png', 'business' => 'Physician']));

        $titles = $options['seopress_titles_option_name'];
        $this->assertSame('-', $titles['seopress_titles_sep']);
        $this->assertSame('%%post_title%% %%sep%% %%sitetitle%%', $titles['seopress_titles_single_titles']['page']['title']);
        $this->assertSame('1', $titles['seopress_titles_archives_author_disable']);
        $this->assertSame(['post', 'page', 'docs'], array_keys($options['seopress_xml_sitemap_option_name']['seopress_xml_sitemap_post_types_list']));
        $this->assertSame(['seopress_social_knowledge_type' => 'Organization', 'seopress_social_knowledge_name' => 'Prof. Dr. Burcu Kısa', 'seopress_social_knowledge_img' => 'https://x.test/logo.png'], $options['seopress_social_option_name']);
        $this->assertSame('1', $options['seopress_advanced_option_name']['seopress_advanced_advanced_attachments']);
        $this->assertSame(['seopress_404_redirect_home' => 'none', 'seopress_local_business_type' => 'Physician'], $options['seopress_pro_option_name']);
        $this->assertStringNotContainsString('noindex', json_encode($options), 'the setup never writes a noindex');
    }

    #[Test]
    public function a_site_s_own_templates_and_separator_stay(): void
    {
        $current = ['seopress_titles_option_name' => ['seopress_titles_sep' => '|', 'seopress_titles_home_site_title' => '%%sitetitle%%',
            'seopress_titles_single_titles' => ['post' => ['title' => '%%post_title%% | Burcu', 'description' => '%%post_excerpt%%'], 'docs' => ['title' => '%%post_title%%', 'description' => '']]],
            'seopress_xml_sitemap_option_name' => ['seopress_xml_sitemap_post_types_list' => ['post' => ['include' => '1']]]];
        $options = MoxDOP_Connector_Seopress::options($current, $this->site(['pro' => false, 'type' => 'person']));

        $titles = $options['seopress_titles_option_name'];
        $this->assertArrayNotHasKey('seopress_titles_sep', $titles);
        $this->assertArrayNotHasKey('seopress_titles_home_site_title', $titles);
        $this->assertArrayNotHasKey('post', $titles['seopress_titles_single_titles']);
        $this->assertSame(['description' => '%%post_excerpt%%'], $titles['seopress_titles_single_titles']['docs'], 'only the empty field is filled');
        $this->assertArrayHasKey('page', $titles['seopress_titles_single_titles']);
        $this->assertArrayNotHasKey('seopress_xml_sitemap_post_types_list', $options['seopress_xml_sitemap_option_name'], 'a chosen site map list stays');
        $this->assertSame('Person', $options['seopress_social_option_name']['seopress_social_knowledge_type']);
        $this->assertArrayNotHasKey('seopress_pro_option_name', $options, 'no Pro values without SEOPress Pro');
    }

    #[Test]
    public function the_site_map_index_shows_the_newest_publish_or_edit_date(): void
    {
        $xml = '<sitemapindex><sitemap><loc>https://x.test/page-sitemap1.xml</loc><lastmod>2026-11-03T10:00:00+00:00</lastmod></sitemap>'
            .'<sitemap><loc>https://x.test/post-sitemap2.xml</loc><lastmod>2026-11-01T10:00:00+00:00</lastmod></sitemap>'
            .'<sitemap><loc>https://x.test/docs-sitemap1.xml</loc><lastmod>2026-09-19T10:00:00+00:00</lastmod></sitemap></sitemapindex>';
        $out = MoxDOP_Connector_Seopress::sitemap_index($xml, static function ($type) {
            return $type === 'page' ? '2026-11-14 08:30:00' : null;
        });

        $this->assertStringContainsString('page-sitemap1.xml</loc><lastmod>2026-11-14T08:30:00+00:00</lastmod>', $out);
        $this->assertStringContainsString('post-sitemap2.xml</loc><lastmod>2026-11-01T10:00:00+00:00</lastmod>', $out, 'multi-page maps stay');
        $this->assertStringContainsString('docs-sitemap1.xml</loc><lastmod>2026-09-19T10:00:00+00:00</lastmod>', $out, 'no date found: unchanged');
    }
}
