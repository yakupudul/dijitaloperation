<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\BindingScopeGuard;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class MetaIntegrationArchitectureTest extends TestCase
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
            'moxdop.meta.app_id' => null,
            'moxdop.meta.app_secret' => null,
            'moxdop.meta.access_token' => null,
        ]);

        Http::fake();
    }

    public function test_canonical_meta_provider_and_meta_ads_connector(): void
    {
        $this->assertSame('meta', ProviderRegistry::META);
        $this->assertSame(
            ['meta_ads', 'instagram'],
            ProviderRegistry::capabilities(ProviderRegistry::META),
        );

        $connectors = MetaConnectorRegistry::connectors();
        $this->assertCount(1, $connectors);
        $this->assertSame(MetaConnectorRegistry::META_ADS, $connectors[0]['capability']);
        $this->assertSame(MetaResourceType::META_AD_ACCOUNT, $connectors[0]['resource_type']);
        $this->assertSame('NOT_YET', $connectors[0]['collection_status']);
        $this->assertTrue($connectors[0]['production_foundation']);
    }

    public function test_business_and_ad_account_resource_types_remain_distinct(): void
    {
        $this->assertTrue(MetaResourceType::isContainer(MetaResourceType::META_BUSINESS));
        $this->assertFalse(MetaResourceType::isBindable(MetaResourceType::META_BUSINESS));
        $this->assertFalse(MetaResourceType::isSelectable(MetaResourceType::META_BUSINESS));

        $this->assertTrue(MetaResourceType::isSelectable(MetaResourceType::META_AD_ACCOUNT));
        $this->assertTrue(MetaResourceType::isBindable(MetaResourceType::META_AD_ACCOUNT));
        $this->assertFalse(MetaResourceType::isContainer(MetaResourceType::META_AD_ACCOUNT));

        $this->assertNull(ExternalResourceAssetCompatibility::preferredAssetType(MetaResourceType::META_BUSINESS));
        $this->assertSame([], ExternalResourceAssetCompatibility::compatibleAssetTypes(MetaResourceType::META_BUSINESS));
        $this->assertSame(['meta_ads'], ExternalResourceAssetCompatibility::compatibleAssetTypes(MetaResourceType::META_AD_ACCOUNT));
    }

    public function test_act_prefix_cannot_create_duplicate_ad_account_resources(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();

        $first = CoreExternalResource::query()->firstOrCreate(
            [
                'integration_id' => $integration->id,
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'external_id' => MetaAdAccountId::canonical('123456789'),
            ],
            [
                'provider' => ProviderRegistry::META,
                'display_name' => 'Account A',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'discovered_at' => now(),
            ],
        );

        $second = CoreExternalResource::query()->firstOrCreate(
            [
                'integration_id' => $integration->id,
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'external_id' => MetaAdAccountId::canonical('act_123456789'),
            ],
            [
                'provider' => ProviderRegistry::META,
                'display_name' => 'Account A renamed',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'discovered_at' => now(),
            ],
        );

        $this->assertTrue($first->is($second));
        $this->assertSame('act_123456789', $first->external_id);
        $this->assertSame(
            1,
            CoreExternalResource::query()
                ->where('integration_id', $integration->id)
                ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
                ->count(),
        );
        $this->assertTrue(MetaAdAccountId::equals('123456789', 'act_123456789'));
    }

    public function test_business_resource_is_not_auto_digital_asset_or_binding(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_111',
            'display_name' => 'Example Business',
        ]);

        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame(0, $business->bindings()->count());
        $this->assertSame(0, CoreAssetBinding::query()->count());

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        $this->assertFalse(AssetBindingCompatibility::isCompatible($asset, $business));
        $this->assertFalse(ExternalResourceAssetCompatibility::isCompatible($asset, $business));

        $this->expectException(InvalidArgumentException::class);
        BindingScopeGuard::assertCanBind($asset, $business);
    }

    public function test_ad_account_inventory_does_not_auto_create_digital_asset_or_binding(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $account = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_999',
            'parent_external_id' => 'biz_111',
            'metadata' => [
                'currency' => 'USD',
                'timezone_name' => 'America/Los_Angeles',
                'business_id' => 'biz_111',
            ],
        ]);

        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame(0, $account->bindings()->count());

        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame(1, $detail['ad_accounts_discovered']);
        $this->assertSame(0, $detail['bound']);
        $this->assertSame(1, $detail['available']);
    }

    public function test_credential_belongs_to_integration_not_resource(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-owned-by-integration'],
        ]);

        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_1',
        ]);
        $account = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_1',
            'parent_external_id' => 'biz_1',
        ]);

        $fresh = $integration->fresh(['providerCredential']);
        $this->assertTrue(app(MetaCredentialResolver::class)->hasTenantAuthorization($fresh));
        $this->assertArrayNotHasKey('access_token', $business->metadata ?? []);
        $this->assertArrayNotHasKey('access_token', $account->metadata ?? []);
        $this->assertSame(1, $integration->credentials()->count());
    }

    public function test_app_secret_not_stored_in_tenant_credential(): void
    {
        config([
            'moxdop.meta.app_id' => 'app-123',
            'moxdop.meta.app_secret' => 'secret-xyz',
        ]);

        $this->assertTrue(MetaApiConfig::isApplicationConfigured());
        $this->assertSame('secret-xyz', MetaApiConfig::appSecret());

        $integration = CoreIntegration::factory()->meta()->create();
        $service = app(MetaProviderCredentialService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->save($integration, [
            'access_token' => 'EAAG-tenant',
            'app_secret' => 'must-not-persist',
        ], $this->admin);
    }

    public function test_meta_ads_binding_foundation_without_auto_binding(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_55',
        ]);
        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);

        BindingScopeGuard::assertCanBind($asset, $resource);
        $this->assertTrue(AssetBindingCompatibility::isCompatible($asset, $resource));
        $this->assertSame(0, CoreAssetBinding::query()->count());

        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
        ]);

        $this->assertSame(1, CoreAssetBinding::query()->count());

        $this->expectException(QueryException::class);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
        ]);
    }

    public function test_authorized_without_discovered_resources(): void
    {
        config([
            'moxdop.meta.app_id' => 'app-123',
            'moxdop.meta.app_secret' => 'secret-xyz',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'config' => [
                'connection_status' => 'connected',
                'last_tested_at' => now()->toIso8601String(),
            ],
        ]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-connected'],
        ]);

        $detail = app(MetaIntegrationReadModel::class)->detail();

        $this->assertSame(MetaAuthStatus::CONNECTED, $detail['auth_status']);
        $this->assertSame(0, $detail['businesses_discovered']);
        $this->assertSame(0, $detail['ad_accounts_discovered']);
        $this->assertSame(0, $detail['bound']);
        $this->assertSame('discover_businesses', $detail['next_action']);
        $this->assertTrue($detail['actions']['discover_businesses']);
        $this->assertFalse($detail['actions']['bind']);
        $this->assertFalse($detail['actions']['collect']);
        $this->assertNull($detail['secrets']);
        $this->assertStringNotContainsString('EAAG-connected', (string) json_encode($detail));
        $this->assertStringNotContainsString('secret-xyz', (string) json_encode($detail));
        app(MetaIntegrationReadModel::class)->assertNoSecrets($detail);
    }

    public function test_discovered_not_bound_and_bound_not_collected(): void
    {
        config([
            'moxdop.meta.app_id' => 'app-123',
            'moxdop.meta.app_secret' => 'secret-xyz',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'config' => [
                'connection_status' => 'connected',
                'auth_status' => 'connected',
                'credential_status' => 'valid',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-token'],
        ]);
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_9',
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_9',
            'parent_external_id' => 'biz_9',
        ]);

        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame(1, $detail['businesses_discovered']);
        $this->assertSame(1, $detail['ad_accounts_discovered']);
        $this->assertSame(0, $detail['bound']);
        // Businesses exist but none selected as discovery context yet.
        $this->assertSame('select_business', $detail['next_action']);

        CoreIntegrationDiscoveryContext::query()->create([
            'integration_id' => $integration->id,
            'external_resource_id' => $business->id,
            'purpose' => CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT,
            'status' => CoreIntegrationDiscoveryContext::STATUS_ACTIVE,
            'selected_at' => now(),
        ]);
        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame('bind', $detail['next_action']);

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads']);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
        ]);

        $boundDetail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame(1, $boundDetail['bound']);
        $this->assertSame('not_run', $boundDetail['collection_state']);
        $this->assertSame('none', $boundDetail['data_state']);
        $this->assertSame('collect', $boundDetail['next_action']);
        $this->assertNotSame('Data available', $boundDetail['data_state_label']);
    }

    public function test_app_configured_distinct_from_authorized(): void
    {
        config([
            'moxdop.meta.app_id' => 'app-123',
            'moxdop.meta.app_secret' => 'secret-xyz',
        ]);

        $integration = CoreIntegration::factory()->meta()->create();
        $this->assertSame(MetaAuthStatus::AUTHORIZATION_REQUIRED, MetaAuthStatus::for($integration));

        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertTrue($detail['app_configured']);
        $this->assertSame(MetaAuthStatus::AUTHORIZATION_REQUIRED, $detail['auth_status']);
        $this->assertFalse($detail['credential_summary']['tenant_authorization_present']);
    }

    public function test_read_model_not_configured_and_no_secrets(): void
    {
        $detail = app(MetaIntegrationReadModel::class)->detail();

        $this->assertSame(IntegrationOperatorStatus::NOT_CONFIGURED, $detail['state']);
        $this->assertSame(0, $detail['resources_discovered']);
        $this->assertNull($detail['secrets']);
        $this->assertArrayNotHasKey('access_token', $detail);
        $this->assertArrayNotHasKey('app_secret', $detail);
        app(MetaIntegrationReadModel::class)->assertNoSecrets($detail);
        Http::assertNothingSent();
    }

    public function test_frozen_integrations_hub_uses_real_meta_card(): void
    {
        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertSee('Meta')
            ->assertSee('Not configured');

        $card = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $g) => $g['providers'])
            ->firstWhere('id', 'meta');

        $this->assertSame('real', $card['provenance'] ?? null);
        $this->assertSame(0, $card['resources_discovered']);
        $this->assertSame('demo.integrations.meta', $card['route']);
    }

    public function test_frozen_meta_page_and_hub_render_without_provider_http(): void
    {
        $this->get(route('demo.integrations'))->assertOk();
        $this->get(route('demo.integrations.meta'))
            ->assertOk()
            ->assertSee('Not configured')
            ->assertDontSee('EAAG-')
            ->assertDontSee('sample-access-token');

        Livewire::test(MetaIntegrationPage::class)->assertOk();
        app(MetaIntegrationReadModel::class)->detail();
        app(OperatorIntegrationsHubQuery::class)->groups();

        Http::assertNothingSent();
        $this->assertStringContainsString('/app/integrations/meta', route('demo.integrations.meta', absolute: false));
    }

    public function test_agency_meta_integration_is_provider_unique(): void
    {
        CoreIntegration::factory()->meta()->create();

        $this->expectException(QueryException::class);
        CoreIntegration::factory()->meta()->create();
    }

    public function test_customers_remain_separate_from_meta_resources(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_customer_trap',
        ]);
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_customer_trap',
        ]);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($customer->id, $brand->customer_id);
        $this->assertSame(0, DigitalAsset::query()->count());
    }

    public function test_provider_hierarchy_is_not_binding(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_h',
        ]);
        $account = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_h',
            'parent_external_id' => 'biz_h',
        ]);

        $this->assertSame('biz_h', $account->parent_external_id);
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_graph_api_version_is_centralized(): void
    {
        $this->assertSame('v26.0', MetaApiConfig::DEFAULT_API_VERSION);
        $this->assertStringContainsString('/v26.0', MetaApiConfig::graphBaseUrl());
        config(['moxdop.meta.api_version' => 'not-a-version']);
        $this->assertSame('v26.0', MetaApiConfig::apiVersion());
    }
}
