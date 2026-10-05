<?php

namespace Tests\Feature\Performance;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Brand page (Özet) query count: the asset list and the KPI numbers are read in batches, so more assets, İşletme
 * Profilleri and bound accounts do not add queries, with the KPI cache warm or cold. A website still reads its own
 * Search Console / GA4 window and totals once (no repeated binding / last-day lookups).
 */
final class BrandPageQueryCountTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        // The account activity lookup of DataStatusReader is read per bound source (its own change); it is stubbed
        // here so the test measures the brand page code itself.
        $this->app->instance(DataStatusReader::class, new class extends DataStatusReader
        {
            public function activityFor(DigitalAsset $asset, string $capability, ?int $externalResourceId): ?string
            {
                return null;
            }
        });
        $customer = Customer::factory()->create(['name' => 'Panorama Sağlık A.Ş.', 'status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        $this->website(1);
        $this->gbp(1);
        $this->asset('instagram', 'Panorama Instagram');
    }

    public function test_more_assets_profiles_and_bindings_add_no_queries_with_warm_or_cold_kpis(): void
    {
        $this->render(); // one-time lookups (permissions cache, schema checks) are not per asset
        $before = $this->measure();
        $assetsTab = $this->render('varliklar');

        foreach (range(2, 5) as $i) {
            $this->gbp($i);
            $this->asset('instagram', 'Ek hesap '.$i);
        }
        $unbound = $this->asset('google_business_profile', 'Bağlanmamış profil');
        $after = $this->measure();

        $this->assertSame($before['warm'], $after['warm'], 'warm KPI cache: no per-asset / per-binding queries');
        $this->assertSame($before['cold'], $after['cold'], 'cold KPI cache: İşletme Profilleri are read in one batch');
        $this->assertGreaterThan($before['warm'], $before['cold'], 'the cold run really computed the KPIs');
        $this->assertSame($assetsTab, $this->render('varliklar'), 'Dijital varlıklar tab: no per-asset queries');

        $kpis = collect($this->page()->viewData('kpis'))->keyBy('key');
        // Five profiles, each 2 calls a day in the current 28 days and 1 a day in the 28 before.
        $this->assertSame(['ok', '280', 100], [$kpis['gbp_actions']['state'], $kpis['gbp_actions']['value'], $kpis['gbp_actions']['delta']]);

        $assets = collect(app(BrandWorkspaceReadService::class)->assets($this->brand->fresh()))->keyBy('id');
        $this->assertSame('Google Business Profile', $assets[$unbound->id]['type_label']);
        $this->assertSame(route('operator.gbp', ['assetId' => $unbound->id]), $assets[$unbound->id]['url']);
        $this->assertSame(OperatorPortfolioPresenter::asset($unbound)['type_label'], $assets[$unbound->id]['type_label']);
    }

    public function test_a_website_reads_its_window_and_totals_once(): void
    {
        $this->render();
        $before = $this->measure();

        $this->website(2);
        $after = $this->measure();

        $this->assertSame($before['warm'], $after['warm'], 'warm KPI cache: a website adds no queries');
        // Cold: its two bindings (Search Console, GA4) once, its last Search Console day once, its totals (current +
        // previous period × Search Console + GA4) once. Before this change bindings and last day were read twice (14).
        $this->assertLessThanOrEqual(7, $after['cold'] - $before['cold'], 'cold KPI cache: one window + totals per website');

        $kpis = collect($this->page()->viewData('kpis'))->keyBy('key');
        $this->assertSame(['60', 100], [$kpis['organic_clicks']['value'], $kpis['organic_clicks']['delta']]);
        $this->assertSame(['400', 100], [$kpis['sessions']['value'], $kpis['sessions']['delta']]);
    }

    /**
     * Query counts of one brand page render with an empty cache (the KPI numbers and the site readers' totals are
     * computed) and right after it, with the KPI cache filled.
     *
     * @return array{cold: int, warm: int}
     */
    private function measure(): array
    {
        Cache::flush();
        $cold = $this->render();
        $warm = $this->render();

        return ['cold' => $cold, 'warm' => $warm];
    }

    /** Query count of one brand page render as a new request (per-request memos such as ServiceScope start empty). */
    private function render(string $tab = 'ozet'): int
    {
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page($tab);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function page(string $tab = 'ozet'): Testable
    {
        return Livewire::withQueryParams(['tab' => $tab])->test(BrandShow::class, ['brand' => (string) $this->brand->id]);
    }

    private function asset(string $type, string $name): DigitalAsset
    {
        return DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'name' => $name, 'status' => DigitalAssetStatus::Active]);
    }

    private function bind(DigitalAsset $asset, CoreExternalResource $resource, string $capability): void
    {
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability,
            'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    /** A website with Search Console (30 clicks now, 15 before) and GA4 (200 sessions now, 100 before). */
    private function website(int $n): DigitalAsset
    {
        $site = $this->asset('website', 'site'.$n.'.example');
        $gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'sc-domain:site'.$n.'.example']);
        $ga4 = CoreExternalResource::factory()->create(['display_name' => 'GA4 '.$n]);
        $this->bind($site, $gsc, 'search_console');
        $this->bind($site, $ga4, 'ga4');
        foreach ([['2026-10-12', 30], ['2026-09-10', 15]] as [$date, $clicks]) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:site'.$n.'.example',
                'search_type' => 'web', 'reporting_date' => $date, 'query' => 'implant ankara', 'page' => 'https://site'.$n.'.example/implant/', 'clicks' => $clicks,
                'impressions' => $clicks * 10, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => 4.0])]);
        }
        foreach ([['2026-10-05', 200], ['2026-09-01', 100]] as [$date, $sessions]) {
            $this->insertFacts('ga4_landing_source_daily', ['digital_asset_id' => null, 'external_resource_id' => $ga4->id, 'property_id' => 'properties/'.$n,
                'reporting_date' => $date, 'landingPage' => '/implant/', 'sessionSource' => 'google', 'sessionMedium' => 'organic', 'sessions' => $sessions,
                'keyEvents' => 1, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }

        return $site;
    }

    /** A bound İşletme Profili with 56 days of call clicks up to 2026-10-10: 2 a day now, 1 a day before. */
    private function gbp(int $n): DigitalAsset
    {
        $asset = $this->asset('google_business_profile', 'Panorama Şube '.$n);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/'.$n,
            'display_name' => 'Şube '.$n, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $this->bind($asset, $resource, 'google_business_profile');
        $rows = [];
        for ($day = 0; $day < 56; $day++) {
            $rows[] = ['external_resource_id' => $resource->id, 'digital_asset_id' => $asset->id, 'reporting_date' => CarbonImmutable::parse('2026-10-10')->subDays($day)->toDateString(),
                'metric' => 'CALL_CLICKS', 'run_id' => $n, 'location_name' => 'locations/'.$n, 'value' => $day < 28 ? 2 : 1, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        }
        $this->insertFacts('gbp_performance_daily', $rows);

        return $asset;
    }
}
