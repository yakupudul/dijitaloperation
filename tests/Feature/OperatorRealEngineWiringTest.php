<?php

namespace Tests\Feature;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Livewire\Operator\Website\DataSourcesPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Gbp\GbpOperatorWorkspace;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Sales\IntentSearchConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;
use Tests\TestCase;

class OperatorRealEngineWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_operator_component_cannot_fall_back_to_unavailable_demo_shell(): void
    {
        $source = file_get_contents(app_path('Livewire/Demo/Website/OverviewPage.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('WebsiteOperatorWorkspace', $source);
        $this->assertStringNotContainsString('MoxDop\\Website', $source);
        $this->assertStringNotContainsString('UnavailableWorkspaceShells', $source);
        $this->assertStringNotContainsString('WebsiteWorkspaceFixtures', $source);
        $this->assertInstanceOf(WebsiteOperatorWorkspace::class, app(WebsiteOperatorWorkspace::class));
    }

    public function test_real_public_discovery_and_data_source_routes_are_registered(): void
    {
        $this->assertSame('/public-discovery', route('operator.public-discovery', absolute: false));
        $this->assertSame('/assets/website/42/discovery', route('operator.website.discovery', ['assetId' => 42], absolute: false));
        $this->assertSame('/assets/website/42/sources', route('operator.website.sources', ['assetId' => 42], absolute: false));
    }

    public function test_operator_can_bind_a_discovered_ga4_resource_directly_to_a_website(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'name' => 'Moximu Website',
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'display_name' => 'Moximu GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(DataSourcesPage::class, ['assetId' => (string) $website->id])
            ->set('ga4ResourceId', (string) $resource->id)
            ->call('bindGa4')
            ->assertHasNoErrors()
            ->assertSee('Google Analytics 4');

        $this->assertDatabaseHas('core_asset_bindings', [
            'digital_asset_id' => $website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_gbp_operator_presenter_uses_real_collected_location_evidence(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $gbp = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_business_profile',
            'name' => 'Moximu GBP',
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => GbpLocationBoundCollector::CAPABILITY,
            'external_id' => 'locations/98765',
            'display_name' => 'Moximu',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $gbp->id,
            'external_resource_id' => $resource->id,
            'capability' => GbpLocationBoundCollector::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $run = Run::query()->create([
            'digital_asset_id' => $gbp->id,
            'core_asset_binding_id' => $binding->id,
            'module_id' => GbpLocationBoundCollector::MODULE_ID,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => ['capability' => GbpLocationBoundCollector::CAPABILITY],
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $gbp->id,
            'source_module' => GbpLocationBoundCollector::MODULE_ID,
            'type' => GbpLocationBoundCollector::EVIDENCE_TYPE,
            'title' => 'Google Business Profile location access',
            'payload' => [
                'ok' => true,
                'title' => 'Moximu Dijital Pazarlama',
                'website_uri' => 'https://moximu.com',
                'primary_phone' => '+90 555 111 22 33',
                'primary_category' => 'Marketing agency',
                'storefront_address' => [
                    'address_lines' => ['Example Cad. 1'],
                    'locality' => 'Manisa',
                    'administrative_area' => 'Manisa',
                    'postal_code' => '45000',
                    'region_code' => 'TR',
                ],
            ],
            'observed_at' => now(),
        ]);

        $workspace = app(GbpOperatorWorkspace::class)->for($gbp->fresh(['brand']));

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertSame('Moximu Dijital Pazarlama', $workspace['identity']['title']);
        $this->assertSame('Marketing agency', $workspace['profile']['categories']['primary']);
        $this->assertStringContainsString('Manisa', $workspace['profile']['location']['address']);
        $this->assertTrue($workspace['connection']['bound']);
    }

    public function test_sales_intent_paid_call_policy_can_be_enabled_from_dataforseo_integration_config(): void
    {
        config()->set('moxdop.sales_intent_discovery.paid_calls_enabled', false);

        CoreIntegration::query()->create([
            'provider' => ProviderRegistry::DATAFORSEO,
            'name' => 'DataForSEO',
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [IntentSearchConfig::RUNTIME_PAID_CALLS_KEY => true],
        ]);

        $this->assertTrue(IntentSearchConfig::paidCallsEnabled());
    }

    public function test_sales_intent_runtime_policy_can_explicitly_disable_a_true_deployment_default(): void
    {
        config()->set('moxdop.sales_intent_discovery.paid_calls_enabled', true);

        CoreIntegration::query()->create([
            'provider' => ProviderRegistry::DATAFORSEO,
            'name' => 'DataForSEO',
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [IntentSearchConfig::RUNTIME_PAID_CALLS_KEY => false],
        ]);

        $this->assertFalse(IntentSearchConfig::paidCallsEnabled());
    }
}
