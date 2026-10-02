<?php

namespace Tests\Feature\Website;

use MoxDop\Website\Standards\PageSignalExtractor;
use MoxDop\Website\Standards\RobotsTxtRules;
use MoxDop\Website\Standards\UrlStandardEvaluator;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use MoxDop\Website\Standards\WebsiteStandardEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Faz 6: every url_* standard gives pass / fail (or review) / not_applicable from stored facts; missing ≠ fail. */
final class UrlStandardEvaluatorTest extends TestCase
{
    private const string HOST = 'klinik.test';

    public function test_all_new_standards_are_in_the_catalog_and_skipped_by_the_stored_assessment(): void
    {
        $catalog = (new WebsiteStandardCatalog)->definitions();
        $ids = array_keys(array_filter($catalog, fn (array $s): bool => str_starts_with($s['method'], 'url_')));
        $this->assertCount(37, $ids);
        foreach ($ids as $id) {
            $standard = $catalog[$id];
            $this->assertContains($standard['applies_to'], ['page', 'site'], $id);
            $this->assertArrayHasKey($standard['group'], WebsiteStandardCatalog::GROUPS, $id);
            $this->assertContains($standard['severity'], ['low', 'medium', 'high'], $id);
            $this->assertNotSame('', $standard['title']);
            $this->assertNotSame('', $standard['action']);
            $this->assertSame('not_applicable', (new WebsiteStandardEvaluator)->evaluate($standard, ['url' => 'https://x.test/'])['state'], $id);
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}> */
    public static function pageCases(): array
    {
        $post = ['kind' => 'post', 'path' => '/blog/implant-sonrasi/', 'cms_type' => 'post'];
        $service = ['kind' => 'service', 'path' => '/tedavilerimiz/implant/'];

        return [
            'author pass' => ['eeat_author', $service + ['signals' => self::signals(['author' => true])], ['ymyl' => true], 'pass'],
            'author fail' => ['eeat_author', $service + ['signals' => self::signals()], ['ymyl' => true], 'fail'],
            'author n/a not ymyl' => ['eeat_author', $service + ['signals' => self::signals()], ['ymyl' => false], 'not_applicable'],
            'author unknown html' => ['eeat_author', $service, ['ymyl' => true], 'unknown'],
            'review pass' => ['eeat_medical_review', $post + ['signals' => self::signals(['medical_review' => true])], ['health' => true], 'pass'],
            'review fail' => ['eeat_medical_review', $post + ['signals' => self::signals()], ['health' => true], 'review'],
            'review n/a' => ['eeat_medical_review', $post + ['signals' => self::signals()], ['health' => false], 'not_applicable'],
            'date pass' => ['eeat_updated_date', $post + ['signals' => self::signals(['visible_date' => true])], ['ymyl' => true], 'pass'],
            'date fail' => ['eeat_updated_date', $post + ['signals' => self::signals()], ['ymyl' => true], 'review'],
            'date n/a page' => ['eeat_updated_date', $service + ['signals' => self::signals()], ['ymyl' => true], 'not_applicable'],
            'procedure pass' => ['medical_procedure_schema', $service + ['signals' => self::signals(['jsonld_types' => ['MedicalProcedure']])], ['health' => true], 'pass'],
            'procedure fail' => ['medical_procedure_schema', $service + ['signals' => self::signals()], ['health' => true], 'review'],
            'procedure n/a' => ['medical_procedure_schema', $service + ['signals' => self::signals()], ['health' => false], 'not_applicable'],
            'faq pass' => ['faq_schema', ['signals' => self::signals(['faq_content' => true, 'jsonld_types' => ['FAQPage']])], [], 'pass'],
            // FAQ markup is optional since Google dropped FAQ rich results (2026-05-07): information, never a defect.
            'faq info' => ['faq_schema', ['signals' => self::signals(['faq_content' => true, 'question_count' => 4])], [], 'info'],
            'faq n/a' => ['faq_schema', ['signals' => self::signals()], [], 'not_applicable'],
            'article pass' => ['article_schema', $post + ['signals' => self::signals(['jsonld_types' => ['BlogPosting'], 'date_modified' => true])], [], 'pass'],
            'article fail' => ['article_schema', $post + ['signals' => self::signals(['jsonld_types' => ['BlogPosting']])], [], 'fail'],
            'article n/a' => ['article_schema', $service + ['signals' => self::signals()], [], 'not_applicable'],
            'breadcrumb pass' => ['breadcrumb_schema', $service + ['signals' => self::signals(['jsonld_types' => ['BreadcrumbList']])], [], 'pass'],
            'breadcrumb fail' => ['breadcrumb_schema', $service + ['signals' => self::signals()], [], 'review'],
            'breadcrumb n/a' => ['breadcrumb_schema', ['path' => '/implant/', 'signals' => self::signals()], [], 'not_applicable'],
            'orphan pass' => ['orphan_page', ['inlinks' => 2], ['link_graph' => true], 'pass'],
            'orphan fail' => ['orphan_page', ['inlinks' => 0], ['link_graph' => true], 'fail'],
            'orphan n/a' => ['orphan_page', ['inlinks' => null], ['link_graph' => false], 'not_applicable'],
            'service links pass' => ['service_inlinks', ['is_priority_service' => true, 'inlinks' => 5], ['link_graph' => true], 'pass'],
            'service links fail' => ['service_inlinks', ['is_priority_service' => true, 'inlinks' => 1], ['link_graph' => true], 'review'],
            'service links n/a' => ['service_inlinks', ['inlinks' => 1], ['link_graph' => true], 'not_applicable'],
            'decay pass' => ['content_decay', $post + ['modified_at' => '2026-08-01', 'clicks' => 5, 'clicks_prev' => 40], [], 'pass'],
            'decay fail' => ['content_decay', $post + ['modified_at' => '2024-01-01', 'clicks' => 5, 'clicks_prev' => 40], [], 'review'],
            'decay n/a' => ['content_decay', $post + ['modified_at' => null, 'clicks' => 5, 'clicks_prev' => 40], [], 'not_applicable'],
            'thin pass' => ['thin_content', ['word_count' => 800], [], 'pass'],
            'thin fail' => ['thin_content', ['word_count' => 120], [], 'review'],
            'thin n/a home' => ['thin_content', ['kind' => 'home', 'word_count' => 50], [], 'not_applicable'],
            'thin unknown' => ['thin_content', ['word_count' => null], [], 'unknown'],
            'sitemap pass' => ['sitemap_hygiene', ['in_sitemap' => true], ['sitemap_known' => true], 'pass'],
            'sitemap fail redirect' => ['sitemap_hygiene', ['in_sitemap' => true, 'status_code' => 301, 'indexable' => false], ['sitemap_known' => true], 'fail'],
            'sitemap fail canonical' => ['sitemap_hygiene', ['in_sitemap' => true, 'canonical_key' => self::HOST.'/baska', 'indexable' => false], ['sitemap_known' => true], 'fail'],
            'sitemap n/a' => ['sitemap_hygiene', ['in_sitemap' => null], ['sitemap_known' => false], 'not_applicable'],
            'missing pass' => ['sitemap_missing', ['in_sitemap' => true], ['sitemap_known' => true], 'pass'],
            'missing fail' => ['sitemap_missing', ['in_sitemap' => false], ['sitemap_known' => true], 'review'],
            'missing n/a' => ['sitemap_missing', ['in_sitemap' => false, 'indexable' => false], ['sitemap_known' => true], 'not_applicable'],
            'index pass' => ['index_coverage', ['inspection' => ['verdict' => 'PASS']], [], 'pass'],
            'index fail' => ['index_coverage', ['is_service' => true, 'inspection' => ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']], [], 'fail'],
            'index n/a no data' => ['index_coverage', ['is_service' => true], [], 'not_applicable'],
            'index n/a no value' => ['index_coverage', ['inspection' => ['verdict' => 'NEUTRAL']], [], 'not_applicable'],
            'cannibal fail' => ['cannibalization', ['cannibal' => ['subject' => 'implant', 'keeper' => self::HOST.'/implant', 'share' => 0.4]], ['cannibalization_known' => true], 'fail'],
            'cannibal keeper pass' => ['cannibalization', ['cannibal' => ['subject' => 'implant', 'keeper' => self::HOST.'/sayfa', 'share' => 0.6]], ['cannibalization_known' => true], 'pass'],
            'cannibal pass' => ['cannibalization', [], ['cannibalization_known' => true], 'pass'],
            'cannibal n/a' => ['cannibalization', [], ['cannibalization_known' => false], 'not_applicable'],
            'snippet pass' => ['snippet_controls', ['signals' => self::signals(['main_words' => 200])], [], 'pass'],
            'snippet fail meta' => ['snippet_controls', ['signals' => self::signals(['nosnippet_meta' => true])], [], 'fail'],
            'snippet fail robots' => ['snippet_controls', ['robots' => 'index, max-snippet:0', 'signals' => self::signals()], [], 'fail'],
            'snippet fail data-nosnippet' => ['snippet_controls', ['signals' => self::signals(['main_words' => 100, 'nosnippet_words' => 80])], [], 'fail'],
            'snippet n/a noindex' => ['snippet_controls', ['indexable' => false, 'signals' => self::signals(['nosnippet_meta' => true])], [], 'not_applicable'],
            'snippet unknown' => ['snippet_controls', [], [], 'unknown'],
            'raw html pass' => ['main_content_raw_html', $service + ['signals' => self::signals(['main_words' => 400])], [], 'pass'],
            'raw html fail js' => ['main_content_raw_html', $service + ['signals' => self::signals(['main_words' => 4, 'spa_root' => true])], [], 'fail'],
            'raw html n/a short' => ['main_content_raw_html', $service + ['signals' => self::signals(['main_words' => 4])], [], 'not_applicable'],
            'raw html unknown' => ['main_content_raw_html', $service, [], 'unknown'],
            'service depth pass' => ['service_content_depth', $service + ['signals' => self::signals(['headings' => ['İmplant tedavi süreci', 'Kaç seans sürer?', 'Kimler için uygun?', 'Riskler ve yan etkiler', 'Tedavi sonrası bakım']])], ['health' => true], 'pass'],
            'service depth review' => ['service_content_depth', $service + ['signals' => self::signals(['headings' => ['İmplant nedir?', 'Fiyat']])], ['health' => true], 'review'],
            'service depth pass not health' => ['service_content_depth', $service + ['signals' => self::signals(['headings' => []])], ['health' => false], 'pass'],
            'service depth n/a' => ['service_content_depth', $post + ['signals' => self::signals()], ['health' => true], 'not_applicable'],
            'service depth unknown' => ['service_content_depth', $service, ['health' => true], 'unknown'],
            'media pass' => ['original_media', $service + ['signals' => self::signals(['images' => ['total' => 3, 'with_alt' => 2, 'original' => 2]])], [], 'pass'],
            'media review' => ['original_media', $service + ['signals' => self::signals(['images' => ['total' => 3, 'with_alt' => 3, 'original' => 0]])], [], 'review'],
            'media n/a' => ['original_media', $post + ['signals' => self::signals()], [], 'not_applicable'],
            'schema match pass' => ['schema_visible_match', ['signals' => self::signals(['organizations' => [['phone_visible' => true, 'name_visible' => true, 'postal_visible' => null]]])], [], 'pass'],
            'schema match review' => ['schema_visible_match', ['signals' => self::signals(['organizations' => [['phone_visible' => false, 'name_visible' => true, 'postal_visible' => null]]])], [], 'review'],
            'schema match n/a' => ['schema_visible_match', ['signals' => self::signals()], [], 'not_applicable'],
            'self stars review' => ['self_serving_review_markup', ['signals' => self::signals(['organizations' => [['self_rating' => true]]])], [], 'review'],
            'self stars pass' => ['self_serving_review_markup', ['signals' => self::signals(['organizations' => [['self_rating' => false]]])], [], 'pass'],
            'self stars n/a' => ['self_serving_review_markup', ['signals' => self::signals()], [], 'not_applicable'],
            'promotion review' => ['tr_health_promotion', $service + ['signals' => self::signals(['promotion_hits' => [['label' => 'Kampanya / indirim / hediye', 'matched' => 'indirim']]])], ['health' => true], 'review'],
            'promotion pass' => ['tr_health_promotion', $service + ['signals' => self::signals(['promotion_hits' => []])], ['health' => true], 'pass'],
            'promotion n/a' => ['tr_health_promotion', $service + ['signals' => self::signals(['promotion_hits' => []])], ['health' => false], 'not_applicable'],
            'promotion unknown' => ['tr_health_promotion', $service + ['signals' => self::signals()], ['health' => true], 'unknown'],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $site
     */
    #[DataProvider('pageCases')]
    public function test_page_standard(string $method, array $page, array $site, string $expected): void
    {
        $record = self::record($page);
        $result = $this->evaluate([$record['key'] => $record], $site);

        $check = $result['pages'][$record['key']]['website:url:'.$method];
        $this->assertSame($expected, $check['state'], $check['finding']);
        if (in_array($expected, ['fail', 'review'], true)) {
            $this->assertNotEmpty($check['solution']);
        }
    }

    public function test_doorway_group_flags_location_variants_keeps_the_page_with_clicks_and_ignores_distinct_services(): void
    {
        $pages = [];
        foreach (['/ankara-implant-merkezi/' => 40, '/ankara-implant-klinigi/' => 3, '/implant-yapan-yerler/' => 0, '/ankara-dis-implanti/' => 0, '/zirkonyum-kaplama/' => 0, '/dis-beyazlatma/' => 0, '/kanal-tedavisi/' => 0] as $path => $clicks) {
            $record = self::record(['path' => $path, 'clicks' => $clicks]);
            $pages[$record['key']] = $record;
        }
        $result = $this->evaluate($pages, ['locations' => ['ankara', 'cankaya']]);

        $check = fn (string $path): array => $result['pages'][self::HOST.rtrim($path, '/')]['website:url:doorway_group'];
        $this->assertSame('pass', $check('/ankara-implant-merkezi/')['state'], 'the page with Google clicks is kept');
        foreach (['/ankara-implant-klinigi/', '/implant-yapan-yerler/', '/ankara-dis-implanti/'] as $path) {
            $this->assertSame('fail', $check($path)['state'], $path);
            $this->assertStringContainsString('/ankara-implant-merkezi/', $check($path)['solution']);
        }
        foreach (['/zirkonyum-kaplama/', '/dis-beyazlatma/', '/kanal-tedavisi/'] as $path) {
            $this->assertSame('not_applicable', $check($path)['state'], $path);
        }
        $this->assertCount(1, array_filter($result['groups'], fn (array $g): bool => $g['kind'] === 'slug'));
    }

    public function test_near_duplicate_titles_are_flagged_distinct_titles_pass(): void
    {
        $pages = [];
        foreach (['/a/' => 'Ankara İmplant Tedavisi Fiyatları | Klinik', '/b/' => 'Çankaya İmplant Tedavisi Fiyatları | Klinik', '/c/' => 'Zirkonyum Kaplama Nedir? | Klinik'] as $path => $title) {
            $record = self::record(['path' => $path, 'title' => $title]);
            $pages[$record['key']] = $record;
        }
        $result = $this->evaluate($pages, ['locations' => ['ankara', 'cankaya']]);

        $this->assertSame('fail', $result['pages'][self::HOST.'/a']['website:url:near_duplicate_title']['state']);
        $this->assertSame('fail', $result['pages'][self::HOST.'/b']['website:url:near_duplicate_title']['state']);
        $this->assertSame('pass', $result['pages'][self::HOST.'/c']['website:url:near_duplicate_title']['state']);
    }

    public function test_hreflang_polylang_consistency(): void
    {
        $tr = self::record(['path' => '/implant/', 'canonical_key' => self::HOST.'/implant', 'wp_language' => 'tr', 'signals' => self::signals(['lang' => 'tr-TR', 'hreflang' => [
            ['language' => 'tr', 'url' => 'https://'.self::HOST.'/implant/'], ['language' => 'en', 'url' => 'https://'.self::HOST.'/en/implant/'], ['language' => 'x-default', 'url' => 'https://'.self::HOST.'/implant/'],
        ]])]);
        $en = self::record(['path' => '/en/implant/', 'canonical_key' => self::HOST.'/implant', 'signals' => self::signals(['lang' => 'tr', 'hreflang' => [
            ['language' => 'en', 'url' => 'https://'.self::HOST.'/en/implant/'],
        ]])]);
        $single = self::record(['path' => '/iletisim/', 'signals' => self::signals()]);
        $result = $this->evaluate([$tr['key'] => $tr, $en['key'] => $en, $single['key'] => $single], []);

        $this->assertStringContainsString('eri bağlantı vermeyen çeviri: /en/implant/', $result['pages'][$tr['key']]['website:url:hreflang_consistency']['finding']);
        $bad = $result['pages'][$en['key']]['website:url:hreflang_consistency'];
        $this->assertSame('fail', $bad['state']);
        $this->assertStringContainsString('Canonical başka adresi', $bad['finding']);
        $this->assertStringContainsString('<html lang="tr">', $bad['finding']);
        $this->assertSame('not_applicable', $result['pages'][$single['key']]['website:url:hreflang_consistency']['state']);
        // With the return link the TR page passes; without x-default it does not.
        $en['signals']['hreflang'][] = ['language' => 'tr', 'url' => 'https://'.self::HOST.'/implant/'];
        $this->assertSame('pass', $this->evaluate([$tr['key'] => $tr, $en['key'] => $en], [])['pages'][$tr['key']]['website:url:hreflang_consistency']['state']);
        // x-default is optional: Polylang writes it on the home page only, so an inner page without it still passes.
        $tr['signals']['hreflang'] = [['language' => 'tr', 'url' => 'https://'.self::HOST.'/implant/'], ['language' => 'en', 'url' => 'https://'.self::HOST.'/en/implant/']];
        $again = $this->evaluate([$tr['key'] => $tr, $en['key'] => $en], []);
        $this->assertSame('pass', $again['pages'][$tr['key']]['website:url:hreflang_consistency']['state']);
        $tr['kind'] = 'home';
        $home = $this->evaluate([$tr['key'] => $tr, $en['key'] => $en], []);
        $this->assertStringContainsString('X-default yok', $home['pages'][$tr['key']]['website:url:hreflang_consistency']['finding']);
    }

    public function test_site_level_eeat_schema_and_gbp_nap_checks(): void
    {
        $homeSignals = self::signals(['jsonld_types' => ['Dentist'], 'phones' => ['3125551122'], 'organizations' => [['types' => ['Dentist'], 'name' => 'Panorama Diş Kliniği', 'telephone' => '3125551122', 'has_address' => true, 'postal_code' => '06680', 'locality' => 'Ankara']]]);
        $home = self::record(['path' => '/', 'kind' => 'home', 'signals' => $homeSignals]);
        $home['key'] = self::HOST;
        $pages = [$home['key'] => $home];
        foreach (['/hakkimizda/' => 'about', '/iletisim/' => 'contact', '/doktorlarimiz/' => 'page'] as $path => $kind) {
            $record = self::record(['path' => $path, 'kind' => $kind]);
            $pages[$record['key']] = $record;
        }
        $site = ['home_key' => self::HOST, 'ymyl' => true, 'health' => true, 'gbp' => ['title' => 'Panorama Diş Kliniği', 'phones' => ['3125551122'], 'postal_code' => '06680']];

        $ok = $this->evaluate($pages, $site)['site'];
        foreach (['eeat_trust_pages', 'medical_business_schema', 'organization_nap_schema', 'gbp_nap_consistency'] as $method) {
            $this->assertSame('pass', $ok['website:url:'.$method]['state'], $method.': '.$ok['website:url:'.$method]['finding']);
        }

        unset($pages[self::HOST.'/doktorlarimiz']);
        $site['gbp']['phones'] = ['2125550000'];
        $pages[self::HOST]['signals']['jsonld_types'] = ['Organization'];
        $pages[self::HOST]['signals']['organizations'][0]['has_address'] = false;
        $bad = $this->evaluate($pages, $site)['site'];
        $this->assertSame('fail', $bad['website:url:eeat_trust_pages']['state']);
        $this->assertStringContainsString('hekim / uzman kadro', $bad['website:url:eeat_trust_pages']['finding']);
        $this->assertSame('fail', $bad['website:url:medical_business_schema']['state']);
        $this->assertStringContainsString('adres', $bad['website:url:organization_nap_schema']['finding']);
        $this->assertStringContainsString('Telefon farklı', $bad['website:url:gbp_nap_consistency']['finding']);

        $none = $this->evaluate($pages, ['home_key' => self::HOST, 'ymyl' => false, 'health' => false, 'gbp' => null])['site'];
        foreach (['eeat_trust_pages', 'medical_business_schema', 'gbp_nap_consistency'] as $method) {
            $this->assertSame('not_applicable', $none['website:url:'.$method]['state'], $method);
        }
    }

    public function test_signal_extractor_reads_eeat_faq_schema_and_nap_from_html(): void
    {
        $html = '<html lang="tr"><head><link rel="alternate" hreflang="en" href="/en/"><script type="application/ld+json">{"@graph":[{"@type":"BlogPosting","dateModified":"2026-09-01","author":{"@type":"Person","name":"Dr. Ali"}},{"@type":"Dentist","name":"Klinik","telephone":"0312 555 11 22","address":{"@type":"PostalAddress","postalCode":"06680"}}]}</script></head>'
            .'<body><h1>İmplant</h1><p>Son güncelleme: 01.09.2026</p><p>Tıbbi olarak inceleyen: Dr. Ali Veli</p><h2>Sıkça sorulan sorular</h2><h3>Ağrı olur mu?</h3><h3>Ne kadar sürer?</h3><h3>Kimlere uygulanır?</h3></body></html>';
        $signals = (new PageSignalExtractor)->extract('https://'.self::HOST.'/blog/implant/', $html);

        $this->assertTrue($signals['author']);
        $this->assertTrue($signals['medical_review']);
        $this->assertTrue($signals['visible_date']);
        $this->assertTrue($signals['faq_content']);
        $this->assertTrue($signals['date_modified']);
        $this->assertContains('BlogPosting', $signals['jsonld_types']);
        $this->assertSame('06680', $signals['organizations'][0]['postal_code']);
        $this->assertSame(['3125551122'], $signals['phones']);
        $this->assertSame('tr', $signals['lang']);
        $this->assertSame('en', $signals['hreflang'][0]['language']);

        $empty = (new PageSignalExtractor)->extract('https://'.self::HOST.'/x/', '<html><body><p>Merhaba dünya.</p></body></html>');
        $this->assertFalse($empty['author']);
        $this->assertFalse($empty['visible_date']);
        $this->assertFalse($empty['faq_content']);
    }

    public function test_robots_checks_separate_search_bots_from_training_bots(): void
    {
        $home = self::record(['path' => '/', 'kind' => 'home']);
        $home['key'] = self::HOST;
        $service = self::record(['path' => '/tedavilerimiz/implant/', 'kind' => 'service']);
        $pages = [$home['key'] => $home, $service['key'] => $service];
        $robots = fn (string $body): array => ['robots' => ['available' => true, 'body' => $body]];

        $open = $this->evaluate($pages, $robots("User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nUser-agent: Google-Extended\nDisallow: /\n"))['site'];
        $this->assertSame('pass', $open['website:url:robots_search_engines']['state']);
        $this->assertSame('pass', $open['website:url:robots_ai_search_bots']['state']);
        // Blocking training bots is informational only and never a problem.
        $this->assertSame('info', $open['website:url:ai_training_bots']['state']);
        $this->assertStringContainsString('GPTBot, Google-Extended', $open['website:url:ai_training_bots']['finding']);

        $blocked = $this->evaluate($pages, $robots("User-agent: Bingbot\nDisallow: /tedavilerimiz/\n\nUser-agent: OAI-SearchBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n"))['site'];
        $this->assertSame('fail', $blocked['website:url:robots_search_engines']['state']);
        $this->assertStringContainsString('Bingbot', $blocked['website:url:robots_search_engines']['finding']);
        $this->assertStringContainsString('/tedavilerimiz/implant/', $blocked['website:url:robots_search_engines']['finding']);
        $this->assertSame('review', $blocked['website:url:robots_ai_search_bots']['state']);
        $this->assertStringContainsString('OAI-SearchBot', $blocked['website:url:robots_ai_search_bots']['finding']);

        $none = $this->evaluate($pages, ['robots' => ['available' => false, 'body' => null]])['site'];
        foreach (['robots_search_engines', 'robots_ai_search_bots', 'ai_training_bots'] as $method) {
            $this->assertSame('not_applicable', $none['website:url:'.$method]['state'], $method);
        }
    }

    public function test_robots_rules_follow_rfc_9309_matching(): void
    {
        $rules = new RobotsTxtRules("User-agent: *\nDisallow: /private\nAllow: /private/public\nDisallow: /*.pdf$\n\nUser-agent: googlebot\nDisallow:\n");
        $this->assertFalse($rules->allowed('Bingbot', '/private/x'));
        $this->assertTrue($rules->allowed('Bingbot', '/private/public/a'), 'longest match wins');
        $this->assertFalse($rules->allowed('Bingbot', '/files/a.pdf'));
        $this->assertTrue($rules->allowed('Bingbot', '/files/a.pdf?x=1'), '$ anchors the end');
        $this->assertTrue($rules->allowed('Googlebot', '/private/x'), 'its own group (empty Disallow) wins over *');
        $this->assertTrue((new RobotsTxtRules(''))->allowed('Googlebot', '/'));
    }

    public function test_site_checks_for_indexnow_bing_reputation_health_disclosure_and_ai_referrals(): void
    {
        $home = self::record(['path' => '/', 'kind' => 'home', 'signals' => self::signals(['bing_verified' => true, 'editor' => true, 'visible_updated' => true])]);
        $home['key'] = self::HOST;
        $en = self::record(['path' => '/en/dental-implants/', 'kind' => 'service', 'wp_language' => 'en', 'signals' => self::signals(['editor' => false])]);
        $pages = [$home['key'] => $home, $en['key'] => $en];
        $site = ['health' => true, 'wordpress' => ['paired' => true, 'plugin_version' => '1.5.0', 'capabilities' => ['drafts', 'indexnow']],
            'ai_referrals' => ['available' => true, 'sources' => ['chatgpt.com' => 30, 'perplexity.ai' => 12]]];

        $ok = $this->evaluate($pages, $site)['site'];
        $this->assertSame('pass', $ok['website:url:indexnow']['state']);
        $this->assertSame('pass', $ok['website:url:bing_webmaster']['state']);
        $this->assertSame('pass', $ok['website:url:site_reputation_abuse']['state']);
        $this->assertSame('review', $ok['website:url:tr_health_disclosure']['state'], 'English pages need the health tourism certificate');
        $this->assertStringContainsString('sağlık turizmi yetki belgesi', $ok['website:url:tr_health_disclosure']['finding']);
        $this->assertSame('info', $ok['website:url:ai_referral_tracking']['state']);
        $this->assertStringContainsString('42 oturum (chatgpt.com 30, perplexity.ai 12)', $ok['website:url:ai_referral_tracking']['finding']);

        $pages[$en['key']]['signals']['health_tourism_cert'] = true;
        $this->assertSame('pass', $this->evaluate($pages, $site)['site']['website:url:tr_health_disclosure']['state']);

        $spam = self::record(['path' => '/deneme-bonusu-veren-siteler/']);
        $pages[$spam['key']] = $spam;
        $pages[$home['key']]['signals']['bing_verified'] = false;
        $bad = $this->evaluate($pages, ['health' => false, 'wordpress' => ['paired' => true, 'plugin_version' => '1.5.0', 'capabilities' => ['drafts']],
            'ai_referrals' => ['available' => true, 'sources' => []]])['site'];
        $this->assertSame('review', $bad['website:url:indexnow']['state']);
        $this->assertSame('unknown', $bad['website:url:bing_webmaster']['state'], 'DNS / XML verification cannot be seen');
        $this->assertSame('review', $bad['website:url:site_reputation_abuse']['state']);
        $this->assertSame('not_applicable', $bad['website:url:tr_health_disclosure']['state']);
        $this->assertSame('info', $bad['website:url:ai_referral_tracking']['state']);

        $none = $this->evaluate([$home['key'] => $home], ['wordpress' => null, 'ai_referrals' => null])['site'];
        $this->assertSame('unknown', $none['website:url:indexnow']['state']);
        $this->assertSame('not_applicable', $none['website:url:ai_referral_tracking']['state']);
        $old = $this->evaluate([$home['key'] => $home], ['wordpress' => ['paired' => true, 'plugin_version' => '1.3.0', 'capabilities' => null]])['site'];
        $this->assertSame('review', $old['website:url:indexnow']['state']);
    }

    public function test_near_duplicate_service_pages_are_flagged_by_text_sketch(): void
    {
        $extractor = new PageSignalExtractor;
        $words = fn (int $seed): string => implode(' ', array_map(fn (int $i): string => 'kelime'.(($i * 7 + $seed) % 997), range(1, 400)));
        $body = $words(1);
        $a = self::record(['path' => '/tedavilerimiz/implant/', 'kind' => 'service', 'signals' => $extractor->extract('https://'.self::HOST.'/tedavilerimiz/implant/', '<html><body><main><p>İmplant '.$body.'</p></main></body></html>')]);
        $b = self::record(['path' => '/tedavilerimiz/zirkonyum/', 'kind' => 'service', 'signals' => $extractor->extract('https://'.self::HOST.'/tedavilerimiz/zirkonyum/', '<html><body><main><p>Zirkonyum '.$body.'</p></main></body></html>')]);
        $c = self::record(['path' => '/tedavilerimiz/kanal/', 'kind' => 'service', 'signals' => $extractor->extract('https://'.self::HOST.'/tedavilerimiz/kanal/', '<html><body><main><p>'.$words(500).'</p></main></body></html>')]);
        $result = $this->evaluate([$a['key'] => $a, $b['key'] => $b, $c['key'] => $c], ['health' => false])['pages'];

        $this->assertSame('review', $result[$a['key']]['website:url:service_content_depth']['state']);
        $this->assertStringContainsString('/tedavilerimiz/zirkonyum/ ile aynı', $result[$a['key']]['website:url:service_content_depth']['finding']);
        $this->assertSame('pass', $result[$c['key']]['website:url:service_content_depth']['state']);
        $this->assertGreaterThan(0.9, UrlStandardEvaluator::similarity($a['signals']['shingles'], $b['signals']['shingles']));
    }

    public function test_signal_extractor_reads_snippet_js_bing_media_stars_and_spam(): void
    {
        $html = '<html><head><meta name="robots" content="index, max-snippet:0"><meta name="msvalidate.01" content="ABCDEF123"><script type="application/ld+json">{"@type":"Dentist","name":"Atlas Diş","telephone":"0312 555 11 22","aggregateRating":{"@type":"AggregateRating","ratingValue":"4.9"}}</script></head>'
            .'<body><header><nav>Menü</nav></header><main><h2>Tedavi süreci</h2><p data-nosnippet>Gizli metin burada.</p><img src="/uploads/ekip.jpg" alt="Ekibimiz"><img src="https://images.unsplash.com/x.jpg" alt="Gülümseme"><p>Sorumlu hekim: Dr. Ayşe Yılmaz. Atlas Diş 0312 555 11 22. Deneme bonusu</p></main></body></html>';
        $signals = (new PageSignalExtractor)->extract('https://'.self::HOST.'/', $html);

        $this->assertTrue($signals['nosnippet_meta']);
        $this->assertTrue($signals['bing_verified']);
        $this->assertSame(3, $signals['nosnippet_words']);
        $this->assertSame(['total' => 2, 'with_alt' => 2, 'original' => 1], $signals['images']);
        $this->assertTrue($signals['organizations'][0]['self_rating']);
        $this->assertTrue($signals['organizations'][0]['phone_visible']);
        $this->assertTrue($signals['organizations'][0]['name_visible']);
        $this->assertSame(['Tedavi süreci'], $signals['headings']);
        $this->assertTrue($signals['editor']);
        $this->assertSame(['deneme bonusu'], $signals['spam_terms']);
        $this->assertFalse($signals['spa_root']);

        $spa = (new PageSignalExtractor)->extract('https://'.self::HOST.'/x/', '<html><body><div id="root"></div><script src="/app.js"></script></body></html>');
        $this->assertTrue($spa['spa_root']);
        $this->assertSame(0, $spa['main_words']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $pages
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function evaluate(array $pages, array $site): array
    {
        $standards = array_filter((new WebsiteStandardCatalog)->definitions(), fn (array $s): bool => str_starts_with($s['method'], 'url_'));

        return (new UrlStandardEvaluator)->evaluate($standards, $pages, $site + [
            'home_key' => self::HOST, 'modifiers' => (array) config('moxdop-url-audit.doorway_modifiers'), 'locations' => [], 'now' => strtotime('2026-09-23'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function record(array $overrides): array
    {
        $path = (string) ($overrides['path'] ?? '/sayfa/');
        $key = self::HOST.rtrim($path, '/');

        return array_replace([
            'key' => $key, 'url' => 'https://'.self::HOST.$path, 'path' => $path, 'kind' => 'page', 'status_code' => 200, 'noindex' => false,
            'canonical_key' => null, 'indexable' => true, 'title' => null, 'h1' => null, 'word_count' => 600, 'cms_type' => null, 'modified_at' => null,
            'wp_language' => null, 'wp_translations' => [], 'in_sitemap' => null, 'inlinks' => null, 'clicks' => null, 'clicks_prev' => null,
            'impressions' => null, 'impr_90' => null, 'inspection' => null, 'is_service' => false, 'is_priority_service' => false,
            'cannibal' => null, 'signals' => null,
        ], $overrides, ['key' => $key]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function signals(array $overrides = []): array
    {
        return array_replace([
            'lang' => 'tr', 'hreflang' => [], 'jsonld_types' => [], 'date_modified' => false, 'organizations' => [], 'author' => false,
            'medical_review' => false, 'visible_date' => false, 'faq_content' => false, 'question_count' => 0, 'phones' => [],
        ], $overrides);
    }
}
