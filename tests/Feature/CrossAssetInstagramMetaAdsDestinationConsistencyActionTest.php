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
use App\Services\CrossAssetInstagramMetaAdsDestinationConsistencyService;
use App\Services\InstagramAccountProfileCollectService;
use App\Services\MetaAdsAdDestinationUrlsCollectService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrossAssetInstagramMetaAdsDestinationConsistencyActionTest extends TestCase
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

    public function test_action_is_visible_for_instagram_assets(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'instagram',
            'status' => DigitalAssetStatus::Active,
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionVisible('runInstagramMetaAdsDestinationConsistency');
    }

    public function test_action_hidden_for_non_instagram_assets(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'primary_url' => 'https://ok.example',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionHidden('runInstagramMetaAdsDestinationConsistency');
    }

    public function test_action_runs_pack_and_redirects_to_run(): void
    {
        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
        ]);

        $instagram = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'instagram',
        ]);
        $meta = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
        ]);

        $instagramRun = Run::factory()->create([
            'digital_asset_id' => $instagram->id,
            'module_id' => InstagramAccountProfileCollectService::MODULE_ID,
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $instagramRun->id,
            'digital_asset_id' => $instagram->id,
            'type' => InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE,
            'payload' => [
                'requested_ig_user_id' => '17841400000000000',
                'ig_user_id' => '17841400000000000',
                'username' => 'acme_brand',
                'name' => 'Acme Brand',
                'account_type' => 'BUSINESS',
                'website' => 'https://acme.example',
                'biography' => 'Hello',
                'ok' => true,
                'status_code' => 200,
                'status_or_error' => '200',
                'error_class' => null,
                'fetch_method' => 'instagram_graph_ig_user_get',
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
            'record' => $instagram->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->callAction('runInstagramMetaAdsDestinationConsistency')
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $run = Run::query()
            ->where('digital_asset_id', $instagram->id)
            ->where('module_id', CrossAssetInstagramMetaAdsDestinationConsistencyService::MODULE_ID)
            ->where('metadata->pack_id', CrossAssetInstagramMetaAdsDestinationConsistencyService::PACK_ID)
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->metadata['compared'] ?? false);
    }
}
