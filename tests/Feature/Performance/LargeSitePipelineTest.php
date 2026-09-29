<?php

namespace Tests\Feature\Performance;

use App\Jobs\RefreshUrlVerdictsJob;
use App\Jobs\RunSeoPlanJob;
use App\Models\SeoPlan;
use App\Models\User;
use App\Models\WebsiteUrlVerdict;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Services\SeoTasks\SeoText;
use App\Services\Website\UrlAudit\UrlAuditService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Production (panoramaankara.com: 5 235 page profiles, 4 591 documents, 2 333 sitemap URLs) timed out the SEO plan in
 * 3 of 4 runs and the URL karnesi repeatedly. These tests run the same work on a site of that size and assert that
 * it is bounded — database round trips, chunked writes, pairwise text comparisons — rather than wall time.
 */
#[Group('performance')]
final class LargeSitePipelineTest extends TestCase
{
    use BuildsLargeWebsite;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-seo-tasks.llm.enabled' => false]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_rule_engine_compares_5000_pages_with_3000_portfolio_queries_through_an_index_not_pairwise(): void
    {
        SeoText::forgetMemo();
        $result = (new SeoTaskRuleEngine)->evaluate($this->largeInput(pages: 5000, gscRows: 5000, offerings: 20, queriesPerOffering: 150));

        $this->assertNotEmpty($result['tasks']);
        $this->assertTrue(collect($result['tasks'])->contains(fn (array $t): bool => $t['rule_id'] === 'prune-pages'), 'pruning ran on the whole site');
        // Old engine: every portfolio query × every page (3 000 × 5 000 = 15 M comparisons, each re-folding both texts)
        // plus every silent page × every page with traffic — minutes of CPU, the RunSeoPlanJob timeouts.
        $this->assertLessThan(1_500_000, SeoText::overlapComparisons(), 'pairwise comparisons stay ~ offerings × pages');
        $this->assertLessThan(60_000, SeoText::foldComputations(), 'each distinct text is folded once');
    }

