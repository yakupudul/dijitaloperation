<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\MetaAdsConnectionsRelationManager;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_connections_relation_manager_renders_summary_and_bind_action(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_1001',
            'display_name' => 'UAT Ad Account Alpha',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['currency' => 'TRY'],
        ]);

        CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_1002',
            'display_name' => 'UAT Ad Account Beta',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $component = Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertOk()
            ->assertSee('Meta Ad Account binding')
            ->assertSee('Connected')
            ->assertSee('Not bound')
            ->assertSee('2 discoverable Ad Accounts available below')
            ->assertSee('No Meta Ad Account connected')
            ->assertTableActionExists('create')
            ->assertTableActionVisible('create')
            ->assertDontSee('EAAG-uat-secret-token-never-show')
            ->assertDontSee((string) $resource->id.' token');

        $html = (string) $component->html();
        $this->assertStringContainsString('Bind Ad Account', $html);
        $this->assertStringContainsString('fi-ta', $html);
    }

    public function test_bind_ad_account_creates_canonical_binding_and_survives_reload(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_2001',
            'display_name' => 'Boundable Meta Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'USD',
                'timezone_name' => 'America/Los_Angeles',
            ],
        ]);

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $resource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasNoTableActionErrors();

        $binding = CoreAssetBinding::query()->firstOrFail();
        $this->assertSame($this->metaAsset->id, $binding->digital_asset_id);
        $this->assertSame($resource->id, $binding->external_resource_id);
        $this->assertSame('meta_ads', $binding->capability);
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $binding->status);
        $this->assertSame($this->admin->id, $binding->configuration['confirmed_by_user_id'] ?? null);
        $this->assertSame('meta_integration_selection', $binding->configuration['origin'] ?? null);

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset->fresh(['assetBindings.externalResource']),
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertOk()
            ->assertSee('Boundable Meta Account')
            ->assertSee('Agency Meta')
            ->assertSee('Currency: USD')
            ->assertDontSee('Not bound')
            ->assertDontSee('EAAG-uat-secret-token-never-show')
            ->assertSee('Disconnect Ad Account');

        $this->assertDatabaseHas('core_asset_bindings', [
            'digital_asset_id' => $this->metaAsset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->assertSame(1, CoreAssetBinding::query()->count());
    }

    public function test_resource_safety_rejects_google_wrong_type_disabled_and_duplicates(): void
    {
        $metaResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_3001',
            'display_name' => 'Valid Meta Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $googleIntegration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $googleResource = CoreExternalResource::factory()->create([
            'integration_id' => $googleIntegration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => 'customers/999',
            'display_name' => 'Google Ads Prop',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $wrongType = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_page',
            'external_id' => 'page_1',
            'display_name' => 'Meta Page',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $googleResource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasTableActionErrors();

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $wrongType->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasTableActionErrors();

        // Provider uniqueness: one Meta Integration — disable it so Bind is unavailable.
        $this->metaIntegration->update(['status' => CoreIntegration::STATUS_DISABLED]);

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])->assertTableActionHidden('create');

        $this->metaIntegration->update(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->assertSame(0, CoreAssetBinding::query()->count());

        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $metaResource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, CoreAssetBinding::query()->count());

        $secondMeta = CoreExternalResource::factory()->create([
            'integration_id' => $this->metaIntegration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_3002',
            'display_name' => 'Second Meta Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        // Silent second bind without replace confirmation must fail.
        Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $this->metaAsset->fresh(),
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $secondMeta->id,
                'allow_replace' => false,
            ])
            ->assertHasTableActionErrors();

        $this->assertFalse(
            CoreAssetBinding::query()
                ->where('digital_asset_id', $this->metaAsset->id)
                ->where('external_resource_id', $secondMeta->id)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->exists(),
        );

        $otherBrand = Brand::factory()->create(['customer_id' => $this->brand->customer_id]);
        $otherAsset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $this->assertFalse(
            CoreAssetBinding::query()
                ->where('digital_asset_id', $otherAsset->id)
                ->where('external_resource_id', $metaResource->id)
                ->exists(),
        );

        $unboundAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $component = Livewire::test(MetaAdsConnectionsRelationManager::class, [
            'ownerRecord' => $unboundAsset,
            'pageClass' => ViewDigitalAsset::class,
        ]);

        $options = (new \ReflectionMethod(MetaAdsConnectionsRelationManager::class, 'resourceOptions'))
            ->invoke($component->instance());

        // Account already actively bound elsewhere is not offered (no shared-account ambiguity).
        $this->assertArrayNotHasKey($metaResource->id, $options);
        $this->assertArrayHasKey($secondMeta->id, $options);
        $this->assertArrayNotHasKey($googleResource->id, $options);
        $this->assertArrayNotHasKey($wrongType->id, $options);
        $this->assertStringNotContainsString('EAAG-uat-secret-token-never-show', json_encode($options));
    }

    public function test_collect_live_data_visible_for_meta_when_collector_registered(): void
    {
        $this->assertNotNull(app(BoundCollectorRegistry::class)->forCapability('meta_ads'));

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->metaAsset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionVisible('collectLiveData');
    }

    public function test_collect_live_data_remains_available_for_google_ads(): void
    {
        $this->assertNotNull(app(BoundCollectorRegistry::class)->forCapability('google_ads'));

        $adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Google Ads UAT Asset',
            'type' => 'google_ads',
            'module_id' => 'google-ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $adsAsset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionVisible('collectLiveData')
            ->assertSee('Collect live data');
    }

    public function test_website_refresh_workflow_preserves_collect_live_data_hidden(): void
    {
        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Website UAT Asset',
            'type' => 'website',
            'module_id' => 'website',
            'primary_url' => 'https://example.test',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertActionVisible('refreshData')
            ->assertActionHidden('collectLiveData');
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
