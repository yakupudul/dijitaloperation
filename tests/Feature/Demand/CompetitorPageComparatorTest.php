<?php

namespace Tests\Feature\Demand;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Demand\CompetitorPageComparator;
use App\Services\Demand\DemandPageFetcher;
use App\Services\Demand\PageContentMetrics;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CompetitorPageComparatorTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $website;

    private BrandOffering $implant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental', 'demand_serp_enabled' => true]);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr|istanbul|kadikoy', 'status' => 'active', 'priority_rank' => 1]);
        $this->implant = app(BrandOfferingService::class)->create($this->brand, 'İmplant');

        DB::table('demand_serp_checks')->insert([
            'brand_id' => $this->brand->id, 'brand_offering_id' => $this->implant->id, 'keyword' => 'implant fiyatları', 'location_code' => 1001,
            'language_code' => 'tr', 'fingerprint' => str_repeat('a', 64), 'status' => 'completed', 'cost_usd' => 0.002,
            'our_rank' => 14, 'our_url' => 'https://atlasdis.com/implant/',
            'results' => json_encode([
                ['rank' => 1, 'domain' => 'rakip1.com', 'url' => 'https://rakip1.com/implant/', 'title' => 'R1'],
                ['rank' => 2, 'domain' => 'rakip2.com', 'url' => 'https://rakip2.com/implant-tedavisi/', 'title' => 'R2'],
                ['rank' => 8, 'domain' => 'uzak.com', 'url' => 'https://uzak.com/x', 'title' => 'far'],
            ]),
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The real fetcher resolves DNS (SSRF guard); here pages come straight from the HTTP fake.
        $this->app->instance(DemandPageFetcher::class, new class extends DemandPageFetcher
        {
            public function __construct() {}

            public function fetch(string $url): array
            {
                $response = Http::get($url);

                return ['status_code' => $response->status(), 'html' => $response->body(), 'final_url' => $url, 'error' => null];
            }
        });
        Http::preventStrayRequests();
        Http::fake([
            'https://atlasdis.com/implant/' => Http::response($this->page(120, h2: 1), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'https://rakip1.com/implant/' => Http::response($this->page(900, h2: 7, faq: true, price: true, place: 'Kadıköy'), 200, ['Content-Type' => 'text/html']),
            'https://rakip2.com/implant-tedavisi/' => Http::response($this->page(700, h2: 6, faq: true, price: true, place: 'Kadıköy'), 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_service_page_is_compared_with_outranking_pages_and_gaps_are_stored(): void
    {
        $stats = app(CompetitorPageComparator::class)->run($this->brand);

        $this->assertSame(1, $stats['compared']);
        $row = DB::table('demand_service_comparisons')->where('brand_offering_id', $this->implant->id)->sole();
        $gaps = array_column(json_decode($row->gaps, true), 'key');
        $this->assertEqualsCanonicalizing(['content_depth', 'structure', 'faq', 'schema', 'price', 'area'], $gaps);
        $this->assertSame(14, (int) $row->our_rank);
        $this->assertSame(['rakip1.com', 'rakip2.com'], array_column(json_decode($row->competitors, true), 'domain'), 'only top-5 pages that outrank us');

        Http::assertSentCount(3);
        app(CompetitorPageComparator::class)->run($this->brand);
        Http::assertSentCount(3); // pages reused for 28 days
    }

    public function test_gap_becomes_a_seo_task_for_the_service(): void
    {
        app(CompetitorPageComparator::class)->run($this->brand);

        $input = app(SeoPlanInputCollector::class)->collect($this->website);
        $this->assertArrayHasKey($this->implant->id, $input['competitor_gaps']);
        $task = collect(app(SeoTaskRuleEngine::class)->evaluate($input)['tasks'])->firstWhere('rule_id', 'competitor-gap');

        $this->assertNotNull($task);
        $this->assertSame('high', $task['severity'], 'not in the top 10');
        $this->assertSame($this->implant->id, $task['brand_offering_id']);
        $this->assertSame('https://atlasdis.com/implant/', $task['target_url']);
        $this->assertCount(6, $task['checklist']);
    }

    public function test_page_metrics(): void
    {
        $metrics = PageContentMetrics::from('https://a.com/x', $this->page(50, h2: 3, faq: true, price: true, place: 'Kadıköy'));

        $this->assertTrue($metrics['faq']);
        $this->assertTrue($metrics['has_price']);
        $this->assertSame(3, $metrics['h2_count']);
        $this->assertContains('FAQPage', $metrics['schema_types']);
        $this->assertStringContainsString('kadikoy', $metrics['text_folded']);
    }

    private function page(int $words, int $h2 = 0, bool $faq = false, bool $price = false, ?string $place = null): string
    {
        $body = '<h1>İmplant</h1>';
        for ($i = 1; $i <= $h2; $i++) {
            $body .= '<h2>Bölüm '.$i.'</h2>';
        }
        $body .= '<p>'.str_repeat('kelime ', $words).'</p>';
        if ($price) {
            $body .= '<p>İmplant fiyatları hastaya göre değişir.</p>';
        }
        if ($place !== null) {
            $body .= '<p>'.$place.' kliniğimiz</p>';
        }
        $schema = $faq ? '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[]}</script>' : '';

        return '<html><head><title>İmplant</title>'.$schema.'</head><body>'.$body.'<a href="/iletisim">İletişim</a></body></html>';
    }
}
