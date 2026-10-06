<?php

namespace Tests\Feature\Portfolio;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\DataStatus\DataStatusReader;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Brand "Özet" KPI cache: the numbers are kept until the data changes (a source's finished collection: run
 * finished_at or automation last success; a new binding; a new day in Europe/Istanbul), not recomputed every 10
 * minutes. A source whose access breaks or is renewed is read again at once. A revised day inside an unchanged window
 * shows once its collection finished (the website reader's own 1-hour cache is not in the way), and a Google Ads
 * account's window moves at its own midnight.
 */
final class BrandOverviewKpiCacheTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $site;

    private CoreExternalResource $gsc;

    private CoreExternalResource $ga4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        // Same stub as BrandPageQueryCountTest: the per-source activity lookup is not what is measured here.
        $this->app->instance(DataStatusReader::class, new class extends DataStatusReader
        {
            public function activityFor(DigitalAsset $asset, string $capability, ?int $externalResourceId): ?string
            {
                return null;
            }
        });
        $customer = Customer::factory()->create(['name' => 'Panorama Sağlık A.Ş.', 'status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        $this->website();
    }

    public function test_the_numbers_stay_cached_while_no_new_data_arrives(): void
    {
        ['cold' => $cold, 'warm' => $warm] = $this->measure();
        $this->assertGreaterThan($warm, $cold, 'the cold run really computed the KPIs');

        $this->travel(30)->minutes();
        $this->assertSame($warm, $this->render(), '30 minutes later nothing new was collected: the cached numbers (they were recomputed every 10 minutes)');
        $this->travel(6)->hours();
        $this->assertSame($warm, $this->render(), 'still the same day and no new collection');
        $this->assertSame(['200', 100], [$this->kpis()['sessions']['value'], $this->kpis()['sessions']['delta']]);
    }

    public function test_a_finished_collection_reads_the_numbers_again(): void
    {
        ['cold' => $cold, 'warm' => $warm] = $this->measure();

        ResourceAutomation::query()->where('external_resource_id', $this->ga4->id)->update(['last_collection_success_at' => now()]);
        $this->assertSame($cold, $this->render(), 'GA4 collected again (automation last success): computed again');
        $this->assertSame($warm, $this->render());

        CollectionResourceRun::query()->where('external_resource_id', $this->gsc->id)->update(['finished_at' => now()]);
        $this->assertSame($cold, $this->render(), 'Search Console run finished later: computed again');
        $this->assertSame($warm, $this->render());
    }

    public function test_a_revised_ga4_day_shows_once_its_collection_finished(): void
    {
        $this->assertSame('200', $this->kpis()['sessions']['value']);
        // The website screen fills the reader's own 1-hour totals cache for the same window.
        $this->assertSame(200, app(SiteAnalysisReader::class)->totals($this->site, 28)['current']['sessions']);

        // GA4 revises a day inside the window (the last Search Console day, so the window, does not move).
        DB::table('ga4_landing_source_daily')->where('external_resource_id', $this->ga4->id)->where('reporting_date', '2026-10-05')->update(['sessions' => 5200]);
        $this->assertSame('200', $this->kpis()['sessions']['value'], 'not collected yet as far as MoxDOP knows: the cached numbers');

        ResourceAutomation::query()->where('external_resource_id', $this->ga4->id)->update(['last_collection_success_at' => now()]);
        $this->assertSame(['5.200', 5100], [$this->kpis()['sessions']['value'], $this->kpis()['sessions']['delta']],
            'after the collection the KPI reads the facts, not the reader\'s older 1-hour totals');
    }

    public function test_the_google_ads_window_moves_at_istanbul_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:50:00', 'Europe/Istanbul'));
        $this->googleAds('Europe/Istanbul');
        ['cold' => $cold, 'warm' => $warm] = $this->measure();
        // 30 a day for the 40 days up to yesterday (10-14): 28 days = 840, the 28 before = 12 days = 360.
        $this->assertSame(['₺840', 'önceki 28 gün: ₺360'], [$this->kpis()['ad_spend']['value'], $this->kpis()['ad_spend']['note']]);

        $this->travel(9)->minutes();
        $this->assertSame($warm, $this->render(), '23:59: still the same day');

        $this->travel(11)->minutes();
        $this->assertSame($cold, $this->render(), 'past midnight in Istanbul: computed again');
        // The window now ends on 10-15 (not collected yet): 27 days = 810; the 28 before = 13 days = 390.
        $this->assertSame(['₺810', 'önceki 28 gün: ₺390'], [$this->kpis()['ad_spend']['value'], $this->kpis()['ad_spend']['note']]);
    }

    public function test_a_google_ads_account_in_another_time_zone_moves_at_its_own_midnight(): void
    {
        // 06:50 in Istanbul is 23:50 in New York (UTC-4): the Istanbul day does not change at New York's midnight.
        $this->travelTo(CarbonImmutable::parse('2026-10-16 06:50:00', 'Europe/Istanbul'));
        $this->googleAds('America/New_York');
        $this->measure();
        $this->assertSame('₺840', $this->kpis()['ad_spend']['value']);

        $this->travel(20)->minutes();
        $this->assertSame(['₺810', 'önceki 28 gün: ₺390'], [$this->kpis()['ad_spend']['value'], $this->kpis()['ad_spend']['note']],
            'the cached numbers expire at the account\'s own midnight');
    }

    public function test_a_reauthorized_account_shows_its_numbers_without_waiting_for_a_collection(): void
    {
        $this->googleAds('Europe/Istanbul');
        $integration = CoreIntegration::query()->where('provider', 'google')->firstOrFail();
        $config = $integration->config;
        $integration->update(['config' => ['auth_status' => GoogleAuthStatus::REFRESH_REQUIRED] + $config]);
        $this->assertSame('not_bound', $this->kpis()['ad_spend']['state'], 'the token must be renewed: the account cannot be read');

        $integration->update(['config' => $config]);
        $this->assertSame(['ok', '₺840'], [$this->kpis()['ad_spend']['state'], $this->kpis()['ad_spend']['value']],
            'renewed: the numbers come back at once, not after the next collection or 12 hours');
    }

    /**
     * Query counts of a render with an empty cache and right after it (one-time lookups warmed first).
     *
     * @return array{cold: int, warm: int}
     */
    private function measure(): array
    {
        $this->render();
        Cache::flush();
        $cold = $this->render();
        $warm = $this->render();

        return ['cold' => $cold, 'warm' => $warm];
    }

    /** Query count of one brand page render as a new request. */
    private function render(): int
    {
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id]);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @return Collection<string, array<string, mixed>> */
    private function kpis(): Collection
    {
        $this->app->forgetScopedInstances();

        return collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->viewData('kpis'))->keyBy('key');
    }

    /**
     * A website with Search Console (30 clicks on 10-12, 15 before; last run finished 2 hours ago) and GA4 (200 sessions
     * on 10-05, 100 before; automation last success 3 hours ago).
     */
    private function website(): void
    {
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.example', 'status' => DigitalAssetStatus::Active]);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'sc-domain:panorama.example']);
        $this->ga4 = CoreExternalResource::factory()->create(['display_name' => 'GA4 Panorama']);
        foreach ([[$this->gsc, 'search_console'], [$this->ga4, 'ga4']] as [$resource, $capability]) {
            CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $resource->id, 'capability' => $capability,
                'status' => CoreAssetBinding::STATUS_ACTIVE]);
        }
        foreach ([['2026-10-12', 30], ['2026-09-10', 15]] as [$date, $clicks]) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.example',
                'search_type' => 'web', 'reporting_date' => $date, 'query' => 'implant ankara', 'page' => 'https://panorama.example/implant/', 'clicks' => $clicks,
                'impressions' => $clicks * 10, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => 4.0])]);
        }
        foreach ([['2026-10-05', 200], ['2026-09-01', 100]] as [$date, $sessions]) {
            $this->insertFacts('ga4_landing_source_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->ga4->id, 'property_id' => 'properties/1',
                'reporting_date' => $date, 'landingPage' => '/implant/', 'sessionSource' => 'google', 'sessionMedium' => 'organic', 'sessions' => $sessions,
                'keyEvents' => 1, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }
        CollectionResourceRun::factory()->create(['external_resource_id' => $this->gsc->id, 'digital_asset_id' => $this->site->id, 'provider_or_source' => 'GSC',
            'status' => CollectionRunStatus::Completed, 'finished_at' => now()->subHours(2)]);
        ResourceAutomation::query()->create(['external_resource_id' => $this->ga4->id, 'collection_enabled' => true, 'last_collection_success_at' => now()->subHours(3)]);
    }

    /** A bound Google Ads account in $timezone with 30 cost a day for the 40 days before its today. */
    private function googleAds(string $timezone): void
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Ads']);
        $integration = CoreIntegration::query()->where('provider', 'google')->first() ?? CoreIntegration::factory()->google()->create();
        $integration->update(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => $timezone, 'currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE]);
        for ($day = 1; $day <= 40; $day++) {
            $this->insertFacts('google_ads_campaign_daily', ['campaign_id' => '101', 'reporting_date' => now($timezone)->subDays($day)->toDateString(),
                'impressions' => 100, 'clicks' => 5, 'cost_micros' => 0, 'cost_amount' => 30, 'conversions' => 1, 'currency' => 'TRY', 'metadata' => '{}',
                'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'source_timezone' => $timezone,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'gads'.$day),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
