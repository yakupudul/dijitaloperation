<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\GoogleAds\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsWorkspaceTruthReconciler;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Overview KPI cards compare the selected period with the equally long previous period, in the app locale. */
final class GoogleAdsOverviewKpiDeltaTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_kpis_carry_localized_previous_period_deltas_and_chart_dates(): void
    {
        $this->accountDay('2026-09-01', 100, 4);
        $this->accountDay('2026-09-02', 100, 4);
        $this->accountDay('2026-09-03', 150, 2);
        $this->accountDay('2026-09-04', 150, 2);

        app()->setLocale('tr');
        $data = app(GoogleAdsWorkspaceTruthReconciler::class)->reconcile((string) $this->asset->id, '2026-09-03', '2026-09-04', []);

        $this->assertSame('önceki döneme göre +%50,0', $data['glance']['spend']['secondary']);
        $this->assertSame('önceki döneme göre -%50,0', $data['glance']['conversions']['secondary']);
        $this->assertSame(['3 Eyl', '4 Eyl'], $data['performance_trend']['labels']);
    }

    public function test_missing_previous_period_is_reported_as_unavailable(): void
    {
        $this->accountDay('2026-09-03', 150, 2);

        app()->setLocale('en');
        $data = app(GoogleAdsWorkspaceTruthReconciler::class)->reconcile((string) $this->asset->id, '2026-09-03', '2026-09-04', []);

        $this->assertSame('vs previous period unavailable', $data['glance']['spend']['secondary']);
        $this->assertSame(['Sep 3'], $data['performance_trend']['labels']);
    }

    public function test_overview_tab_renders_real_rows_with_turkish_labels(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 10));
        $this->accountDay('2026-09-03', 150, 0);
        DB::table('google_ads_campaign_snapshot')->insert($this->pooled([
            'campaign_id' => 'c1',
            'metadata' => json_encode(['name' => 'Implant Arama', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH']),
        ], 'snapshot-c1'));
        DB::table('google_ads_campaign_daily')->insert($this->pooled([
            'reporting_date' => '2026-09-03', 'campaign_id' => 'c1', 'impressions' => 1000, 'clicks' => 30,
            'cost_micros' => 150_000_000, 'cost_amount' => 150, 'conversions' => 0, 'currency' => 'TRY',
        ], 'daily-c1'));

        app()->setLocale('tr');
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertOk()
            ->assertSee('Implant Arama')
            ->assertSee('Aktif')
            ->assertDontSee('ENABLED')
            ->assertSee('1 kampanya harcama yaptı ama dönüşüm getirmedi');
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function pooled(array $values, string $fingerprint): array
    {
        return $values + [
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $fingerprint),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function accountDay(string $date, float $cost, float $conversions): void
    {
        DB::table('google_ads_account_daily')->insert([
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'reporting_date' => $date,
            'impressions' => 1000,
            'clicks' => 50,
            'cost_micros' => (int) ($cost * 1_000_000),
            'cost_amount' => $cost,
            'conversions' => $conversions,
            'currency' => 'TRY',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', 'account'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
