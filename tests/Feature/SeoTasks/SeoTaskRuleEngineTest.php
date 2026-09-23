<?php

namespace Tests\Feature\SeoTasks;

use App\Services\SeoTasks\SeoTaskConfig;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Services\SeoTasks\SeoText;
use Tests\TestCase;

/**
 * Pure rule-engine behaviour over an in-memory input package (no database, no LLM).
 */
final class SeoTaskRuleEngineTest extends TestCase
{
    public function test_ctr_curve_interpolates_between_positions(): void
    {
        $this->assertEqualsWithDelta(0.28, SeoTaskConfig::ctrAt(1.0), 0.0001);
        $this->assertEqualsWithDelta(0.125, SeoTaskConfig::ctrAt(2.5), 0.0001);
        $this->assertEqualsWithDelta(0.004, SeoTaskConfig::ctrAt(35.0), 0.0001);
    }

    public function test_text_helpers_fold_turkish_and_normalize_urls(): void
    {
        $this->assertSame('dis implanti fiyatlari', SeoText::fold('Diş İmplantı Fiyatları'));
        $this->assertTrue(SeoText::containsPhrase('İstanbul diş implantı fiyatları 2026', 'diş implantı'));
        $this->assertSame('example.test/hizmetler/implant', SeoText::urlKey('https://www.Example.test/hizmetler/implant/?utm_source=x'));
        $this->assertTrue(SeoText::looksLikeQuestion('implant nasıl yapılır'));
        $this->assertFalse(SeoText::looksLikeQuestion('implant diş'));
    }

    public function test_engine_produces_fix_strengthen_create_question_and_ai_visibility_tasks(): void
    {
        $result = (new SeoTaskRuleEngine)->evaluate($this->input());
        $tasks = collect($result['tasks']);
        $byType = $tasks->groupBy('type');

        // Fix: finding + aggregated page rules.
        $this->assertTrue($byType->has('fix'));
        $this->assertTrue($tasks->contains(fn (array $t): bool => $t['rule_id'] === 'finding:website:head:title-missing'));
        $this->assertTrue($tasks->contains(fn (array $t): bool => $t['rule_id'] === 'meta-missing'));
        $this->assertTrue($tasks->contains(fn (array $t): bool => $t['rule_id'] === 'thin-content'));
        $this->assertFalse($tasks->contains(fn (array $t): bool => str_contains($t['rule_id'], 'low-severity')), 'low severity findings must not become tasks');

        // Strengthen: the implant page ranks 7–9 for priority-service queries.
        $strengthen = $byType->get('strengthen', collect())->first();
        $this->assertNotNull($strengthen);
        $this->assertSame('https://example.test/implant/', $strengthen['target_url']);
        $this->assertGreaterThan(0, $strengthen['estimated_extra_clicks']);
        $this->assertTrue(collect($strengthen['checklist'])->contains(fn (string $s): bool => str_contains($s, 'Title')), 'top query not in title → checklist item');
        $this->assertTrue(collect($strengthen['checklist'])->contains(fn (string $s): bool => str_contains($s, 'Niyeti ayır')), 'competing page → cannibalization warning');

        // Create: at least the weekly minimum, GSC-backed bucket first.
        $create = $byType->get('create', collect());
        $this->assertGreaterThanOrEqual(SeoTaskConfig::int('create.min_per_site', 4), $create->count());
        $gscBacked = $create->first(fn (array $t): bool => ($t['evidence']['source'] ?? '') === 'gsc');
        $this->assertNotNull($gscBacked);
        $this->assertSame('guide', $gscBacked['content_brief']['page_type']);
        $this->assertContains('implant nasıl yapılır', $gscBacked['content_brief']['queries']);
        $this->assertNotEmpty($gscBacked['content_brief']['h2_outline']);
        $this->assertStringStartsWith('https://example.test/', $gscBacked['target_url']);

        // Question: "Zirkonyum" has a weak candidate page only.
        $question = $byType->get('question', collect())->first();
        $this->assertNotNull($question);
        $this->assertSame(3, $question['brand_offering_id']);
        $this->assertNotEmpty($question['evidence']['candidates']);

        // Service page auto-assignment for the implant offering.
        $assignment = collect($result['assignments'])->firstWhere('brand_offering_id', 1);
        $this->assertSame('assigned', $assignment['status']);
        $this->assertSame('https://example.test/implant/', $assignment['page_url']);

        // AI visibility: robots.txt blocks OAI-SearchBot, homepage has no Organization schema.
        $ai = $byType->get('ai_visibility', collect());
        $this->assertTrue($ai->contains(fn (array $t): bool => $t['rule_id'] === 'robots-bot-block' && in_array('OAI-SearchBot', $t['evidence']['blocked'], true)));
        $this->assertTrue($ai->contains(fn (array $t): bool => $t['rule_id'] === 'org-schema'));

        // Quotas and stable keys.
        $this->assertLessThanOrEqual(SeoTaskConfig::int('quotas.open_tasks_per_site', 15), $tasks->count());
        $this->assertSame($tasks->count(), $tasks->pluck('task_key')->unique()->count());
        $again = (new SeoTaskRuleEngine)->evaluate($this->input());
        $this->assertSame($tasks->pluck('task_key')->sort()->values()->all(), collect($again['tasks'])->pluck('task_key')->sort()->values()->all(), 'keys are deterministic across runs');
    }

