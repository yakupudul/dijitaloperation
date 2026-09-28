<?php

namespace Tests\Feature\SeoTasks;

use App\Models\ComplianceRule;
use App\Services\Compliance\Packs\HealthSectorPack;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Services\SeoTasks\SeoText;
use Tests\TestCase;

/**
 * Faz 0 rule-engine guarantees (pure, no database): an empty page inventory produces one setup card and no new-page
 * proposals; sitemap-only rows count as existing pages; proposed URLs follow the site's structure; a health brand's
 * titles, seeds and outlines stay within its sector pack.
 */
final class SeoRuleEngineInventoryComplianceTest extends TestCase
{
    public function test_empty_inventory_emits_a_setup_card_and_no_create_or_missing_service_page_tasks(): void
    {
        $input = $this->input([]);
        $input['inventory'] = ['empty' => true, 'collection' => 'queued'];

        $result = (new SeoTaskRuleEngine)->evaluate($input);
        $tasks = collect($result['tasks']);

        $this->assertFalse($tasks->contains(fn (array $t): bool => $t['type'] === 'create'), 'no "Oluştur" task without a page list');
        $card = $tasks->firstWhere('rule_id', SeoTaskRuleEngine::INVENTORY_MISSING_RULE);
        $this->assertNotNull($card);
        $this->assertSame('question', $card['type']);
        $this->assertStringContainsString('tarama başlatıldı; tarama bitince plan kendiliğinden yenilenir', $card['reason']);
        $this->assertSame([], $result['assignments'], 'service ↔ page mappings are not overwritten while the list is empty');
        $this->assertTrue($result['stats']['inventory_missing']);
    }

    public function test_sitemap_only_profiles_count_as_the_existing_service_page(): void
    {
        // Sitemap rows: URL only, no title/H1 read yet (as projected from website_url).
        $input = $this->input([
            $this->sitemapPage('/'),
            $this->sitemapPage('/tedavilerimiz/implant-tedavisi/'),
            $this->sitemapPage('/tedavilerimiz/ortodonti/'),
            $this->sitemapPage('/dis-beyazlatma-nedir/'),
            $this->sitemapPage('/iletisim/'),
        ]);

        $tasks = collect((new SeoTaskRuleEngine)->evaluate($input)['tasks']);

        $this->assertNull($tasks->firstWhere('rule_id', SeoTaskRuleEngine::INVENTORY_MISSING_RULE));
        $this->assertFalse($tasks->contains(fn (array $t): bool => $t['title'] === 'İmplant Tedavisi için hizmet sayfası aç'));
        $this->assertFalse($tasks->contains(fn (array $t): bool => ($t['evidence']['source'] ?? '') === 'inventory' && $t['brand_offering_id'] === 1));
        $create = $tasks->where('type', 'create');
        $this->assertNotEmpty($create);
        foreach ($create as $task) {
            $this->assertStringNotContainsString('/blog/', (string) $task['target_url'], 'the site has no /blog/ folder');
        }
    }

    public function test_health_brand_titles_seeds_and_outlines_have_no_price_or_before_after_wording(): void
    {
        $input = $this->input([$this->sitemapPage('/'), $this->sitemapPage('/iletisim/')]);
        $input['gsc']['rows'] = [
            ['query' => 'implant tedavisi fiyatları', 'page' => 'https://x.test/', 'url_key' => SeoText::urlKey('https://x.test/'), 'clicks' => 0, 'impressions' => 900, 'position' => 45.0],
        ];
        $input['gsc']['query_count'] = 1;
        $input['compliance_rules'] = array_map(
            static fn (array $rule): ComplianceRule => new ComplianceRule($rule + ['pack_id' => 'health', 'active' => $rule['active'] ?? true]),
            (new HealthSectorPack)->defaultRules(),
        );

        $create = collect((new SeoTaskRuleEngine)->evaluate($input)['tasks'])->where('type', 'create');
        $this->assertNotEmpty($create);

        $forbidden = '/fiyat|ücret|ucret|öncesi|oncesi/iu';
        foreach ($create as $task) {
            $brief = $task['content_brief'];
            $this->assertDoesNotMatchRegularExpression($forbidden, $task['title']);
            $this->assertDoesNotMatchRegularExpression($forbidden, $brief['page_title']);
            $this->assertDoesNotMatchRegularExpression($forbidden, implode(' | ', $brief['h2_outline']));
            $this->assertDoesNotMatchRegularExpression($forbidden, implode(' | ', $brief['queries']));
            $this->assertDoesNotMatchRegularExpression($forbidden, implode(' | ', $task['checklist']));
        }
        // The search demand stays visible as evidence.
        $this->assertTrue($create->contains(fn (array $t): bool => in_array('implant tedavisi fiyatları', array_column($t['evidence']['queries'], 'query'), true)));
        $this->assertTrue($create->contains(fn (array $t): bool => str_starts_with($t['title'], 'Rehber yaz: implant tedavisi')));

        // Without a sector pack the same plan may still mention price.
        unset($input['compliance_rules']);
        $plain = collect((new SeoTaskRuleEngine)->evaluate($input)['tasks'])->where('type', 'create');
        $this->assertTrue($plain->contains(fn (array $t): bool => preg_match('/fiyat/iu', implode(' ', $t['content_brief']['h2_outline']).' '.$t['content_brief']['page_title']) === 1));
    }

    /** @return array<string, mixed> */
    private function sitemapPage(string $path): array
    {
        $url = 'https://x.test'.$path;

        return [
            'profile_id' => crc32($path), 'url' => $url, 'url_key' => SeoText::urlKey($url), 'path' => $path, 'observed' => true,
            'head_observed' => false, 'title_present' => null, 'title' => null, 'meta_description' => null, 'h1' => null, 'h1_present' => null,
            'word_count' => null, 'status_code' => null, 'final_url' => null, 'redirect_count' => null, 'robots' => null, 'noindex' => false,
            'canonical_hrefs' => [], 'structured_types' => [], 'crawl_issues' => [], 'internal_links' => null, 'cms_type' => null, 'cms_status' => null,
            'last_observed_at' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function input(array $pages): array
    {
        $keyed = [];
        foreach ($pages as $page) {
            $keyed[$page['url_key']] = $page;
        }

        return [
            'site' => ['id' => 24, 'brand_id' => 1, 'customer_id' => 1, 'brand_name' => 'Panorama', 'domain' => 'x.test', 'primary_url' => 'https://x.test/', 'origin' => 'https://x.test', 'home_key' => 'x.test', 'languages' => ['tr']],
            'period' => ['start' => '2026-06-25', 'end' => '2026-09-23', 'days' => 90],
            'gsc' => ['available' => false, 'reason' => 'gsc_not_bound', 'rows' => [], 'query_count' => 0, 'page_count' => 0, 'truncated' => false],
            'pages' => $keyed,
            'findings' => [],
            'offerings' => [
                ['id' => 1, 'catalog_item_id' => 11, 'name' => 'İmplant Tedavisi', 'names' => ['İmplant Tedavisi'], 'keywords' => ['implant'], 'is_priority' => true, 'priority_rank' => 1, 'queries' => []],
                ['id' => 2, 'catalog_item_id' => 12, 'name' => 'Ortodonti', 'names' => ['Ortodonti'], 'keywords' => [], 'is_priority' => false, 'priority_rank' => null, 'queries' => []],
            ],
            'service_areas' => ['Ankara'],
            'ga4' => ['available' => false, 'landing' => []],
            'robots' => ['available' => false, 'body' => null, 'observed_at' => null],
            'assignments' => [],
        ];
    }
}
