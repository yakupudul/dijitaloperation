<?php

namespace Tests\Feature\MetaAds;

use App\Ai\Agents\Insights\MetaGeoAgent;
use App\Enums\DigitalAssetStatus;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaGeoResults;
use App\Services\MetaAds\MetaGeoResultsReader;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Meta country + city results: collection, country → city summary, audience tab table and the AI geo insight. */
final class MetaGeoResultsTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'UTC'));
        app()->setLocale('tr');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Atlas Meta']);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read', 'business_management']]]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT, 'external_id' => 'act_777',
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_country_and_region_rows_are_stored_with_results_and_summarised(): void
    {
        $this->fakeInsights();

        $stored = app(MetaGeoResults::class)->collect($this->asset, 3);

        $this->assertSame(6, $stored);
        $istanbul = DB::table('meta_geo_results_daily')->where('level', 'region')->where('region', 'Istanbul')->first();
        $this->assertSame('TR', $istanbul->country, 'the ad delivered only in Türkiye that day');
        $this->assertSame(6.0, (float) $istanbul->leads, 'lead aliases are not double counted');
        $this->assertSame('Implant – İstanbul – 35+', $istanbul->adset_name);
        $berlin = DB::table('meta_geo_results_daily')->where('level', 'region')->where('region', 'Berlin')->first();
        $this->assertSame('', $berlin->country, 'an ad in two countries cannot place its cities');

        $summary = app(MetaGeoResultsReader::class)->summary($this->asset->id, '2026-09-01', '2026-09-30');
        $this->assertTrue($summary['has_data']);
        $this->assertSame('TR', $summary['countries'][0]['country']);
        $this->assertSame(['Istanbul', 'Ankara'], array_column($summary['countries'][0]['regions'], 'region'));
        $this->assertSame(50.0, $summary['countries'][0]['regions'][0]['cost_per_result']);
        $this->assertSame('', end($summary['countries'])['country'], 'the unplaced cities come last');

        app(MetaGeoResults::class)->collect($this->asset, 3);
        $this->assertSame(6, DB::table('meta_geo_results_daily')->count(), 'a re-collection replaces the window');
    }

    public function test_audience_tab_shows_country_city_table_ai_button_and_queues_collection(): void
    {
        $this->fakeInsights();
        app(MetaGeoResults::class)->collect($this->asset, 3);

        $this->get(route('operator.meta.overview', ['assetId' => $this->asset->id, 'tab' => 'audience', 'period' => 'last_28']))
            ->assertOk()->assertSee('Ülke ve şehir performansı')->assertSee('Türkiye')->assertSee('Istanbul')
            ->assertSee('Birden fazla ülke')->assertSee('✨ AI');

        Queue::fake();
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'audience'])->call('collectGeoResults');
        Queue::assertPushed(CollectMetaGeoResultsJob::class, fn ($job): bool => $job->assetId === $this->asset->id && $job->days === 90);
    }

    public function test_ai_geo_insight_reads_names_cities_and_targeting(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->fakeInsights();
        app(MetaGeoResults::class)->collect($this->asset, 3);
        DB::table('meta_adset_targeting_snapshot')->insert([
            'digital_asset_id' => $this->asset->id, 'external_resource_id' => 1, 'account_id' => '777', 'adset_id' => 'as1', 'adset_name' => 'Implant – İstanbul – 35+',
            'optimization_goal' => 'LEAD_GENERATION', 'targeting' => json_encode(['age_min' => 35, 'age_max' => 55, 'genders' => [2], 'flexible_spec' => [['interests' => [['id' => '1', 'name' => 'Dental implant']]]]]),
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'as1'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        MetaGeoAgent::fake([['summary' => 'En iyi: implant, İstanbul, 35-55 kadın.', 'items' => [['title' => 'İmplant · İstanbul · 35-55 kadın — 6 lead, 50 TL/lead', 'detail' => 'Bütçe artırılabilir.', 'tag' => 'winner']]]]);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'audience'])
            ->call('runInsight', 'meta.geo_results', $this->asset->id)
            ->assertSee('En iyi: implant, İstanbul, 35-55 kadın.')
            ->assertSee('İyi çalışıyor');

        $this->assertSame(1, AiProduction::query()->where('kind', 'meta.geo_results')->count());
        MetaGeoAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Dental implant')
            && str_contains((string) $prompt->prompt, 'Istanbul') && str_contains((string) $prompt->prompt, 'Implant'));

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'audience'])
            ->call('runInsight', 'alerts.cause', $this->asset->id);
        $this->assertSame(1, AiProduction::query()->count(), 'the page only allows its own insight kind');
    }

    private function fakeInsights(): void
    {
        $base = ['campaign_id' => 'c1', 'campaign_name' => 'Implant Lead', 'adset_id' => 'as1', 'adset_name' => 'Implant – İstanbul – 35+', 'account_currency' => 'TRY', 'date_start' => '2026-09-20'];
        $leads = [['action_type' => 'lead', 'value' => '6'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '6']];
        $this->mock(MetaApiClient::class, function ($mock) use ($base, $leads): void {
            $mock->shouldReceive('get')->andReturnUsing(function ($integration, string $path, array $query) use ($base, $leads): array {
                if ($query['breakdowns'] === 'country') {
                    return ['data' => [
                        $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'country' => 'TR', 'spend' => '400', 'impressions' => '9000', 'clicks' => '120', 'actions' => $leads],
                        $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'country' => 'DE', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                        $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'country' => 'TR', 'spend' => '20', 'impressions' => '300', 'clicks' => '2'],
                    ]];
                }

                return ['data' => [
                    $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'region' => 'Istanbul', 'spend' => '300', 'impressions' => '7000', 'clicks' => '100', 'actions' => $leads],
                    $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'region' => 'Ankara', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                    $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'region' => 'Berlin', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                ]];
            });
        });
    }
}