    public function test_operator_assignment_is_never_overridden_and_google_block_is_critical(): void
    {
        $input = $this->input();
        $input['assignments'][3] = ['id' => 9, 'page_url' => 'https://example.test/zirkonyum-kaplama/', 'url_key' => 'example.test/zirkonyum-kaplama', 'status' => 'assigned', 'decision_source' => 'operator', 'score' => null];
        $input['robots']['body'] = "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: Googlebot\nDisallow: /\n";

        $result = (new SeoTaskRuleEngine)->evaluate($input);
        $tasks = collect($result['tasks']);

        $this->assertFalse($tasks->contains(fn (array $t): bool => $t['type'] === 'question'));
        $this->assertNull(collect($result['assignments'])->firstWhere('brand_offering_id', 3));
        $block = $tasks->first(fn (array $t): bool => $t['rule_id'] === 'robots-bot-block');
        $this->assertSame('critical', $block['severity']);
        $this->assertSame(['Googlebot'], $block['evidence']['blocked']);
    }

    public function test_minimum_content_suggestions_are_guaranteed_without_search_console_data(): void
    {
        $input = $this->input();
        $input['gsc'] = ['available' => false, 'reason' => 'gsc_not_bound', 'rows' => [], 'query_count' => 0, 'page_count' => 0, 'truncated' => false];

        $result = (new SeoTaskRuleEngine)->evaluate($input);
        $create = collect($result['tasks'])->where('type', 'create');

        $this->assertGreaterThanOrEqual(4, $create->count());
        $this->assertTrue($create->every(fn (array $t): bool => is_array($t['content_brief']) && $t['content_brief']['queries'] !== []));
        $this->assertTrue($create->contains(fn (array $t): bool => ($t['evidence']['source'] ?? '') === 'fallback'));
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $page = fn (string $path, array $over = []): array => array_replace([
            'profile_id' => crc32($path), 'url' => 'https://example.test'.$path, 'url_key' => SeoText::urlKey('https://example.test'.$path),
            'path' => $path, 'observed' => true, 'title' => 'Sayfa', 'meta_description' => 'Açıklama', 'h1' => 'Başlık', 'h1_present' => true,
            'word_count' => 600, 'status_code' => 200, 'final_url' => null, 'redirect_count' => 0, 'robots' => 'index, follow', 'noindex' => false,
            'canonical_hrefs' => [], 'structured_types' => [], 'crawl_issues' => [], 'internal_links' => 5, 'cms_type' => 'page', 'cms_status' => 'publish', 'last_observed_at' => null,
        ], $over);

        $pages = [];
        foreach ([
            $page('/', ['title' => 'Örnek Klinik', 'h1' => 'Örnek Klinik', 'structured_types' => ['WebSite']]),
            $page('/implant/', ['title' => 'İmplant Tedavisi', 'h1' => 'İmplant Tedavisi', 'word_count' => 500, 'structured_types' => ['MedicalWebPage']]),
            $page('/blog/implant-fiyat/', ['title' => 'İmplant fiyatları', 'h1' => 'İmplant fiyatları', 'word_count' => 900]),
            $page('/ortodonti/', ['title' => 'Ortodonti', 'h1' => 'Ortodonti', 'meta_description' => null]),
            $page('/estetik/', ['title' => 'Gülüş Estetiği', 'h1' => 'Gülüş estetiği', 'word_count' => 80]),
            $page('/hakkimizda/', ['title' => 'Hakkımızda', 'h1' => 'Hakkımızda']),
        ] as $p) {
            $pages[$p['url_key']] = $p;
        }

        $row = fn (string $q, string $path, int $impr, int $clicks, float $pos): array => [
            'query' => $q, 'page' => 'https://example.test'.$path, 'url_key' => SeoText::urlKey('https://example.test'.$path),
            'clicks' => $clicks, 'impressions' => $impr, 'position' => $pos,
        ];
        $rows = [
            $row('implant diş', '/implant/', 1200, 30, 7.2),
            $row('implant diş', '/blog/implant-fiyat/', 500, 10, 12.0),
            $row('diş implantı fiyatları', '/implant/', 800, 15, 8.5),
            $row('implant tedavisi', '/implant/', 300, 20, 3.1),
            $row('implant nasıl yapılır', '/blog/implant-fiyat/', 400, 2, 28.0),
            $row('implant ne kadar sürer', '/blog/implant-fiyat/', 150, 0, 35.0),
            $row('ortodonti tedavisi', '/ortodonti/', 250, 12, 6.0),
            $row('zirkonyum kaplama', '/estetik/', 90, 1, 24.0),
            $row('kadıköy implant', '/implant/', 60, 0, 31.0),
        ];

        return [
            'site' => ['id' => 1, 'brand_id' => 1, 'customer_id' => 1, 'brand_name' => 'Örnek Klinik', 'domain' => 'example.test', 'primary_url' => 'https://example.test/', 'origin' => 'https://example.test', 'home_key' => 'example.test', 'languages' => ['tr']],
            'period' => ['start' => '2026-06-25', 'end' => '2026-09-23', 'days' => 90],
            'gsc' => ['available' => true, 'reason' => null, 'rows' => $rows, 'query_count' => 8, 'page_count' => 4, 'truncated' => false],
            'pages' => $pages,
            'findings' => [
                ['id' => 10, 'fingerprint' => 'fp-title', 'rule_id' => 'website:head:title-missing', 'category' => 'seo', 'severity' => 'high', 'title' => 'Başlık etiketi eksik: /iletisim', 'summary' => 'Sayfada title yok.', 'subject_id' => 'https://example.test/iletisim/', 'last_seen_at' => null],
                ['id' => 11, 'fingerprint' => 'fp-og', 'rule_id' => 'website:head:open-graph-incomplete-low-severity', 'category' => 'seo', 'severity' => 'low', 'title' => 'OG eksik', 'summary' => null, 'subject_id' => null, 'last_seen_at' => null],
            ],
            'offerings' => [
                ['id' => 1, 'catalog_item_id' => 11, 'name' => 'İmplant Diş', 'names' => ['İmplant Diş', 'Diş İmplantı'], 'keywords' => ['implant'], 'is_priority' => true, 'priority_rank' => 1, 'queries' => ['implant diş', 'implant fiyatları 2026']],
                ['id' => 2, 'catalog_item_id' => 12, 'name' => 'Ortodonti', 'names' => ['Ortodonti'], 'keywords' => [], 'is_priority' => false, 'priority_rank' => null, 'queries' => []],
                ['id' => 3, 'catalog_item_id' => 13, 'name' => 'Zirkonyum Kaplama', 'names' => ['Zirkonyum Kaplama'], 'keywords' => ['zirkonyum'], 'is_priority' => true, 'priority_rank' => 2, 'queries' => []],
            ],
            'service_areas' => ['Kadıköy', 'İstanbul'],
            'ga4' => ['available' => true, 'landing' => ['example.test/implant' => ['sessions' => 400, 'engaged_sessions' => 200, 'key_events' => 0]]],
            'robots' => ['available' => true, 'body' => "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: OAI-SearchBot\nDisallow: /\n", 'observed_at' => null],
            'assignments' => [],
        ];
    }
}
