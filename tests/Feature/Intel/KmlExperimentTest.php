<?php

namespace Tests\Feature\Intel;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Market\MapRankingsPage;
use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridRun;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Intel\KmlBuilder;
use App\Services\Intel\ServiceAreaGeocoder;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8b: KML pin file (geocoded service areas, informative description, cap) and the before / after comparison.
 */
final class KmlExperimentTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        config(['moxdop-intel.kml.geocoder_delay_ms' => 0]);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'domain' => 'atlasdis.com']);
        app(BrandOfferingService::class)->create($this->brand, 'İmplant');
        foreach (['Kadıköy' => 1, 'Üsküdar' => 2, 'Ataşehir' => 3] as $district => $rank) {
            BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => $district, 'normalized_key' => 'tr|istanbul|'.$rank, 'status' => 'active', 'priority_rank' => $rank]);
        }
    }

    public function test_geocoding_and_kml_file(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'nominatim.openstreetmap.org/*' => function ($request) {
                return str_contains(urldecode($request->url()), 'Ataşehir') ? Http::response([]) : Http::response([['lat' => '40.99', 'lon' => '29.03']]);
            },
        ]);
        $this->assertSame(['found' => 2, 'missed' => 1], app(ServiceAreaGeocoder::class)->geocodeBrand($this->brand));
        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'MoxDOP'));
        $this->assertSame(['found' => 0, 'missed' => 0], app(ServiceAreaGeocoder::class)->geocodeBrand($this->brand), 'a miss is not retried for 30 days');

        $result = app(KmlBuilder::class)->build($this->brand);
        $this->assertSame(2, $result['pins'], 'no profile pin; two geocoded areas');
        $this->assertStringContainsString('<name>Atlas Dental – Kadıköy</name>', $result['kml']);
        $this->assertStringContainsString('İmplant — Kadıköy ve çevresine hizmet verir.', $result['kml']);
        $this->assertStringContainsString('<coordinates>29.0300000,40.9900000,0</coordinates>', $result['kml']);
        $this->assertStringContainsString('Web: https://atlasdis.com', $result['kml']);
        $this->assertTrue(collect($result['warnings'])->contains(fn (string $w): bool => str_contains($w, '1 hizmet bölgesinin konumu yok')));
        $this->assertTrue(collect($result['warnings'])->contains(fn (string $w): bool => str_contains($w, 'Profili pini yok')));
        $this->assertNotFalse(simplexml_load_string($result['kml']), 'valid XML');

        BrandIntelSetting::for($this->brand)->fill(['kml_pin_limit' => 1])->save();
        $this->assertSame(1, app(KmlBuilder::class)->build($this->brand)['pins'], 'pin cap');

        $this->get(route('operator.market.kml', ['brand' => $this->brand->id]))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.google-earth.kml+xml; charset=UTF-8')
            ->assertSee('Atlas Dental – Kadıköy', false);
    }

    public function test_experiment_compares_scans_around_the_publish_date(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $run = fn (int $daysAgo, float $atrp, float $solv) => MapGridRun::query()->create([
            'brand_id' => $this->brand->id, 'keyword' => 'implant kadıköy', 'center_lat' => 41, 'center_lng' => 29, 'grid_size' => 3, 'spacing_km' => 1,
            'status' => MapGridRun::STATUS_COMPLETED, 'points_total' => 9, 'points_done' => 9, 'atrp' => $atrp, 'solv' => $solv, 'started_at' => now()->subDays($daysAgo),
        ]);
        $run(30, 12, 10);
        $run(20, 10, 20);
        $run(3, 6, 50);
        $run(90, 21, 0);

        Livewire::test(MapRankingsPage::class, ['brand' => $this->brand->id])->call('toggleExperiment')->assertSee('Deney başlangıcı');
        $this->travel(-10)->days();
        BrandIntelSetting::for($this->brand)->fill(['kml_experiment_started_on' => now()->toDateString()])->save();
        $this->travel(10)->days();

        $rows = app(KmlBuilder::class)->experiment($this->brand);
        $this->assertSame([['keyword' => 'implant kadıköy', 'before' => ['runs' => 2, 'atrp' => 11.0, 'solv' => 15.0], 'after' => ['runs' => 1, 'atrp' => 6.0, 'solv' => 50.0]]], $rows, 'scans older than 42 days before are left out');
        Livewire::test(MapRankingsPage::class, ['brand' => $this->brand->id])->assertSee('Harita pinleri (KML)')->assertSee('%50');
    }
}
