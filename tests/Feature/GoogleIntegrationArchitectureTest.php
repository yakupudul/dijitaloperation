<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\BindingScopeGuard;
use App\Support\Integrations\Google\GoogleConnectorRegistry;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleIntegrationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        config([
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.ads_developer_token' => null,
        ]);

        Http::fake();
    }

    public function test_canonical_google_provider_and_connectors(): void
    {
        $this->assertSame('google', ProviderRegistry::GOOGLE);
        $this->assertTrue(GoogleConnectorRegistry::sharesAuthorizationCredential());
        $this->assertSame(
            ['search_console', 'ga4', 'google_ads', 'google_business_profile'],
            ProviderRegistry::capabilities(ProviderRegistry::GOOGLE),
        );

        foreach (GoogleConnectorRegistry::all() as $connector) {
            $this->assertSame(ProviderRegistry::GOOGLE, $connector['provider']);
            $this->assertTrue(GoogleResourceType::isValid($connector['resource_type']));
        }

        $this->assertSame('gsc', GoogleConnectorRegistry::get('search_console')['ui_slug']);
        $this->assertSame('gbp', GoogleConnectorRegistry::get('google_business_profile')['ui_slug']);
        $this->assertNotNull(GoogleConnectorRegistry::byUiSlug('google-ads'));
    }

    public function test_integration_credential_resource_binding_relations(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/111',
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'ga4']);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
        ]);

        $this->assertTrue($integration->providerCredential()->exists());
        $this->assertTrue($integration->authorizationCredential()->exists());
        $this->assertSame(1, $integration->externalResources()->count());
        $this->assertTrue($resource->bindings()->whereKey($binding->id)->exists());
        $this->assertTrue(AssetBindingCompatibility::isCompatible($asset, $resource));
        BindingScopeGuard::assertCanBind($asset, $resource);
    }

    public function test_external_resource_deduplicates_on_integration_type_external_id(): void
    {
        $integration = CoreIntegration::factory()->google()->create();

        $first = CoreExternalResource::query()->firstOrCreate(
            [
                'integration_id' => $integration->id,
                'resource_type' => GoogleResourceType::GSC_PROPERTY,
                'external_id' => 'sc-domain:example.test',
            ],
            [
                'provider' => ProviderRegistry::GOOGLE,
                'display_name' => 'example.test',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'discovered_at' => now(),
            ],
        );

        $second = CoreExternalResource::query()->firstOrCreate(
            [
                'integration_id' => $integration->id,
                'resource_type' => GoogleResourceType::GSC_PROPERTY,
                'external_id' => 'sc-domain:example.test',
            ],
            [
                'provider' => ProviderRegistry::GOOGLE,
                'display_name' => 'renamed',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'discovered_at' => now(),
            ],
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CoreExternalResource::query()->where('integration_id', $integration->id)->count());
    }

    public function test_binding_uniqueness_and_discovered_not_bound(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1234567890',
        ]);
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);

        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
        ]);

        $this->expectException(QueryException::class);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
        ]);
    }

    public function test_discovered_resource_can_exist_without_binding(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
        ]);

        $this->assertSame(0, $resource->bindings()->count());

        $detail = app(GoogleIntegrationReadModel::class)->detail();
        $this->assertSame(1, $detail['resources_discovered']);
        $this->assertSame(0, $detail['bound']);
        $this->assertSame(1, $detail['available']);
    }

    public function test_incompatible_asset_resource_rejected(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
        ]);
        $adsAsset = DigitalAsset::factory()->create(['type' => 'google_ads']);

        $this->assertFalse(AssetBindingCompatibility::isCompatible($adsAsset, $resource));
        $this->expectException(\InvalidArgumentException::class);
        BindingScopeGuard::assertCanBind($adsAsset, $resource);
    }

    public function test_read_model_not_configured_and_no_secrets(): void
    {
        $detail = app(GoogleIntegrationReadModel::class)->detail();

        $this->assertSame(IntegrationOperatorStatus::NOT_CONFIGURED, $detail['state']);
        $this->assertSame(0, $detail['resources_discovered']);
        $this->assertNull($detail['secrets']);
        $this->assertArrayNotHasKey('access_token', $detail);
        $this->assertArrayNotHasKey('refresh_token', $detail);
        $this->assertArrayNotHasKey('client_secret', $detail);

        app(GoogleIntegrationReadModel::class)->assertNoSecrets($detail);
        Http::assertNothingSent();
    }

    public function test_read_model_connected_without_resources_and_without_fresh_data(): void
    {
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'config' => [
                'auth_status' => 'connected',
                'account_email' => 'ops@example.test',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
        ]);

        $detail = app(GoogleIntegrationReadModel::class)->detail();

        $this->assertSame(IntegrationOperatorStatus::CONNECTED, $detail['state']);
        $this->assertSame(0, $detail['resources_discovered']);
        $this->assertSame('not_run', $detail['collection_state']);
        $this->assertSame('none', $detail['data_state']);
        $this->assertSame('discover', $detail['next_action']);
        $this->assertStringContainsString('ops@example.test', (string) $detail['account_email']);
        $this->assertFalse(str_contains(json_encode($detail) ?: '', 'sample-refresh-token'));
        $this->assertFalse(str_contains(json_encode($detail) ?: '', 'test-client-secret'));
    }

    public function test_bound_without_collection_is_not_data_available(): void
    {
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'config' => ['auth_status' => 'connected'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
        ]);
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
        ]);

        $detail = app(GoogleIntegrationReadModel::class)->detail();

        $this->assertSame(IntegrationOperatorStatus::CONNECTED, $detail['state']);
        $this->assertSame(1, $detail['bound']);
        $this->assertSame('not_run', $detail['collection_state']);
        $this->assertSame('none', $detail['data_state']);
        $this->assertNotSame('Data available', $detail['data_state_label']);
    }

    public function test_resource_types_remain_distinct(): void
    {
        $integration = CoreIntegration::factory()->google()->create();

        foreach ([
            GoogleResourceType::GA4_PROPERTY => 'properties/1',
            GoogleResourceType::GSC_PROPERTY => 'sc-domain:a.test',
            GoogleResourceType::GOOGLE_ADS_CUSTOMER => '111',
            GoogleResourceType::GBP_LOCATION => 'locations/1',
        ] as $type => $externalId) {
            CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'resource_type' => $type,
                'external_id' => $externalId,
            ]);
        }

        $types = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->pluck('resource_type')
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            GoogleResourceType::GA4_PROPERTY,
            GoogleResourceType::GBP_LOCATION,
            GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            GoogleResourceType::GSC_PROPERTY,
        ], $types);
    }

    public function test_frozen_integrations_hub_uses_real_google_card(): void
    {
        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('Not configured')
            ->assertDontSee('23');

        $card = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $g) => $g['providers'])
            ->firstWhere('id', 'google');

        $this->assertSame('real', $card['provenance'] ?? null);
        $this->assertSame(0, $card['resources_discovered']);
        $this->assertTrue($card['discovery_not_run'] ?? false);
    }

    public function test_frozen_google_page_renders_and_oauth_lands_on_app(): void
    {
        $this->get(route('operator.integrations'))->assertOk();
        $this->get(route('operator.integrations.google'))
            ->assertOk()
            ->assertSee('Not configured')
            ->assertDontSee('sample-access-token');

        $this->assertStringContainsString('/integrations/google', route('operator.integrations.google', absolute: false));

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::GOOGLE);
        $card = app(IntegrationWorkspaceCatalog::class)->cards()->first(
            fn ($c) => $c->provider === ProviderRegistry::GOOGLE,
        );
        $this->assertNotNull($card);
        $this->assertStringContainsString('/integrations/google', (string) $card->manageUrl);
        $this->assertSame($integration->id, $card->integrationId);
    }

    public function test_connectors_share_single_credential_path(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
        ]);

        $this->assertSame(1, $integration->credentials()->authorization()->count());
        $this->assertTrue(GoogleConnectorRegistry::sharesAuthorizationCredential());
        $this->assertCount(4, GoogleConnectorRegistry::ids());
    }

    public function test_no_live_google_http_from_read_model_or_ui(): void
    {
        Livewire::test(GoogleIntegrationPage::class)->assertOk();
        app(GoogleIntegrationReadModel::class)->detail();
        Http::assertNothingSent();
    }

    public function test_agency_google_integration_is_provider_unique(): void
    {
        CoreIntegration::factory()->google()->create();

        $this->expectException(QueryException::class);
        CoreIntegration::factory()->google()->create();
    }

    public function test_binding_scope_rejects_unknown_google_resource_type(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'not_a_google_type',
        ]);
        // Force type onto an otherwise compatible asset path by bypassing AssetBindingCompatibility
        // through a custom resource that uses a known DigitalAsset capability name incorrectly.
        $asset = DigitalAsset::factory()->create(['type' => 'ga4']);

        $this->assertFalse(AssetBindingCompatibility::isCompatible($asset, $resource));

        $this->expectException(\InvalidArgumentException::class);
        BindingScopeGuard::assertCanBind($asset, $resource);
    }

    public function test_customers_remain_separate_from_agency_integration_ownership(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
        ]);

        $assetA = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'website']);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'website']);

        // Agency Google Integration may bind resources to multiple customers' assets.
        BindingScopeGuard::assertCanBind($assetA, $resource);
        BindingScopeGuard::assertCanBind($assetB, $resource);
        $this->assertTrue(BindingScopeGuard::belongsToIntegration($resource, $integration->id));
        $this->assertFalse(BindingScopeGuard::belongsToIntegration($resource, $integration->id + 999));
    }
}
