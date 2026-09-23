<?php

namespace Tests\Feature;

use App\Contracts\Integrations\DiscoversProviderResources;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_integration_provider_must_be_canonical_and_unique(): void
    {
        $this->assertFalse(ProviderRegistry::isValid('not-a-provider'));
        $this->assertSame(['google', 'meta', 'dataforseo', 'openai', 'anthropic', 'gemini', 'groq', 'openrouter'], array_keys(ProviderRegistry::all()));

        $catalog = app(IntegrationWorkspaceCatalog::class);
        $first = $catalog->bootstrap(ProviderRegistry::GOOGLE);
        $second = $catalog->bootstrap(ProviderRegistry::GOOGLE);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->count());
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

    public function test_provider_registry_lists_canonical_capabilities(): void
    {
        $this->assertTrue(ProviderRegistry::isValid(ProviderRegistry::GOOGLE));
        $this->assertContains('search_console', ProviderRegistry::capabilities(ProviderRegistry::GOOGLE));
        $this->assertContains('ga4', ProviderRegistry::capabilities(ProviderRegistry::GOOGLE));
        $this->assertContains('meta_ads', ProviderRegistry::capabilities(ProviderRegistry::META));
        $this->assertSame('Google', ProviderRegistry::defaultName(ProviderRegistry::GOOGLE));
    }
}
