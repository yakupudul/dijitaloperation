<?php

namespace Tests\Feature\Website;

use App\Livewire\Demo\Website\OverviewPage;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Website\WebsiteHealthScoreService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class WebsiteHealthScoreTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private const string FIRST = '2026-09-01 10:00:00';

    private const string SECOND = '2026-09-10 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    #[Test]
    public function score_formula_applies_severity_weights_to_affected_page_share(): void
    {
        $service = app(WebsiteHealthScoreService::class);

        $this->assertNull($service->score(0, []));
        $this->assertSame(100, $service->score(10, []));
        // 1/5 high (30 × 0.2 = 6) + 1/5 medium (17 × 0.2 = 3.4) + 2/5 low (8 × 0.4 = 3.2) → 87.4
        $this->assertSame(87, $service->score(5, [
            'd' => ['HTTP_4XX' => 'high'],
            'c' => ['REDIRECT_CHAIN' => 'medium'],
            'a' => ['MISSING_META_DESCRIPTION' => 'low'],
            'b' => ['MISSING_META_DESCRIPTION' => 'low', 'X' => 'info'],
        ]));
        $this->assertSame(0, $service->score(1, ['a' => ['A' => 'critical', 'B' => 'high', 'C' => 'medium', 'D' => 'low']]));
        $this->assertSame('good', $service->grade(80));
        $this->assertSame('fair', $service->grade(60));
        $this->assertSame('poor', $service->grade(59));
        $this->assertNull($service->grade(null));
    }

    #[Test]
    public function trend_keeps_the_last_six_crawls_and_single_crawl_hides_it(): void
    {
        $service = app(WebsiteHealthScoreService::class);
        $crawls = [];
        for ($index = 1; $index <= 7; $index++) {
            $crawls[] = [
                'key' => 'run:'.$index,
                'collected_at' => sprintf('2026-09-%02dT10:00:00+00:00', $index),
                'pages' => ['https://example.com/' => ['status' => 200, 'redirect_count' => 0, 'redirected_from' => null]],
                'issues' => $index === 7 ? ['https://example.com/' => ['HTTP_5XX' => 'critical']] : [],
            ];
        }

        $report = $service->report($crawls, [], [], []);
        $this->assertCount(6, $report['trend']);
        $this->assertSame('2026-09-02T10:00:00+00:00', $report['trend'][0]['collected_at']);
        $this->assertSame(55, $report['score']);
        $this->assertSame(-45, $report['change']);
        $this->assertSame(7, $report['crawl_count']);

        $single = $service->report([$crawls[0]], [], [], []);
        $this->assertSame([], $single['trend']);
        $this->assertNull($single['change']);
        $this->assertNull($single['groups'][0]['new_count'] ?? null);
    }

    #[Test]
    public function builds_score_trend_groups_and_derived_checks_from_collected_rows(): void
    {
        $asset = $this->seedSite();

        $report = app(WebsiteHealthScoreService::class)->build($asset);

        $this->assertTrue($report['available']);
        $this->assertSame('crawl', $report['source']);
        $this->assertSame(5, $report['pages_checked']);
        $this->assertSame(87, $report['score']);
        $this->assertSame('good', $report['grade']);
        $this->assertSame([98, 87], array_column($report['trend'], 'score'));
        $this->assertSame(-11, $report['change']);
        $this->assertStringStartsWith('2026-09-10', (string) $report['collected_at']);

        $groups = collect($report['groups'])->keyBy('code');

        $this->assertSame(['HTTP_4XX', 'REDIRECT_CHAIN', 'MISSING_META_DESCRIPTION', 'BROKEN_INTERNAL_LINK', 'ORPHAN_PAGE', 'DUPLICATE_TITLE'], $groups->keys()->all());

        $this->assertSame('broken', $groups['HTTP_4XX']['kind']);
        $this->assertSame(1, $groups['HTTP_4XX']['url_count']);
        $this->assertSame(1, $groups['HTTP_4XX']['new_count']);
        $this->assertSame('HTTP 404', $groups['HTTP_4XX']['items'][0]['detail']);

        $this->assertSame('redirect', $groups['REDIRECT_CHAIN']['kind']);
        $this->assertSame('https://example.com/c', $groups['REDIRECT_CHAIN']['items'][0]['url']);
        $this->assertStringContainsString('https://example.com/c-old', (string) $groups['REDIRECT_CHAIN']['items'][0]['detail']);

        $this->assertSame(2, $groups['MISSING_META_DESCRIPTION']['url_count']);
        $this->assertSame(1, $groups['MISSING_META_DESCRIPTION']['new_count']);
        $this->assertEqualsWithDelta(0.4, $groups['MISSING_META_DESCRIPTION']['share'], 0.0001);
        $this->assertTrue($groups['MISSING_META_DESCRIPTION']['scored']);

        $this->assertFalse($groups['BROKEN_INTERNAL_LINK']['scored']);
        $this->assertSame(['url' => 'https://example.com/', 'detail' => '→ https://example.com/d'], $groups['BROKEN_INTERNAL_LINK']['items'][0]);

        // The older edge "/ → /c" is superseded by the latest observation of "/", so /c is orphaned.
        $this->assertSame(['https://example.com/c'], array_column($groups['ORPHAN_PAGE']['items'], 'url'));

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], array_column($groups['DUPLICATE_TITLE']['items'], 'url'));
        $this->assertSame(1, $groups['DUPLICATE_TITLE']['cluster_count']);
        $this->assertFalse($groups->has('DUPLICATE_META_DESCRIPTION'));

        $this->assertSame([
            'broken_pages' => 1,
            'broken_links' => 1,
            'redirects' => 1,
            'orphans' => 1,
            'duplicates' => 2,
        ], $report['summary']);
    }

    #[Test]
    public function missing_link_graph_and_metadata_are_reported_as_unavailable(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Solo Website');
        $this->http($asset, 'https://example.com/', self::FIRST, 21);
        $this->issue($asset, 'https://example.com/', 'MISSING_TITLE', 'medium', self::FIRST, 21);

        $report = app(WebsiteHealthScoreService::class)->build($asset);

        $this->assertSame(83, $report['score']);
        $this->assertSame([], $report['trend']);
        $this->assertNull($report['summary']['orphans']);
        $this->assertNull($report['summary']['broken_links']);
        $this->assertNull($report['summary']['duplicates']);
        $this->assertFalse($report['availability']['orphans']);
        $this->assertNull($report['groups'][0]['new_count']);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'health'])
            ->assertSee(__('operator_website.health_score.title'))
            ->assertSee(__('operator_website.health_score.trend_hidden'))
            ->assertSee(__('operator_website.health_score.unavailable.links'))
            ->assertSee(__('operator_website.health_score.grades.good'))
            ->assertDontSee(__('operator.website.technical_health.empty.title'));
    }

    #[Test]
    public function health_tab_renders_score_trend_and_expandable_groups(): void
    {
        $asset = $this->seedSite();

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'health'])
            ->assertOk()
            ->assertSee(__('operator_website.health_score.title'))
            ->assertSeeHtml('data-health-score-value="87"')
            ->assertSeeHtml('data-health-trend')
            ->assertSee(__('operator_website.health_score.change', ['value' => '−11']))
            ->assertSeeHtml('data-health-group="HTTP_4XX"')
            ->assertSeeHtml('data-health-group="DUPLICATE_TITLE"')
            ->assertSee(__('operator_website.health_score.codes.ORPHAN_PAGE'))
            ->assertSee('https://example.com/c-old')
            ->assertSee(__('operator_website.health_score.not_scored'));
    }

    #[Test]
    public function health_tab_without_collected_data_shows_no_score(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Empty Website');

        $this->assertFalse(app(WebsiteHealthScoreService::class)->build($asset)['available']);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'health'])
            ->assertOk()
            ->assertDontSeeHtml('data-website-health-score')
            ->assertDontSee(__('operator_website.health_score.title'));
    }

    #[Test]
    public function csv_export_streams_bom_semicolon_rows_for_every_affected_url(): void
    {
        $asset = $this->seedSite();

        $component = Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'health'])
            ->call('exportHealthCsv')
            ->assertFileDownloaded(__('operator_website.health_score.csv.filename').'-'.$asset->id.'-'.now()->format('Y-m-d').'.csv');

        $content = base64_decode((string) data_get($component->effects, 'download.content'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = array_values(array_filter(explode("\n", substr($content, 3))));
        $this->assertSame([
            __('operator_website.health_score.csv.code'),
            __('operator_website.health_score.csv.issue'),
            __('operator_website.health_score.csv.severity'),
            __('operator_website.health_score.csv.scored'),
            __('operator_website.health_score.csv.url_count'),
            __('operator_website.health_score.csv.share'),
            __('operator_website.health_score.csv.new_count'),
            __('operator_website.health_score.csv.url'),
            __('operator_website.health_score.csv.detail'),
        ], str_getcsv($lines[0], ';', '"', ''));
        $this->assertSame(
            ['HTTP_4XX', __('operator.website.technical_health.issue_codes.HTTP_4XX'), __('operator_website.severity.high'), __('operator_website.health_score.csv.yes'), '1', '20,0', '1', 'https://example.com/d', 'HTTP 404'],
            str_getcsv($lines[1], ';', '"', ''),
        );
        // 1 (4xx) + 1 (redirect) + 2 (meta description) + 1 (broken link) + 1 (orphan) + 2 (duplicate title)
        $this->assertCount(1 + 8, $lines);
        $this->assertStringContainsString('MISSING_META_DESCRIPTION;', $content);
        $this->assertStringContainsString(';https://example.com/b;', $content);
        $this->assertStringContainsString('HTTP_4XX;', $content);
    }

    private function seedSite(): DigitalAsset
    {
        $asset = $this->createPortfolioAsset('website', 'Health Website', ['primary_url' => 'https://example.com']);

        // Crawl 1 (run 11): 4 pages, one low-severity issue → 98.
        foreach (['/', '/a', '/b', '/c'] as $path) {
            $this->http($asset, 'https://example.com'.$path, self::FIRST, 11);
        }
        $this->issue($asset, 'https://example.com/a', 'MISSING_META_DESCRIPTION', 'low', self::FIRST, 11);
        $this->edge($asset, 'https://example.com/', 'https://example.com/c', self::FIRST);

        // Crawl 2 (run 12): new 404 page, redirect chain, one more low issue → 87.
        foreach (['/', '/a', '/b'] as $path) {
            $this->http($asset, 'https://example.com'.$path, self::SECOND, 12);
        }
        $this->http($asset, 'https://example.com/c-old', self::SECOND, 12, 200, 'https://example.com/c/', 2);
        $this->http($asset, 'https://example.com/d', self::SECOND, 12, 404);
        $this->issue($asset, 'https://example.com/d', 'HTTP_4XX', 'high', self::SECOND, 12);
        $this->issue($asset, 'https://example.com/a', 'MISSING_META_DESCRIPTION', 'low', self::SECOND, 12);
        $this->issue($asset, 'https://example.com/b', 'MISSING_META_DESCRIPTION', 'low', self::SECOND, 12);
        $this->issue($asset, 'https://example.com/c', 'REDIRECT_CHAIN', 'medium', self::SECOND, 12);

        $this->edge($asset, 'https://example.com/', 'https://example.com/a', self::SECOND);
        $this->edge($asset, 'https://example.com/', 'https://example.com/d', self::SECOND);
        $this->edge($asset, 'https://example.com/a', 'https://example.com/b', self::SECOND);
        $this->edge($asset, 'https://example.com/b', 'https://example.com/', self::SECOND);

        $this->metadata($asset, 'https://example.com/', 'Home', self::SECOND);
        $this->metadata($asset, 'https://example.com/a', 'Hizmetler', self::SECOND);
        $this->metadata($asset, 'https://example.com/b', ' hizmetler ', self::SECOND);
        $this->metadata($asset, 'https://example.com/c', 'C sayfası', self::SECOND);

        return $asset;
    }

    /** @return array<string, mixed> */
    private function base(DigitalAsset $asset, string $observedAt, ?int $runId): array
    {
        return [
            'digital_asset_id' => $asset->id,
            'observed_at' => $observedAt,
            'contract_version' => 1,
            'last_collection_run_id' => $runId,
            'first_collected_at' => $observedAt,
            'last_collected_at' => $observedAt,
            'record_fingerprint' => hash('sha256', uniqid('', true)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function http(DigitalAsset $asset, string $url, string $observedAt, ?int $runId, int $status = 200, ?string $finalUrl = null, int $redirects = 0): void
    {
        DB::table('website_http_snapshot')->insert($this->base($asset, $observedAt, $runId) + [
            'url' => $url,
            'metadata' => json_encode([
                'requested_url' => $url,
                'final_url' => $finalUrl ?? $url,
                'status_code' => $status,
                'redirect_count' => $redirects,
                'ok' => $status < 400,
            ]),
        ]);
    }

    private function issue(DigitalAsset $asset, string $url, string $code, string $severity, string $observedAt, ?int $runId): void
    {
        DB::table('website_crawl_issue_snapshot')->insert($this->base($asset, $observedAt, $runId) + [
            'url' => $url,
            'issue_code' => $code,
            'severity' => $severity,
            'message' => $code,
            'metadata' => json_encode(['deterministic' => true]),
        ]);
    }

    private function edge(DigitalAsset $asset, string $source, string $target, string $observedAt): void
    {
        DB::table('website_link_edge')->insert($this->base($asset, $observedAt, null) + [
            'edge_key' => hash('sha256', $source.'>'.$target),
            'source_url' => $source,
            'target_url' => $target,
            'normalized_target_url' => $target,
            'is_internal' => true,
        ]);
    }

    private function metadata(DigitalAsset $asset, string $url, string $title, string $observedAt): void
    {
        DB::table('website_metadata_snapshot')->insert($this->base($asset, $observedAt, 12) + [
            'url' => $url,
            'metadata' => json_encode(['title' => $title, 'meta_description' => null]),
        ]);
    }
}
