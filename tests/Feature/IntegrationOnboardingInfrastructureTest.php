<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\ConnectorPage;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Operator\Website\V2\WebsiteScreen;
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

        // Faz 13: Google's overview links every connector (no separate Connectors tab).
        $this->get(route('operator.integrations.google'))
            ->assertOk()
            ->assertSee('Genel Bakış')
            ->assertSee('Google Analytics')
            ->assertSee('Search Console')
            ->assertSee('Google Ads')
            ->assertSee('Google Business Profile');

        $this->get(route('operator.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta Ads Integration')
            ->assertSee('Ad Accounts')
            ->assertSee('Data Collection');

        // GA4, Search Console and Google Ads are central account-based connectors.
        foreach (['ga4' => 'Geçmiş', 'gsc' => 'Geçmiş', 'google-ads' => 'Canlı Akış'] as $connector => $historyTab) {
            $this->get(route('operator.integrations.connector', ['connector' => $connector]))
                ->assertOk()
                ->assertSee('Hesaplar')
                ->assertSee('Toplu işlemler')
                ->assertSee('Veri')
                ->assertSee($historyTab);
        }

        $this->get(route('operator.integrations.connector', ['connector' => 'gbp']))
            ->assertOk()
            ->assertSee('Google Business Profile')
            ->assertSee('Activate the Google connection first.');

        // The generic Meta Ads connector page was folded into the Meta integration page.
        $this->get(route('operator.integrations.connector', ['connector' => 'meta-ads']))
            ->assertRedirect(route('operator.integrations.meta', ['tab' => 'resources']));
    }

    public function test_connector_resources_are_empty_until_configured(): void
    {
        // GA4 is a central account-based connector: no fixtures, no properties and no history until configured.
        Livewire::test(ConnectorPage::class, ['connector' => 'ga4'])
            ->assertSee('Google Analytics')
            ->assertSee('0 mülk bulundu')
            ->call('setTab', 'resources')
            ->assertDontSee('Atlas Dental GA4')
            ->assertDontSee('Panorama Ankara GA4')
            ->assertDontSee('Recommended match')
            ->assertSee('No discovered accounts yet.')
            ->call('setTab', 'data')
            ->assertSee('Merkezi veri havuzu')
            ->call('setTab', 'activity')
            ->assertSee('Henüz GA4 aktarım geçmişi yok.');
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

        // Domain / hosting / TLS stay Website facts (Site Sağlığı), not standalone assets.
        Livewire::test(WebsiteScreen::class, ['assetId' => (string) $website->id, 'tab' => 'infrastructure'])
            ->assertSet('tab', 'saglik')
            ->assertSee('Northwind Website');

        $this->get(route('operator.domain'))
            ->assertRedirect(route('operator.assets'));

        $this->get(route('operator.hosting'))
            ->assertRedirect(route('operator.assets'));
    }
}
