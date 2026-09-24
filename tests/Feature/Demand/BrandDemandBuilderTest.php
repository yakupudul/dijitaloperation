<?php

namespace Tests\Feature\Demand;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Portfolio\BrandDemand;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Demand\BrandDemandBuilder;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BrandDemandBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $website;

    private BrandOffering $implant;

    private BrandOffering $zirkonyum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'normalized_key' => 'tr|istanbul', 'status' => 'active', 'priority_rank' => 2]);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr|istanbul|kadikoy', 'status' => 'active', 'priority_rank' => 1]);
        $offerings = app(BrandOfferingService::class);
        $this->implant = $offerings->create($this->brand, 'İmplant');
        $this->zirkonyum = $offerings->create($this->brand, 'Zirkonyum Kaplama');
        $offerings->create($this->brand, 'Diş Kliniği');
    }

    public function test_queries_from_all_sources_get_service_area_brand_and_value(): void
    {
        $this->gsc('implant fiyatları', 10, 200);
        $this->gsc('kadıköy diş implantı', 5, 100);
        $this->gsc('atlas dental yorumlar', 30, 300);
        $this->gsc('ankara implant', 1, 50);
        $this->gsc('zirkonyum kaplamalar', 2, 40);
        $this->gsc('rastgele bir sorgu', 0, 10);
        $this->ads('implant fiyatları', clicks: 4, conversions: 2);
        $this->gbp('diş kliniği kadıköy', 120);

        $stats = app(BrandDemandBuilder::class)->build($this->brand);

        $this->assertSame(7, $stats['queries']);
        $rows = BrandDemandQuery::query()->where('brand_id', $this->brand->id)->get()->keyBy('query');
        $implant = $rows['implant fiyatları'];
        $this->assertSame($this->implant->id, $implant->brand_offering_id, 'suffix-tolerant: fiyatları');
        $this->assertEqualsCanonicalizing(['search_console', 'google_ads'], $implant->sources);
        $this->assertSame(4, (int) $implant->ads_clicks);
        $this->assertEqualsWithDelta(10 + 200 * 0.05 + 4 * 0.5 + 2 * 20, $implant->value_score, 0.01);

        $kadikoy = $rows['kadıköy diş implantı'];
        $this->assertSame($this->implant->id, $kadikoy->brand_offering_id);
        $this->assertSame('in_area', $kadikoy->location_status);
        $this->assertSame('Kadıköy', $kadikoy->serviceArea->district_name, 'most specific area wins');

        $this->assertTrue($rows['atlas dental yorumlar']->is_branded);
        $this->assertFalse($implant->is_branded);
        $this->assertSame('out_of_area', $rows['ankara implant']->location_status);
        $this->assertSame($this->zirkonyum->id, $rows['zirkonyum kaplamalar']->brand_offering_id);
        $this->assertNull($rows['rastgele bir sorgu']->brand_offering_id);
        $this->assertSame(['google_business_profile'], $rows['diş kliniği kadıköy']->sources);
        $this->assertSame(120, (int) $rows['diş kliniği kadıköy']->gbp_impressions);
    }

    public function test_operator_assignment_survives_and_unseen_queries_are_kept(): void
    {
        $this->gsc('implant fiyatları', 10, 200);
        $this->gsc('eski sorgu', 3, 30);
        app(BrandDemandBuilder::class)->build($this->brand);
        BrandDemandQuery::query()->where('query', 'implant fiyatları')->update(['brand_offering_id' => $this->zirkonyum->id, 'assignment_source' => BrandDemandQuery::SOURCE_OPERATOR]);

        DB::table('gsc_query_daily')->where('query', 'eski sorgu')->delete();
        $this->travel(1)->seconds();
        app(BrandDemandBuilder::class)->build($this->brand);

        $this->assertSame($this->zirkonyum->id, BrandDemandQuery::query()->where('query', 'implant fiyatları')->value('brand_offering_id'));
        $old = BrandDemandQuery::query()->where('query', 'eski sorgu')->firstOrFail();
        $this->assertSame(0, (int) $old->gsc_clicks, 'window metrics cleared');
        $this->assertSame(0.0, $old->value_score);
    }

    public function test_command_skips_passive_customers(): void
    {
        $this->gsc('implant fiyatları', 10, 200);
        $this->brand->customer->update(['status' => CustomerStatus::Inactive]);

        $this->artisan('moxdop:demand:build')->assertSuccessful();

        $this->assertSame(0, BrandDemandQuery::query()->count());
    }

    public function test_brand_page_section_shows_services_and_assigns_by_hand(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->gsc('implant fiyatları', 10, 200);
        $this->gsc('gülüş tasarımı', 4, 80);
        app(BrandDemandBuilder::class)->build($this->brand);
        $unassigned = BrandDemandQuery::query()->where('query', 'gülüş tasarımı')->firstOrFail();

        Livewire::test(BrandDemand::class, ['brandId' => $this->brand->id])
            ->assertSee('İmplant')
            ->assertSee('gülüş tasarımı')
            ->call('assign', $unassigned->id, (string) $this->zirkonyum->id);

        $this->assertSame($this->zirkonyum->id, $unassigned->fresh()->brand_offering_id);
        $this->assertSame(BrandDemandQuery::SOURCE_OPERATOR, $unassigned->fresh()->assignment_source);
    }

    private function gsc(string $query, int $clicks, int $impressions): void
    {
        DB::table('gsc_query_daily')->insert([
            'digital_asset_id' => $this->website->id, 'site_url' => 'sc-domain:atlasdis.com', 'reporting_date' => now()->subDays(5)->toDateString(),
            'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }

    private function ads(string $term, int $clicks, int $conversions): void
    {
        DB::table('google_ads_search_term_daily')->insert([
            'digital_asset_id' => $this->website->id, 'customer_id' => '123', 'reporting_date' => now()->subDays(3)->toDateString(),
            'search_term' => $term, 'impressions' => 50, 'clicks' => $clicks, 'cost_micros' => 1_000_000, 'conversions' => $conversions,
            'cost_amount' => 1, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20),
        ]);
    }

    private function gbp(string $keyword, int $impressions): void
    {
        DB::table('gbp_search_keywords_monthly')->insert([
            'digital_asset_id' => $this->website->id, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1',
            'month_start' => now()->startOfMonth()->toDateString(), 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword),
            'impressions' => $impressions, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
