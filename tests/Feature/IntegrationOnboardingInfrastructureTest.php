<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\DigitalAsset;
use App\Models\User;
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
        $this->get(route('operator.integrations'))
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('Meta')
            ->assertSee('DataForSEO')
            ->assertSee('OpenAI');

        $this->get(route('operator.integrations.google'))
            ->assertOk()
            ->assertSee('Connectors');

        $this->get(route('operator.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta Ads Connector');

        foreach (['google-ads', 'ga4', 'gsc', 'gbp', 'meta-ads'] as $connector) {
            $this->get(route('operator.integrations.connector', ['connector' => $connector]))
                ->assertOk()
                ->assertSee('Overview')
                ->assertSee('Resources')
                ->assertSee('Bindings')
                ->assertSee('Data')
                ->assertSee('Sync')
                ->assertSee('Activity');
        }
    }

    public function test_connector_resources_are_empty_until_configured(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'ga4'])
            ->assertSee('Google Analytics')
            ->assertSee('Not configured')
            ->call('setTab', 'resources')
            ->assertDontSee('Atlas Dental GA4')
            ->assertDontSee('Panorama Ankara GA4')
            ->assertDontSee('Recommended match')
            ->call('setTab', 'data')
            ->assertSee('No collection data')
            ->call('setTab', 'sync')
            ->assertSee('Last successful collection')
            ->call('setTab', 'activity');
    }

    public function test_binding_is_blocked_until_integration_is_configured(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'ga4'])
            ->call('setTab', 'resources')
            ->call('openBind', 'ga4-panorama')
            ->assertDontSee('Confirm binding')
            ->assertDontSee('Binding confirmed');

        $this->assertSame([], DemoState::connectorBindings('ga4'));
    }

    public function test_create_asset_then_bind_does_not_seed_fixture_assets(): void
    {
        Livewire::test(ConnectorPage::class, ['connector' => 'gsc'])
            ->call('openBind', 'gsc-panorama')
            ->set('bindMode', 'create')
            ->set('newAssetName', 'Panorama Search Console')
            ->call('prepareConfirm')
            ->call('confirmBinding')
            ->assertDontSee('already exists');

        $this->assertSame([], DemoState::all()['demo_assets'] ?? []);
    }

    public function test_google_integration_links_connectors_and_keeps_disconnect_impact(): void
    {
        // Faz 13: the Connectors tab duplicated Overview; old links land on Overview, which links every connector.
        Livewire::test(GoogleIntegrationPage::class)
            ->assertSee('Bağlı dijital varlıklar')
            ->call('setTab', 'connectors')
            ->assertSet('tab', 'overview')
            ->assertSee('Genel Bakış')
            ->assertDontSee('>Connectors<', false);
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

        Livewire::test(AssetsIndex::class)
            ->set('filterRole', 'infrastructure')
            ->assertDontSee('DemoHost · Atlas Dental');
    }

    public function test_website_infrastructure_tab_and_legacy_routes_preserved(): void
    {
        $website = DigitalAsset::factory()->create(['type' => 'website', 'name' => 'Northwind Website']);

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $website->id, 'tab' => 'infrastructure'])
            ->assertSee('Infrastructure')
            ->assertSee('Domain')
            ->assertSee('DNS')
            ->assertSee('Hosting')
            ->assertSee('SSL / TLS')
            ->assertSee('CMS')
            ->assertSee('not standalone assets');

        $this->get(route('operator.domain'))
            ->assertRedirect(route('operator.assets'));

        $this->get(route('operator.hosting'))
            ->assertRedirect(route('operator.assets'));
    }
}
