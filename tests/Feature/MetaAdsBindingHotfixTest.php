<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaAdsBindingHotfixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private CoreIntegration $metaIntegration;

    private DigitalAsset $metaAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $this->metaIntegration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'name' => 'Agency Meta',
        ]);

        app(MetaProviderCredentialService::class)->save($this->metaIntegration, [
            'access_token' => 'EAAG-uat-secret-token-never-show',
        ], $this->admin);

        $this->metaAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Meta Ads UAT Asset',
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
    }

    public function test_operator_collect_now_routes_meta_ads_to_collection_engine(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_collect_engine',
            'display_name' => 'Collect Engine Meta Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->metaAsset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        config([
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue_connection' => 'database',
        ]);

        $result = app(CollectLiveBoundDataService::class)->collect($this->metaAsset->fresh());
        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertNotNull($result['collection_run_id']);
        $this->assertEmpty($result['runs']);
        $this->assertTrue(CollectionRun::query()->whereKey($result['collection_run_id'])->exists());
        $this->assertSame(0, Evidence::query()->where('digital_asset_id', $this->metaAsset->id)->count());
        $this->assertSame(0, Run::query()->where('module_id', 'meta-ads')->count());
    }

    public function test_no_binding_message_is_provider_neutral(): void
    {
        $adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
            'module_id' => 'google-ads',
        ]);

        $result = app(CollectLiveBoundDataService::class)->collect($adsAsset);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No active provider bindings', $result['message']);
        $this->assertStringContainsString('Settings → Integrations', $result['message']);
        $this->assertStringContainsString('Connections', $result['message']);
        $this->assertStringNotContainsString('Google first', $result['message']);
        $this->assertStringNotContainsString('Provider resources', $result['message']);
    }
}
