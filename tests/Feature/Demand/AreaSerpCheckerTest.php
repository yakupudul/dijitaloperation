<?php

namespace Tests\Feature\Demand;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandServiceArea;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Demand\AreaSerpChecker;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AreaSerpCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private BrandServiceArea $kadikoy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $admin);

        $this->brand = $this->brand('Atlas Dental', 'atlasdis.com');
        $this->kadikoy = BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr|istanbul|kadikoy', 'status' => 'active', 'priority_rank' => 1]);
        $implant = app(BrandOfferingService::class)->create($this->brand, 'İmplant');
        foreach ([['implant fiyatları', 60], ['diş implantı', 40], ['implant tedavisi', 20]] as [$query, $value]) {
            BrandDemandQuery::query()->create(['brand_id' => $this->brand->id, 'query' => $query, 'query_key' => hash('sha256', $query), 'brand_offering_id' => $implant->id, 'value_score' => $value]);
        }
        BrandDemandQuery::query()->create(['brand_id' => $this->brand->id, 'query' => 'atlas dental', 'query_key' => hash('sha256', 'atlas dental'), 'brand_offering_id' => $implant->id, 'is_branded' => true, 'value_score' => 500]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.dataforseo.com/v3/serp/google/locations/tr' => Http::response($this->envelope([
                ['location_code' => 1001, 'location_name' => 'Kadikoy,Istanbul,Turkey', 'location_type' => 'District', 'country_iso_code' => 'TR', 'location_code_parent' => 1002],
                ['location_code' => 1002, 'location_name' => 'Istanbul,Istanbul,Turkey', 'location_type' => 'City', 'country_iso_code' => 'TR'],
            ])),
            'https://api.dataforseo.com/v3/serp/google/organic/live/regular' => Http::response($this->envelope([['items' => [
                ['type' => 'organic', 'rank_group' => 1, 'url' => 'https://www.rakipklinik.com/implant/', 'title' => 'Rakip'],
                ['type' => 'local_pack', 'rank_group' => 1, 'url' => 'https://maps.example/'],
                ['type' => 'organic', 'rank_group' => 2, 'url' => 'https://baska.com/x', 'title' => 'Başka'],
                ['type' => 'organic', 'rank_group' => 4, 'url' => 'https://atlasdis.com/implant/', 'title' => 'Biz'],
            ]]], cost: 0.002)),
        ]);
    }

    public function test_opt_in_checks_top_queries_at_the_area_location_and_proposes_repeating_competitors(): void
    {
        $this->brand->forceFill(['demand_serp_enabled' => true, 'demand_serp_monthly_usd' => 1.0])->save();

        $stats = app(AreaSerpChecker::class)->run($this->brand);

        $this->assertSame(3, $stats['checked'], 'three non-branded implant queries, branded skipped');
        $this->assertSame(1001, (int) $this->kadikoy->fresh()->dataforseo_location_code, 'district resolved from the free directory');
        $check = DB::table('demand_serp_checks')->where('keyword', 'implant fiyatları')->first();
        $this->assertSame(1001, (int) $check->location_code);
        $this->assertSame(4, (int) $check->our_rank, 'DataForSEO organic rank_group');
        $this->assertCount(3, json_decode($check->results, true));
        $competitor = SearchDemandCompetitor::query()->where('brand_id', $this->brand->id)->where('normalized_domain', 'rakipklinik.com')->firstOrFail();
        $this->assertSame('pending', $competitor->status);
        $this->assertTrue($competitor->is_serp_competitor);
        $this->assertFalse(SearchDemandCompetitor::query()->where('normalized_domain', 'atlasdis.com')->exists(), 'own domain never a competitor');

        Http::assertSentCount(4); // directory + 3 SERP calls
        app(AreaSerpChecker::class)->run($this->brand->fresh());
        Http::assertSentCount(4); // fresh results: nothing new is paid for
    }

    public function test_budget_cap_and_cross_brand_reuse(): void
    {
        $this->brand->forceFill(['demand_serp_enabled' => true, 'demand_serp_monthly_usd' => 0.003])->save();
        $stats = app(AreaSerpChecker::class)->run($this->brand);
        $this->assertSame(1, $stats['checked']);
        $this->assertSame(2, $stats['skipped_budget'], 'the monthly cap is never exceeded');

        $other = $this->brand('Başka Diş', 'baskadis.com');
        BrandServiceArea::query()->create(['brand_id' => $other->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr|istanbul|kadikoy', 'status' => 'active', 'priority_rank' => 1]);
        $offering = app(BrandOfferingService::class)->create($other, 'İmplant');
        BrandDemandQuery::query()->create(['brand_id' => $other->id, 'query' => 'implant fiyatları', 'query_key' => hash('sha256', 'implant fiyatları'), 'brand_offering_id' => $offering->id, 'value_score' => 10]);
        $other->forceFill(['demand_serp_enabled' => true])->save();
        $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'live/regular'))->count();

        $reuse = app(AreaSerpChecker::class)->run($other);

        $this->assertSame(1, $reuse['reused']);
        $this->assertSame($sent, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'live/regular'))->count(), 'same keyword + location reused across brands');
    }

    public function test_disabled_brand_makes_no_call(): void
    {
        $stats = app(AreaSerpChecker::class)->run($this->brand);

        $this->assertSame(0, $stats['planned']);
        Http::assertNothingSent();
    }

    private function brand(string $name, string $domain): Brand
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => $name]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'primary_url' => 'https://'.$domain.'/', 'domain' => $domain]);

        return $brand;
    }

    /** @param  list<array<string, mixed>>  $result */
    private function envelope(array $result, float $cost = 0.0): array
    {
        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [['id' => 't1', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'result' => $result]]];
    }
}
