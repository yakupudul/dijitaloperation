<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Support\Integrations\BindingCardinalityRegistry;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleResourceBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'dev',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $customer = Customer::factory()->create(['name' => 'Acme']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Acme Brand',
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev',
            ],
        ]);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_compatibility_map_for_canonical_types(): void
    {
        $this->assertSame('ga4', ExternalResourceAssetCompatibility::preferredAssetType('ga4'));
        $this->assertSame('gsc', ExternalResourceAssetCompatibility::preferredAssetType('search_console'));
        $this->assertSame('google_ads', ExternalResourceAssetCompatibility::preferredAssetType('google_ads'));
        $this->assertSame('google_business_profile', ExternalResourceAssetCompatibility::preferredAssetType('google_business_profile'));

        $this->assertTrue(ExternalResourceAssetCompatibility::canBindResourceToAssetType('ga4', 'website'));
        $this->assertFalse(ExternalResourceAssetCompatibility::canBindResourceToAssetType('ga4', 'google_ads'));
        $this->assertFalse(ExternalResourceAssetCompatibility::canBindResourceToAssetType('search_console', 'google_business_profile'));
    }

    public function test_create_asset_and_bind_ga4_without_collection(): void
    {
        Queue::fake();
        Http::fake();

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123',
            'display_name' => 'Acme GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $assetsBefore = DigitalAsset::query()->count();

        $result = app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Acme Analytics',
            confirmedBy: $this->admin,
        ));

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['created_asset']);
        $this->assertSame($assetsBefore + 1, DigitalAsset::query()->count());
        $this->assertSame('ga4', $result['asset']->type);
        $this->assertSame($this->brand->id, $result['asset']->brand_id);
        $this->assertDatabaseHas('core_asset_bindings', [
            'digital_asset_id' => $result['asset']->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
            'status' => 'active',
        ]);
        $this->assertSame($this->admin->id, $result['binding']->configuration['confirmed_by_user_id'] ?? null);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_bind_existing_compatible_asset(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'gsc',
            'name' => 'Existing GSC',
            'status' => 'active',
        ]);

        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:acme.com',
            'display_name' => 'acme.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $result = app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
        ));

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['created_asset']);
        $this->assertSame($asset->id, $result['asset']->id);
        $this->assertSame(1, CoreAssetBinding::query()->count());
    }

    public function test_rejects_incompatible_type_binding(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
            'status' => 'active',
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/9',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->expectException(ValidationException::class);

        app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
        ));
    }

    public function test_duplicate_binding_prevented(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '2222222222',
            'display_name' => 'Client',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'selectable' => true],
        ]);

        $service = app(ConfirmGoogleResourceBindingService::class);
        $first = $service->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Ads 1',
            confirmedBy: $this->admin,
        ));
        $this->assertTrue($first['ok']);

        $this->expectException(ValidationException::class);
        $service->confirm(new ResourceBindingPlan(
            resource: $resource->fresh() ?? $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Ads 2',
            confirmedBy: $this->admin,
        ));
    }

    public function test_ads_manager_not_selectable(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '1111111111',
            'display_name' => 'MCC',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => true, 'selectable' => false],
        ]);

        $this->expectException(ValidationException::class);

        app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'MCC Asset',
            confirmedBy: $this->admin,
        ));
    }

    public function test_gbp_location_creates_google_business_profile_asset(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_business_profile',
            'external_id' => 'locations/loc-1',
            'display_name' => 'Store One',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['selectable' => true],
        ]);

        $result = app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Store One GBP',
            confirmedBy: $this->admin,
        ));

        $this->assertSame('google_business_profile', $result['asset']->type);
        $this->assertSame('google_business_profile', $result['binding']->capability);
    }

    public function test_unauthorized_user_cannot_bind(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/1',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->expectException(ValidationException::class);

        app(ConfirmGoogleResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $resource,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Nope',
            confirmedBy: $user,
        ));
    }

    public function test_livewire_confirm_bind_create_asset(): void
    {
        Queue::fake();

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/777',
            'display_name' => 'Livewire GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GoogleIntegrationPage::class)
            ->call('setTab', 'resources')
            ->call('bindResource', (string) $resource->id)
            ->assertSet('showBindModal', true)
            ->set('brandId', $this->brand->id)
            ->set('bindMode', ResourceBindingPlan::MODE_CREATE_ASSET)
            ->set('assetName', 'Confirmed GA4')
            ->call('confirmBind')
            ->assertSet('showBindModal', false)
            ->assertSee('Digital Asset created and Google resource bound');

        $this->assertDatabaseHas('digital_assets', [
            'name' => 'Confirmed GA4',
            'type' => 'ga4',
            'brand_id' => $this->brand->id,
        ]);
        $this->assertSame(1, CoreAssetBinding::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_page_render_does_not_call_google(): void
    {
        Http::fake();

        Livewire::actingAs($this->admin)
            ->test(GoogleIntegrationPage::class)
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_cardinality_registry_present_for_google_connectors(): void
    {
        foreach (['ga4', 'search_console', 'google_ads', 'google_business_profile'] as $type) {
            $rules = BindingCardinalityRegistry::forResourceType($type);
            $this->assertSame(1, $rules['max_active_resources_per_asset']);
            $this->assertSame(1, $rules['max_active_assets_per_resource']);
        }
    }

    public function test_no_auto_bind_from_name_similarity(): void
    {
        DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'ga4',
            'name' => 'Acme GA4',
            'status' => 'active',
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/555',
            'display_name' => 'Acme GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->assertSame(0, CoreAssetBinding::query()->count());

        // Opening bind UI does not create a binding without confirm.
        Livewire::actingAs($this->admin)
            ->test(GoogleIntegrationPage::class)
            ->call('bindResource', (string) $resource->id)
            ->assertSet('showBindModal', true);

        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_replace_closes_the_old_binding_and_preserves_its_resource_identity(): void
    {
        $first = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/old',
            'display_name' => 'Old GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $second = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/new',
            'display_name' => 'New GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'status' => 'active',
        ]);

        $old = app(ConfirmGoogleResourceBindingService::class)->bindExisting($asset, $first, $this->admin);

        try {
            app(ConfirmGoogleResourceBindingService::class)->bindExisting($asset, $second, $this->admin);
            $this->fail('Replacement without allowReplace must be rejected.');
        } catch (ValidationException) {
        }

        $result = app(ConfirmGoogleResourceBindingService::class)->bindExisting(
            $asset,
            $second,
            $this->admin,
            allowReplace: true,
        );

        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $old->fresh()->status);
        $this->assertSame($first->id, $old->fresh()->external_resource_id);
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $result->status);
        $this->assertSame($second->id, $result->external_resource_id);
        $this->assertSame(2, CoreAssetBinding::query()->count());
    }

    public function test_unbind_preserves_historical_binding_identity(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'status' => 'active',
        ]);
        $binding = app(ConfirmGoogleResourceBindingService::class)->bindExisting($asset, $resource, $this->admin);

        $result = app(ConfirmGoogleResourceBindingService::class)->unbind($binding, $this->admin);

        $this->assertTrue($result['ok']);
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $binding->fresh()->status);
        $this->assertSame($resource->id, $binding->fresh()->external_resource_id);
    }
}
