<?php

namespace Tests\Feature;

use App\Contracts\Integrations\DiscoversProviderResources;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\AssetBindingsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Filament\App\Resources\Integrations\Pages\EditIntegration;
use App\Filament\App\Resources\Integrations\Pages\ListIntegrations;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\ConnectionScope;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CentralIntegrationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

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
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Moximu Website',
        ]);
    }

    public function test_admin_can_create_integration_with_validated_provider_and_status(): void
    {
        $integration = app(IntegrationWorkspaceCatalog::class)
            ->bootstrap(ProviderRegistry::GOOGLE);

        $this->assertSame('Google', $integration->name);
        $this->assertSame(CoreIntegration::STATUS_ACTIVE, $integration->status);
        // Google app credentials are configured only via View → Configure (not create JSON/KeyValue).
        $this->assertSame([], $integration->config ?? []);
        $this->assertFalse($integration->providerCredential()->exists());
        $this->assertFalse($integration->authorizationCredential()->exists());
        $this->assertFalse(IntegrationResource::canCreate());
    }

    public function test_integration_provider_must_be_canonical_and_unique(): void
    {
        $this->assertFalse(ProviderRegistry::isValid('not-a-provider'));
        $this->assertSame(['google', 'meta', 'dataforseo', 'openai', 'anthropic', 'gemini'], array_keys(ProviderRegistry::all()));

        $catalog = app(IntegrationWorkspaceCatalog::class);
        $first = $catalog->bootstrap(ProviderRegistry::GOOGLE);
        $second = $catalog->bootstrap(ProviderRegistry::GOOGLE);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->count());
    }

    public function test_integration_credentials_are_encrypted_and_never_rehydrated_plaintext(): void
    {
        // Generic credentials JSON remains for non-Google providers; Google uses View → Configure only.
        $integration = CoreIntegration::factory()->meta()->create();

        Livewire::test(EditIntegration::class, ['record' => $integration->getRouteKey()])
            ->fillForm([
                'name' => 'Meta',
                'status' => CoreIntegration::STATUS_ACTIVE,
                'config' => [],
                'credentials_json' => json_encode([
                    'app_id' => 'super-app-id',
                    'app_secret' => 'super-secret-client',
                ], JSON_THROW_ON_ERROR),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('super-secret-client', $stored);
        $this->assertSame('super-secret-client', $credential->encrypted_payload['app_secret']);
        $this->assertSame(CoreIntegrationCredential::TYPE_PROVIDER, $credential->credential_type);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());

        Livewire::test(EditIntegration::class, ['record' => $integration->getRouteKey()])
            ->assertSchemaStateSet([
                'credentials_json' => null,
            ]);
    }

    public function test_external_resource_belongs_to_integration_with_uniqueness_and_no_credential_leakage(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['refresh_token' => 'must-not-leak'],
        ]);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'display_name' => 'Moximu GA4',
            'metadata' => ['property_name' => 'Moximu'],
        ]);

        $this->assertTrue($resource->integration->is($integration));
        $this->assertSame('properties/123456', $resource->external_id);
        $this->assertArrayNotHasKey('encrypted_payload', $resource->toArray());
        $this->assertStringNotContainsString('must-not-leak', json_encode($resource->metadata));

        $this->expectException(QueryException::class);

        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'display_name' => 'Duplicate',
        ]);
    }

    public function test_binding_assigns_correct_asset_and_prevents_duplicates(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id]);
        $adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '9999999999',
            'display_name' => 'Ads Prop',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $adsAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $resource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasNoTableActionErrors();

        $binding = CoreAssetBinding::query()->firstOrFail();

        $this->assertSame($adsAsset->id, $binding->digital_asset_id);
        $this->assertNotSame($otherAsset->id, $binding->digital_asset_id);
        $this->assertSame($resource->id, $binding->external_resource_id);
        $this->assertSame('google_ads', $binding->capability);
        $this->assertNull($binding->externalResource->metadata['credentials'] ?? null);

        // Capability already bound — create action is hidden (no unbound compatible resources).
        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $adsAsset,
            'pageClass' => ViewDigitalAsset::class,
        ])->assertTableActionHidden('create');

        $secondResource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '8888888888',
            'display_name' => 'Another Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->expectException(QueryException::class);

        CoreAssetBinding::query()->create([
            'digital_asset_id' => $adsAsset->id,
            'external_resource_id' => $secondResource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ]);
    }

    public function test_binding_select_lists_discovered_resources_not_manual_ids(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'display_name' => 'moximu.com Search Console',
            'external_id' => 'sc-domain:moximu.com',
        ]);

        $this->assertStringContainsString('Search Console', $resource->optionLabel());
        $this->assertStringContainsString('moximu.com Search Console', $resource->optionLabel());
        $this->assertStringContainsString('sc-domain:moximu.com', $resource->optionLabel());

        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $resource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasNoTableActionErrors();

        $binding = CoreAssetBinding::query()->firstOrFail();
        $this->assertSame('search_console', $binding->capability);
        $this->assertSame('sc-domain:moximu.com', $binding->externalResource->external_id);
    }

    public function test_provider_resources_empty_state_explains_discovery_requirement(): void
    {
        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertOk()
            ->assertSee('No provider resources bound')
            ->assertSee('Resource discovery must run on the Integration first')
            ->assertTableActionHidden('create');
    }

    public function test_settings_integrations_lists_real_records_and_empty_state(): void
    {
        Livewire::test(ListIntegrations::class)
            ->assertOk()
            ->assertSee('Integrations')
            ->assertSee('Google')
            ->assertSee('DataForSEO')
            ->assertSee('OpenAI')
            ->assertSee('Set up')
            ->assertDontSee('Add integration')
            ->assertDontSeeHtml('fi-ta-table');

        CoreIntegration::factory()->openai()->create([
            'name' => 'OpenAI',
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        Livewire::test(ListIntegrations::class)
            ->assertOk()
            ->assertSee('OpenAI')
            ->assertSee('Manage')
            ->assertDontSee('Add integration');
    }

    public function test_team_member_cannot_access_integrations(): void
    {
        $teamMember = User::factory()->create();
        $teamMember->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($teamMember);

        $this->assertFalse(IntegrationResource::canAccess());

        $this->get(IntegrationResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_wordpress_asset_scoped_connection_still_works(): void
    {
        $this->assertTrue(ConnectionScope::isAssetScoped('wordpress'));
        $this->assertTrue(ConnectionScope::isProviderLevel('ga4'));

        Livewire::test(ConnectionsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'type' => 'wordpress',
                'name' => 'WordPress App Password',
                'enabled' => true,
                'config' => ['base_url' => 'https://www.moximu.com'],
                'credentials_json' => json_encode([
                    'username' => 'editor',
                    'application_password' => 'wp-app-secret',
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertHasNoTableActionErrors();

        $connection = CoreConnection::query()->where('name', 'WordPress App Password')->firstOrFail();
        $this->assertSame($this->asset->id, $connection->digital_asset_id);
        $this->assertTrue(ConnectionScope::isAssetScoped($connection->type));

        $credential = CoreConnectionCredential::query()
            ->where('connection_id', $connection->id)
            ->firstOrFail();

        $stored = DB::table('core_connection_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertStringNotContainsString('wp-app-secret', (string) $stored);
        $this->assertSame('wp-app-secret', $credential->encrypted_payload['application_password']);
    }

    public function test_discovery_contract_normalizes_resources_without_credentials(): void
    {
        $integration = CoreIntegration::factory()->google()->create();

        $discoverer = new class implements DiscoversProviderResources
        {
            public function provider(): string
            {
                return ProviderRegistry::GOOGLE;
            }

            public function discover(CoreIntegration $integration): array
            {
                return [
                    new DiscoveredExternalResource(
                        resourceType: 'search_console',
                        externalId: 'sc-domain:moximu.com',
                        displayName: 'moximu.com',
                        metadata: ['permission_level' => 'siteFullUser'],
                    ),
                    new DiscoveredExternalResource(
                        resourceType: 'ga4',
                        externalId: 'properties/42',
                        displayName: 'Moximu GA4',
                    ),
                ];
            }
        };

        $discovered = $discoverer->discover($integration);

        $this->assertCount(2, $discovered);
        $this->assertSame('search_console', $discovered[0]->resourceType);
        $this->assertSame('sc-domain:moximu.com', $discovered[0]->externalId);

        foreach ($discovered as $item) {
            $encoded = json_encode($item->metadata);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('token', $encoded);
            $this->assertStringNotContainsString('secret', $encoded);
        }
    }

    public function test_digital_asset_workspace_exposes_provider_resources_and_site_connections(): void
    {
        $relations = DigitalAssetResource::getRelations();

        $this->assertSame(AssetBindingsRelationManager::class, $relations['assetBindings']);
        $this->assertSame(ConnectionsRelationManager::class, $relations['connections']);

        // Meta Ads assets use the productized connection workspace tabs.
        $metaAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $metaAsset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Connections')
            ->assertDontSee('Provider resources')
            ->assertDontSee('Site connections');

        // Google Ads assets use the productized Ads workspace tabs.
        $adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $adsAsset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Performance')
            ->assertSee('Search terms')
            ->assertSee('Intelligence')
            ->assertSee('Connections')
            ->assertSee('Activity')
            ->assertDontSee('Provider resources')
            ->assertDontSee('Site connections');

        // Website assets use the productized workspace tabs.
        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Performance')
            ->assertSee('Connections')
            ->assertDontSee('Provider resources')
            ->assertDontSee('Site connections');
    }

    public function test_provider_registry_lists_canonical_capabilities(): void
    {
        $this->assertTrue(ProviderRegistry::isValid(ProviderRegistry::GOOGLE));
        $this->assertContains('search_console', ProviderRegistry::capabilities(ProviderRegistry::GOOGLE));
        $this->assertContains('ga4', ProviderRegistry::capabilities(ProviderRegistry::GOOGLE));
        $this->assertContains('meta_ads', ProviderRegistry::capabilities(ProviderRegistry::META));
        $this->assertSame('Google', ProviderRegistry::defaultName(ProviderRegistry::GOOGLE));
    }
}
