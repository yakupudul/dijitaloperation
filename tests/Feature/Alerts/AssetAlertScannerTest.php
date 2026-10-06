<?php

namespace Tests\Feature\Alerts;

use App\Enums\Collection\ActivityTier;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Operator\AssetRuntimeStatusReader;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Daily asset alerts: detection from collected rows, upsert by key, auto-resolve, and where they show.
 */
final class AssetAlertScannerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 09:00:00', 'UTC'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $this->integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_google_ads_spend_spike_and_conversions_stopped_then_resolved(): void
    {
        [$asset, $resource] = $this->googleAdsAsset();
        for ($day = 1; $day <= 30; $day++) {
            $date = now()->subDays($day)->toDateString();
            $spike = $day === 1;
            $recent = $day <= 3;
            $this->adsRow($resource, $date, $spike ? 900 : 200, $recent ? 0 : 3);
        }

        $result = app(AssetAlertScanner::class)->scan($asset);
        $kinds = AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->pluck('kind')->sort()->values()->all();
        $this->assertSame(['conversions_stopped', 'spend_spike'], $kinds);
        $this->assertSame(2, $result['new']);

        $again = app(AssetAlertScanner::class)->scan($asset);
        $this->assertSame(0, $again['new'], 'same alerts are updated, not duplicated');

        DB::table('google_ads_account_daily')->where('reporting_date', now()->subDay()->toDateString())->update(['cost_amount' => 210, 'conversions' => 2]);
        $final = app(AssetAlertScanner::class)->scan($asset);
        $this->assertSame(2, $final['resolved']);
        $this->assertSame(0, AssetAlert::query()->open()->count());
    }

    public function test_ga4_session_and_conversion_drops_are_alerted(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'name' => 'Site']);
        for ($day = 1; $day <= 14; $day++) {
            DB::table('ga4_property_daily')->insert([
                'digital_asset_id' => $site->id, 'external_resource_id' => 77, 'property_id' => '123',
                'reporting_date' => now()->subDays($day)->toDateString(), 'sessions' => $day <= 7 ? 30 : 100, 'keyEvents' => $day <= 7 ? 3 : 4,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'ga'.$day), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(AssetAlertScanner::class)->scan($site);

        $kinds = AssetAlert::query()->open()->where('digital_asset_id', $site->id)->pluck('kind')->all();
        $this->assertContains('ga4_sessions_drop', $kinds);
        $this->assertNotContains('ga4_conversions_drop', $kinds, '28 → 21 key events is a 25% dip, below the threshold');
    }

    public function test_search_drop_bad_review_and_stale_data(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => DigitalAssetStatus::Active, 'name' => 'Site']);
        for ($day = 1; $day <= 14; $day++) {
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:ornek.test',
                'reporting_date' => now()->subDays($day)->toDateString(), 'clicks' => $day <= 7 ? 5 : 20, 'impressions' => 400,
                'search_type' => 'web', 'metadata' => '{}', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'g'.$day), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(AssetAlertScanner::class)->scan($site);
        $this->assertSame('search_traffic_drop', AssetAlert::query()->open()->where('digital_asset_id', $site->id)->value('kind'));

        $gbp = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Profil']);
        $location = CoreExternalResource::factory()->create(['integration_id' => $this->integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $gbp->id, 'external_resource_id' => $location->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        DB::table('gbp_reviews')->insert(['digital_asset_id' => null, 'external_resource_id' => $location->id, 'run_id' => 1, 'location_name' => 'locations/1', 'review_id' => 'r1', 'star_rating' => 'ONE', 'comment' => 'Kötü', 'create_time' => now()->subDays(2), 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gbp_location_snapshots')->insert(['run_id' => 1, 'digital_asset_id' => null, 'external_resource_id' => $location->id, 'location_name' => 'locations/1', 'captured_at' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('moxdop:alerts:scan')->expectsOutputToContain('varlık tarandı')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['bad_review_unanswered', 'stale_data'], AssetAlert::query()->open()->where('digital_asset_id', $gbp->id)->pluck('kind')->all());

        $this->get(route('operator.gbp', ['assetId' => $gbp->id]))->assertOk()->assertSee('Yanıtsız düşük puanlı yorum')->assertSee('data-asset-alerts', false);
        // Step 3: asset alerts left the home screen (Bugün); they stay on the asset page.
        $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk()->assertSee('Google arama tıklamaları düştü');
    }

    public function test_an_idle_ga4_property_collected_weekly_is_judged_by_the_weekly_interval(): void
    {
        [$idle, $idleProperty] = $this->collectedAccount('ga4', 5, ActivityTier::Idle);
        [$active] = $this->collectedAccount('ga4', 5);

        app(AssetAlertScanner::class)->scanAll();

        $this->assertNotContains('ga4_stale', $this->openKinds($idle), 'idle: weekly interval + 3 days');
        $this->assertNotContains('stale_data', $this->openKinds($idle));
        $this->assertContains('ga4_stale', $this->openKinds($active), 'active: daily interval + 3 days');

        $this->collected($idleProperty, 11);
        app(AssetAlertScanner::class)->scanAll();

        $this->assertContains('ga4_stale', $this->openKinds($idle), 'idle past the weekly interval + 3 days');
        $this->assertContains('stale_data', $this->openKinds($idle));
    }

    public function test_stale_data_gives_a_weekly_collected_google_ads_account_the_weekly_interval(): void
    {
        [$idle, $idleAccount] = $this->collectedAccount('google_ads', 4, ActivityTier::Dormant);
        [$active] = $this->collectedAccount('google_ads', 4);
        $scan = function (DigitalAsset $asset): array {
            // The budget watch path: a runtime row, the bound accounts read by the scanner itself.
            app(AssetAlertScanner::class)->scan($asset, app(AssetRuntimeStatusReader::class)->forAssets(collect([$asset]))[(int) $asset->id]);

            return $this->openKinds($asset);
        };

        $this->assertNotContains('stale_data', $scan($idle), 'dormant: 72 hours + one week');
        $this->assertContains('stale_data', $scan($active), 'active: 72 hours');
        $this->assertStringContainsString('(72 saatten eski)', (string) AssetAlert::query()->open()->where('digital_asset_id', $active->id)->where('kind', 'stale_data')->value('message'));

        $this->collected($idleAccount, 11);
        $this->assertContains('stale_data', $scan($idle), 'missed its weekly pass');
        $this->assertStringContainsString('240 saatten eski; hesapta etkinlik olmadığı için haftada bir çekiliyor', (string) AssetAlert::query()->open()->where('digital_asset_id', $idle->id)->where('kind', 'stale_data')->value('message'));
    }

    public function test_stale_data_skips_accounts_not_collected_on_purpose_but_not_one_to_reconnect(): void
    {
        [$off] = $this->collectedAccount('google_ads', 5, automation: ['collection_enabled' => false]);
        [$manager] = $this->collectedAccount('google_ads', 5, metadata: ['is_manager' => true]);
        [$reconnect, $reconnectAccount] = $this->collectedAccount('google_ads', 5);
        $reconnectAccount->update(['status' => CoreExternalResource::STATUS_UNAVAILABLE]);

        $this->artisan('moxdop:alerts:scan')->assertSuccessful();

        $this->assertNotContains('stale_data', $this->openKinds($off), 'automatic collection switched off');
        $this->assertNotContains('stale_data', $this->openKinds($manager), 'manager account: data comes from its client accounts');
        $this->assertContains('stale_data', $this->openKinds($reconnect), 'its data really stopped');
    }

    /** @return list<string> */
    private function openKinds(DigitalAsset $asset): array
    {
        return AssetAlert::query()->open()->where('digital_asset_id', $asset->id)->pluck('kind')->all();
    }

    /**
     * A new asset with one bound account, its automation (daily interval) last successful `$daysAgo` days back and,
     * when given, its activity tier.
     *
     * @param  array<string, mixed>  $automation
     * @param  array<string, mixed>  $metadata
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function collectedAccount(string $type, int $daysAgo, ?ActivityTier $tier = null, array $automation = [], array $metadata = []): array
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type === 'ga4' ? 'website' : $type, 'status' => DigitalAssetStatus::Active,
            'name' => $type.' '.fake()->unique()->numberBetween(1, 9999)]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id, 'provider' => 'google', 'resource_type' => $type,
            'external_id' => ($type === 'ga4' ? 'properties/' : '').fake()->unique()->numerify('##########'), 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => $metadata + ['currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $type, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        ResourceAutomation::query()->create($automation + ['external_resource_id' => $resource->id, 'collection_enabled' => true, 'interval_days' => 1]);
        $this->collected($resource, $daysAgo);
        if ($tier !== null) {
            ResourceActivity::query()->create(['external_resource_id' => $resource->id, 'provider' => $type === 'ga4' ? 'GA4' : 'GOOGLE_ADS', 'tier' => $tier]);
        }

        return [$asset, $resource];
    }

    /** The account's last successful collection (automation and resource run) `$daysAgo` days back. */
    private function collected(CoreExternalResource $resource, int $daysAgo): void
    {
        ResourceAutomation::query()->where('external_resource_id', $resource->id)->update(['last_collection_success_at' => now()->subDays($daysAgo)]);
        CollectionResourceRun::query()->where('external_resource_id', $resource->id)->delete();
        CollectionResourceRun::factory()->create(['external_resource_id' => $resource->id, 'provider_or_source' => $resource->resource_type === 'ga4' ? 'GA4' : 'GOOGLE_ADS',
            'status' => CollectionRunStatus::Completed, 'finished_at' => now()->subDays($daysAgo)]);
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource} */
    private function googleAdsAsset(): array
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Ads']);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return [$asset, $resource];
    }

    private function adsRow(CoreExternalResource $resource, string $date, float $cost, int $conversions): void
    {
        DB::table('google_ads_account_daily')->insert([
            'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'reporting_date' => $date,
            'impressions' => 1000, 'clicks' => 50, 'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost, 'conversions' => $conversions,
            'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $date), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