    public function test_seo_plan_on_a_5000_page_site_completes_with_bounded_database_work(): void
    {
        ['site' => $site] = $this->buildLargeWebsite();
        Bus::fake([RefreshUrlVerdictsJob::class]);
        $plan = app(SeoPlanRunner::class)->queue($site, $this->admin, dispatchJob: false);

        DB::enableQueryLog();
        $done = app(SeoPlanRunner::class)->run($plan->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(SeoPlan::STATUS_COMPLETED, $done->status, (string) $done->error_summary);
        $this->assertGreaterThan(4000, (int) data_get($done->input_summary, 'pages'), 'every document page was planned');
        $this->assertLessThan(600, count($queries), 'reads are chunked, not one query per page');
        $profileReads = array_filter($queries, fn (array $q): bool => str_contains($q['query'], 'from "website_page_profiles"'));
        $this->assertLessThanOrEqual(20, count($profileReads), 'page profiles are read in chunks of 500');
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), (new RunSeoPlanJob($plan->id))->timeout);
    }

    public function test_url_verdicts_of_a_5000_page_site_are_stored_in_chunks_with_bounded_reads(): void
    {
        ['site' => $site] = $this->buildLargeWebsite();

        DB::enableQueryLog();
        $result = app(UrlAuditService::class)->refresh($site);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('completed', $result['status']);
        $this->assertGreaterThan(4000, $result['urls']);
        $this->assertSame($result['urls'], WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->count());
        $inserts = array_filter($queries, fn (array $q): bool => str_starts_with($q['query'], 'insert into "website_url_verdicts"'));
        $this->assertSame((int) ceil($result['urls'] / 200), count($inserts), 'verdicts are written 200 per statement');
        $this->assertLessThan(1000, count($queries));
    }

    /**
     * @return array<string, mixed>
     */
    private function largeInput(int $pages, int $gscRows, int $offerings, int $queriesPerOffering): array
    {
        $pageRows = [];
        for ($i = 0; $i < $pages; $i++) {
            $path = '/'.SeoText::slugify($this->phrase(3, $i)).'-'.$i.'/';
            $url = 'https://panorama.test'.$path;
            $pageRows[SeoText::urlKey($url)] = ['profile_id' => $i + 1, 'url' => $url, 'url_key' => SeoText::urlKey($url), 'path' => $path, 'observed' => true,
                'title' => ucfirst($this->phrase(4, $i * 3)), 'meta_description' => $i % 7 ? 'Açıklama '.$i : null, 'h1' => ucfirst($this->phrase(3, $i * 5)), 'h1_present' => true,
                'word_count' => 200 + $i % 900, 'status_code' => $i % 50 ? 200 : 404, 'final_url' => null, 'redirect_count' => 0, 'robots' => 'index, follow', 'noindex' => false,
                'canonical_hrefs' => [], 'structured_types' => [], 'crawl_issues' => [], 'internal_links' => 5, 'cms_type' => 'post', 'cms_status' => 'publish', 'last_observed_at' => null];
        }
        $keys = array_keys($pageRows);
        $rows = [];
        for ($i = 0; $i < $gscRows; $i++) {
            $key = $keys[($i * 37) % $pages];
            $rows[] = ['query' => $this->phrase(3, $i + 7), 'page' => $pageRows[$key]['url'], 'url_key' => $key, 'clicks' => $i % 20, 'impressions' => 1 + $i % 900, 'position' => 1 + ($i % 400) / 10];
        }
        $offeringRows = [];
        for ($o = 1; $o <= $offerings; $o++) {
            $queries = [];
            for ($j = 0; $j < $queriesPerOffering; $j++) {
                $queries[] = $this->phrase(3, $o * 1000 + $j);
            }
            $offeringRows[] = ['id' => $o, 'catalog_item_id' => $o, 'name' => ucfirst(self::WORDS[$o]).' '.self::WORDS[$o + 1], 'names' => [ucfirst(self::WORDS[$o])],
                'keywords' => [self::WORDS[$o]], 'is_priority' => $o <= 5, 'priority_rank' => $o <= 5 ? $o : null, 'queries' => $queries];
        }
        $traffic = [];
        $inlinks = [];
        $outlinks = [];
        foreach ($keys as $n => $key) {
            if ($n % 2 === 0) {
                $traffic[$key] = ['url' => $pageRows[$key]['url'], 'clicks_cur' => 3, 'clicks_prev' => 9, 'impr_cur' => 40, 'impr_prev' => 90, 'impr_90' => 120];
            }
            foreach ([17, 34, 51] as $step) {
                $to = $keys[($n + $step) % $pages];
                $outlinks[$key][] = $to;
                $inlinks[$to][] = $key;
            }
        }

        return [
            'site' => ['id' => 1, 'brand_id' => 1, 'customer_id' => 1, 'brand_name' => 'Panorama', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/',
                'origin' => 'https://panorama.test', 'home_key' => 'panorama.test', 'languages' => ['tr']],
            'period' => ['start' => '2026-06-25', 'end' => '2026-09-23', 'days' => 90],
            'gsc' => ['available' => true, 'reason' => null, 'rows' => $rows, 'query_count' => $gscRows, 'page_count' => 3000, 'truncated' => false],
            'pages' => $pageRows, 'findings' => [], 'offerings' => $offeringRows, 'service_areas' => ['Çankaya', 'Ankara'],
            'service_area_rows' => [['country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya']],
            'ga4' => ['available' => false, 'landing' => []], 'robots' => ['available' => false, 'body' => null, 'observed_at' => null], 'assignments' => [],
            'traffic' => ['available' => true, 'history_days' => 120, 'pages' => $traffic],
            'links' => ['available' => true, 'inlinks' => $inlinks, 'outlinks' => $outlinks],
        ];
    }
}
