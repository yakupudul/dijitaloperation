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
use App\Services\CrossAssetWebsiteMetaAdsDestinationConsistencyService;
use App\Services\MetaAdsAdDestinationUrlsCollectService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrossAssetWebsiteMetaAdsDestinationConsistencyActionTest extends TestCase
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
            ->assertActionVisible('runWebsiteMetaAdsDestinationConsistency');
    }

    public function test_action_hidden_for_non_website_assets(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionHidden('runWebsiteMetaAdsDestinationConsistency');
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
        $meta = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
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

        $metaRun = Run::factory()->create([
            'digital_asset_id' => $meta->id,
            'module_id' => MetaAdsAdDestinationUrlsCollectService::MODULE_ID,
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $metaRun->id,
            'digital_asset_id' => $meta->id,
            'type' => MetaAdsAdDestinationUrlsCollectService::EVIDENCE_TYPE_AD_DESTINATION_URLS,
            'payload' => [
                'requested_ad_account_id' => 'act_1111111111',
                'destination_urls' => ['https://acme.example/ads'],
                'destination_url_hosts' => ['acme.example'],
                'destination_url_count' => 1,
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'meta_ads_ads_list_get',
            ],
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->callAction('runWebsiteMetaAdsDestinationConsistency')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $run = Run::query()
            ->where('digital_asset_id', $website->id)
            ->where('module_id', CrossAssetWebsiteMetaAdsDestinationConsistencyService::MODULE_ID)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->metadata['compared'] ?? false);
    }
}
