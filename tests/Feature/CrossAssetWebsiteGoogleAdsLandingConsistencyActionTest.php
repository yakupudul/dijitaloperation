<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\CrossAssetWebsiteGoogleAdsLandingConsistencyService;
use App\Services\GoogleAdsLandingFinalUrlsCollectService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrossAssetWebsiteGoogleAdsLandingConsistencyActionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');
    }

    public function test_action_is_visible_for_website_assets(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
            'primary_url' => 'https://ok.example',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionVisible('runWebsiteGoogleAdsLandingConsistency');
    }

    public function test_action_hidden_for_non_website_assets(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionHidden('runWebsiteGoogleAdsLandingConsistency');
    }

    public function test_action_runs_pack_and_redirects_to_run(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'primary_url' => 'https://acme.example',
        ]);
        $ads = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);

        $websiteRun = Run::factory()->create([
            'digital_asset_id' => $website->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $websiteRun->id,
            'digital_asset_id' => $website->id,
            'type' => 'http_fetch',
            'payload' => [
                'url' => 'https://acme.example',
                'status_code' => 200,
                'effective_url' => 'https://acme.example',
                'is_https' => true,
                'response_is_ok' => true,
                'error_class' => null,
                'error_or_status' => '200',
            ],
        ]);

        $adsRun = Run::factory()->create([
            'digital_asset_id' => $ads->id,
            'module_id' => GoogleAdsLandingFinalUrlsCollectService::MODULE_ID,
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $adsRun->id,
            'digital_asset_id' => $ads->id,
            'type' => GoogleAdsLandingFinalUrlsCollectService::EVIDENCE_TYPE_LANDING_FINAL_URLS,
            'payload' => [
                'requested_customer_id' => '1111111111',
                'final_urls' => ['https://acme.example/ads'],
                'final_url_hosts' => ['acme.example'],
                'final_url_count' => 1,
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'google_ads_search_gaql',
            ],
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->callAction('runWebsiteGoogleAdsLandingConsistency')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $run = Run::query()
            ->where('digital_asset_id', $website->id)
            ->where('module_id', CrossAssetWebsiteGoogleAdsLandingConsistencyService::MODULE_ID)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->metadata['compared'] ?? false);
    }
}
