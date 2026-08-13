<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\PortfolioSetupWizard;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\User;
use App\Support\Demo\ConnectorWorkspaceFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationOnboardingInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
    }

    public function test_integrations_hub_and_google_meta_connectors_smoke(): void
    {
        $this->get(route('demo.integrations'))
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('Meta')
            ->assertSee('DataForSEO')
            ->assertSee('OpenAI');

        $this->get(route('demo.integrations.google'))
            ->assertOk()
            ->assertSee('Connectors');

        $this->get(route('demo.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta Ads Connector');

        foreach (['google-ads', 'ga4', 'gsc', 'gbp', 'meta-ads'] as $connector) {
            $this->get(route('demo.integrations.connector', ['connector' => $connector]))
                ->assertOk()
                ->assertSee('Overview')
                ->assertSee('Resources')
                ->assertSee('Bindings')
                ->assertSee('Data')
                ->assertSee('Sync')
                ->assertSee('Activity');
        }
    }

    public function test_connector_resources_bound_available_and_no_analytics_duplication(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'ga4'])
            ->assertSee('Google Analytics')
            ->assertSee('Connection')
            ->call('setTab', 'resources')
            ->assertSee('Atlas Dental GA4')
            ->assertSee('Available')
            ->assertSee('Bound')
            ->assertSee('Panorama Ankara GA4')
            ->assertSee('Recommended match')
            ->call('setTab', 'data')
            ->assertSee('Collection preview')
            ->assertSee('Open Google Analytics Digital Asset')
            ->assertDontSee('Users by country')
            ->assertDontSee('Session exploration')
            ->call('setTab', 'sync')
            ->assertSee('Last successful collection')
            ->call('setTab', 'activity')
            ->assertSee('Collection completed');
    }

    public function test_binding_requires_confirmation_and_rejects_cross_brand(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'ga4'])
            ->call('setTab', 'resources')
            ->call('openBind', 'ga4-panorama')
            ->assertSee('Bind resource')
            ->set('bindMode', 'existing')
            ->set('selectedAssetId', DemoCatalog::GA4_ASSET_ID)
            ->call('prepareConfirm')
            ->assertSee('Confirm binding')
            ->call('confirmBinding')
            ->assertSee('Binding confirmed');

        $bindings = DemoState::connectorBindings('ga4');
        $this->assertSame('bound', $bindings['ga4-panorama']['action']);
        $this->assertSame(DemoCatalog::BRAND_ID, $bindings['ga4-panorama']['brand_id']);
    }

    public function test_create_asset_then_bind_avoids_duplicate_name(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'gsc'])
            ->call('openBind', 'gsc-panorama')
            ->set('bindMode', 'create')
            ->set('newAssetName', 'Panorama Search Console')
            ->call('prepareConfirm')
            ->call('confirmBinding');

        $assets = collect(DemoState::all()['demo_assets']);
        $this->assertTrue($assets->contains(fn (array $a): bool => ($a['name'] ?? '') === 'Panorama Search Console'));

        Livewire::test(ConnectorPage::class, ['connector' => 'gsc'])
            ->call('openBind', 'gsc-horizon')
            ->set('bindMode', 'create')
            ->set('newAssetName', 'Panorama Search Console')
            ->call('prepareConfirm')
            ->call('confirmBinding')
            ->assertSee('already exists');
    }

    public function test_google_integration_links_connectors_and_keeps_disconnect_impact(): void
    {
        Livewire::test(GoogleIntegrationPage::class)
            ->call('setTab', 'connectors')
            ->assertSee('Google Ads Connector')
            ->assertSee('Google Analytics Connector')
            ->assertSee('Search Console Connector')
            ->assertSee('Google Business Profile Connector')
            ->call('openDisconnect')
            ->assertSee('14');
    }

    public function test_portfolio_setup_wizard_entry_points_and_flow(): void
    {
        Livewire::test(PortfolioSetupWizard::class, ['entry' => 'customer'])
            ->assertSee('Portfolio Setup Wizard')
            ->assertSee('Customer')
            ->set('customer_name', 'Atlas Group Demo')
            ->set('contact_name', 'Yakup')
            ->call('next')
            ->assertSet('step', 2)
            ->set('brand_name', 'Atlas Dental Wizard')
            ->set('website_url', 'https://atlasdental.example')
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('Domain and Hosting are Website infrastructure')
            ->assertSee('Google Analytics')
            ->assertSee('Search Console')
            ->call('toggleAsset', 'ga4')
            ->call('toggleAsset', 'gsc')
            ->call('toggleAsset', 'gbp')
            ->call('next')
            ->assertSet('step', 4)
            ->assertSee('Connect & Match')
            ->assertSee('Recommended')
            ->call('selectResource', 'ga4', 'ga4-atlas')
            ->call('selectResource', 'gsc', 'gsc-atlas')
            ->call('selectResource', 'gbp', 'gbp-atlas')
            ->call('next')
            ->assertSet('step', 5)
            ->assertSee('Discover & Review')
            ->assertSee('Dental Implant')
            ->call('toggleCandidate', 'dc-offering-implant')
            ->call('next')
            ->assertSet('step', 6)
            ->assertSee('is ready')
            ->assertSee('Open Brand')
            ->assertSee('Setup incomplete ≠ Brand unhealthy');
    }

    public function test_wizard_add_brand_and_asset_entry_points(): void
    {
        Livewire::test(PortfolioSetupWizard::class, ['entry' => 'brand'])
            ->assertSet('step', 2)
            ->assertSee('Brand');

        Livewire::test(PortfolioSetupWizard::class, ['entry' => 'asset'])
            ->assertSet('step', 3)
            ->assertSee('Digital Assets');
    }

    public function test_wizard_back_preserves_selections_and_skip_works(): void
    {
        Livewire::test(PortfolioSetupWizard::class, ['entry' => 'asset'])
            ->call('toggleAsset', 'meta_ads')
            ->call('next')
            ->assertSet('step', 4)
            ->call('skipProvider', 'meta_ads')
            ->assertSee('Skipped')
            ->call('back')
            ->assertSet('step', 3)
            ->assertSee('Meta Ads');
    }

    public function test_domain_hosting_not_selectable_and_hidden_from_directory(): void
    {
        $create = Livewire::test(AssetCreate::class);
        $options = $create->viewData('typeOptions');
        $this->assertArrayNotHasKey('domain', $options);
        $this->assertArrayNotHasKey('hosting', $options);

        Livewire::test(AssetsIndex::class)
            ->assertDontSee('DemoHost · Atlas Dental')
            ->assertDontSee('Domain (legacy)');

        // Legacy still reachable via explicit filter
        Livewire::test(AssetsIndex::class)
            ->set('filterRole', 'infrastructure')
            ->assertSee('DemoHost · Atlas Dental');
    }

    public function test_website_infrastructure_tab_and_legacy_routes_preserved(): void
    {
        Livewire::test(WebsiteOverviewPage::class, ['tab' => 'infrastructure'])
            ->assertSee('Infrastructure')
            ->assertSee('Domain')
            ->assertSee('DNS')
            ->assertSee('Hosting')
            ->assertSee('SSL / TLS')
            ->assertSee('CMS')
            ->assertSee('not standalone assets');

        $this->get(route('demo.domain'))
            ->assertOk()
            ->assertSee('Legacy Domain workspace');

        $this->get(route('demo.hosting'))
            ->assertOk()
            ->assertSee('Legacy Hosting workspace');
    }

    public function test_connector_fixtures_are_deterministic(): void
    {
        $a = ConnectorWorkspaceFixtures::ga4();
        $b = ConnectorWorkspaceFixtures::ga4();
        $this->assertSame($a['resources'], $b['resources']);
        $this->assertSame(count(ConnectorWorkspaceFixtures::ids()), 5);
    }
}
