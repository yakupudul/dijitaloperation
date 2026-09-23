<?php

namespace Tests\Feature\Assets;

use App\Contracts\GbpOperatorWorkspace;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Business Profile asset page reads the collector's gbp_* tables (not the legacy probe Evidence) and shows
 * performance with comparison, search keywords, reviews and profile completeness — also after a partial run.
 */
final class GbpAssetPageTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $location;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Örnek Profil']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->location = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'display_name' => 'Örnek Lokasyon', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $this->binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->location->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_bound_profile_without_collected_rows_asks_for_collection(): void
    {
        $data = app(GbpOperatorWorkspace::class)->for($this->asset->fresh(['brand']));

        $this->assertSame('configured', $data['migration_mode']);
        $this->assertFalse($data['performance_live']['available']);
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertSee(__('operator_gbp.collect_title'));
    }

    public function test_collected_rows_render_after_a_partial_run(): void
    {
        $run = Run::query()->create(['digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => $this->binding->id, 'module_id' => 'google-business-profile', 'status' => 'partial', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        $this->seedRows($run->id);

        $data = app(GbpOperatorWorkspace::class)->for($this->asset->fresh(['brand']));

        $this->assertSame('real', $data['migration_mode']);
        $this->assertSame('Örnek Klinik Merkez', $data['identity']['title']);
        $this->assertStringContainsString('Kadıköy', $data['identity']['location_line']);
        $this->assertSame('partial', $data['connection']['last_run_status']);

        $perf = $data['performance_live'];
        $this->assertTrue($perf['available']);
        $this->assertSame(28 * 100, $perf['metrics']['maps_views']['current']);
        $this->assertSame(28 * 2, $perf['metrics']['calls']['current']);
        $this->assertSame(28 * 4, $perf['metrics']['calls']['previous']);
        $this->assertSame(-50, $perf['metrics']['calls']['change_pct']);
        $this->assertCount(28, $perf['series']['dates']);

        $this->assertSame(['implant fiyatları', 'ortodonti'], array_column($data['keywords_live']['items'], 'keyword'));
        $this->assertSame(1, $data['keywords_live']['below_threshold_count']);

        $reviews = $data['reviews_live'];
        $this->assertSame(4.6, $reviews['average'], 'Google-reported average wins over our sample');
        $this->assertSame(3, $reviews['unanswered_recent']);
        $this->assertSame(25, $reviews['reply_rate']);

        $this->assertNotNull($data['profile']['completeness']);
        $this->assertLessThan(100, $data['profile']['completeness']['score']);
        $this->assertNotContains('performance', $data['unsupported_live_capabilities']);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertSee('Örnek Klinik Merkez')
            ->assertDontSee(__('operator_gbp.collect_title'))
            ->call('setTab', 'performance')
            ->assertSee('implant fiyatları')
            ->assertSee(__('operator_gbp.metrics.calls'))
            ->call('setTab', 'reviews')
            ->assertSee('Harika hizmet')
            ->assertSee(__('operator_gbp.not_replied'))
            ->call('setTab', 'profile')
            ->assertSee(__('operator_gbp.completeness_items.description'));
    }

    public function test_retired_tabs_redirect_and_edit_link_is_present(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'competitors'])
            ->assertSet('tab', 'overview')
            ->assertSee(route('operator.asset.edit', ['assetId' => $this->asset->id]), false);
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'queries'])
            ->assertSet('tab', 'performance');
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'days' => 45])
            ->assertSet('days', 28);
    }

    private function seedRows(int $runId): void
    {
        $base = ['digital_asset_id' => null, 'external_resource_id' => $this->location->id, 'location_name' => 'locations/1', 'created_at' => now(), 'updated_at' => now()];
        DB::table('gbp_location_snapshots')->insert(['run_id' => $runId] + $base + [
            'captured_at' => now()->subHour(), 'title' => 'Örnek Klinik Merkez', 'primary_category' => 'Diş Kliniği',
            'additional_categories' => json_encode(['additionalCategories' => [['displayName' => 'Ortodontist']]]),
            'storefront_address' => json_encode(['addressLines' => ['Moda Cad. 1'], 'locality' => 'Kadıköy', 'administrativeArea' => 'İstanbul']),
            'phone_numbers' => json_encode(['primaryPhone' => '+90 216 555 00 00']), 'website_uri' => 'https://ornek.test/',
            'regular_hours' => json_encode(['periods' => [['openDay' => 'MONDAY'], ['openDay' => 'TUESDAY']]]),
            'profile' => json_encode(['description' => 'Kısa açıklama.']), 'maps_uri' => 'https://maps.google.com/?cid=1',
            'average_rating' => 4.6, 'total_review_count' => 120,
        ]);
        $end = CarbonImmutable::parse('2026-09-21');
        for ($day = 0; $day < 56; $day++) {
            $date = $end->subDays($day)->toDateString();
            DB::table('gbp_performance_daily')->insert($base + ['run_id' => $runId, 'reporting_date' => $date, 'metric' => 'CALL_CLICKS', 'value' => $day < 28 ? 2 : 4, 'collected_at' => now()]);
            DB::table('gbp_performance_daily')->insert($base + ['run_id' => $runId, 'reporting_date' => $date, 'metric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'value' => 100, 'collected_at' => now()]);
        }
        foreach ([['implant fiyatları', 120], ['ortodonti', 90]] as [$keyword, $impressions]) {
            DB::table('gbp_search_keywords_monthly')->insert($base + ['run_id' => $runId, 'month_start' => '2026-08-01', 'search_keyword' => $keyword, 'search_keyword_hash' => hash('sha256', $keyword), 'impressions' => $impressions, 'collected_at' => now()]);
        }
        DB::table('gbp_search_keywords_monthly')->insert($base + ['run_id' => $runId, 'month_start' => '2026-08-01', 'search_keyword' => 'nadir arama', 'search_keyword_hash' => hash('sha256', 'nadir'), 'impressions' => null, 'threshold' => 15, 'collected_at' => now()]);
        foreach (range(1, 4) as $i) {
            DB::table('gbp_reviews')->insert($base + [
                'run_id' => $runId, 'review_id' => 'r'.$i, 'star_rating' => $i === 1 ? 'FIVE' : 'FOUR',
                'comment' => $i === 1 ? 'Harika hizmet' : null, 'create_time' => '2026-09-1'.$i.' 10:00:00',
                'reviewer' => json_encode(['displayName' => 'Hasta '.$i]),
                'review_reply' => $i === 4 ? json_encode(['comment' => 'Teşekkürler']) : null,
                'raw_payload' => '{}', 'collected_at' => now(),
            ]);
        }
    }
}
