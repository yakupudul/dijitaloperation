<?php

namespace Tests\Feature;

use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Operator\GoogleAds\OverviewPage;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsCanonicalWorkTasks;
use Tests\TestCase;

class DemoProductRoutesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCanonicalWorkTasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $this->seedCanonicalWorkTasks();
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_dashboard_is_the_site_root(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_dashboard_and_portfolio_routes_smoke(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('operator.dashboard_exec.today'))
            ->assertDontSee('Agency Health');

        $this->get(route('operator.customers'))->assertOk()->assertSee('Customers');
        $this->get(route('operator.brands'))->assertOk()->assertSee('Brands');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id]))
            ->assertOk()
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Açık işler')
            ->assertSee('Dijital varlıklar')
            ->assertSee('Ayarlar');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id, 'tab' => 'ayarlar']))->assertOk()->assertSee('Dijital varlıklar');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id, 'tab' => 'discovery']))
            ->assertOk()
            ->assertSee('İş bağlamı');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id, 'tab' => 'research']))
            ->assertOk()
            ->assertSee('İş bağlamı');
        $this->get(route('operator.assets'))
            ->assertOk()
            ->assertSee('Dijital varlıklar')
            ->assertSee('Marka × kanal')
            ->assertSee('Atlas Dental Website');
    }

    public function test_operations_and_integrations_routes_smoke(): void
    {
        $this->get(route('operator.integrations'))
            ->assertOk()
            ->assertSee('Integrations')
            ->assertSee('Platforms & Data')
            ->assertSee('Intelligence Providers')
            ->assertSee('Google')
            ->assertSee('Meta');
        $this->get(route('operator.integrations.google'))
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('Hesaplar')
            ->assertSee('Bağlı dijital varlıklar')
            ->assertSee('Not configured');
        $this->get(route('operator.integrations.google', ['tab' => 'resources']))
            ->assertOk()
            ->assertSee('Bağlanmamış hesaplar')
            ->assertSee('No resources discovered yet')
            ->assertSee('Henüz bağlanmış hesap yok.')
            ->assertDontSee('Panorama Ankara GA4');
        $this->get(route('operator.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta')
            ->assertSee('Ad Accounts')
            ->assertSee('Not configured')
            // Business Portfolio (discovery) and Ad Account (analytics) stay separate.
            ->assertSee('Collection and reporting operate at Ad Account level.')
            ->assertDontSee('Import all Meta data')
            ->assertDontSee('Meta data import');
        $this->get(route('operator.settings'))
            ->assertOk()
            ->assertSee('General')
            ->assertSee('Team & Access')
            ->assertSee('AI & Intelligence');
        $this->get(route('operator.settings', ['section' => 'advanced']))
            ->assertOk()
            ->assertDontSee('Reset Demo Mode')
            ->assertDontSee('>Modules</');
    }

    public function test_asset_workspace_routes_smoke(): void
    {
        $this->get(route('operator.meta.overview', ['assetId' => DemoCatalog::META_ASSET_ID]))->assertNotFound();
        $this->get(route('operator.google-ads.overview'))->assertNotFound();
        $this->get(route('operator.website'))->assertNotFound();
        $this->get(route('operator.gbp'))->assertNotFound();
        $this->get(route('operator.analytics'))->assertNotFound();
        $this->get(route('operator.search-console'))->assertNotFound();
        $this->get(route('operator.domain'))->assertRedirect(route('operator.assets'));
        $this->get(route('operator.hosting'))->assertRedirect(route('operator.assets'));

        $meta = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'meta_ads',
            'name' => 'Nova Meta',
            'module_id' => 'meta-ads',
        ]);
        $gads = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'google_ads',
            'name' => 'Nova Google Ads',
            'module_id' => 'google-ads',
        ]);
        $website = $this->workAsset;
        $gbp = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'google_business_profile',
            'name' => 'Nova GBP',
        ]);
        $ga4 = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'ga4',
            'name' => 'Nova GA4',
            'module_id' => 'analytics',
        ]);
        $gsc = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'gsc',
            'name' => 'Nova GSC',
            'module_id' => 'search-console',
        ]);

        $this->get(route('operator.meta.overview', ['assetId' => $meta->id]))
            ->assertOk()
            ->assertSee('Genel Bakış')
            ->assertDontSee('Post Bariatric')
            ->assertDontSee('Atlas Health — Europe');
        $this->get(route('operator.google-ads.overview', ['assetId' => $gads->id]))
            ->assertOk()
            ->assertSee('Google Ads')
            ->assertDontSee('Atlas Dental — Europe');
        $this->get(route('operator.website', ['assetId' => $website->id]))
            ->assertOk()
            ->assertSee('Atlas Dental Website');
        $this->get(route('operator.gbp', ['assetId' => $gbp->id]))
            ->assertOk()
            ->assertSee('Google Business Profile')
            ->assertDontSee('Demo local rank tracking');
        $this->get(route('operator.analytics', ['assetId' => $ga4->id]))
            ->assertOk()
            ->assertSee('Google Analytics')
            ->assertDontSee('Atlas Dental — GA4');
        $this->get(route('operator.search-console', ['assetId' => $gsc->id]))
            ->assertOk()
            ->assertSee('Google Search Console')
            ->assertDontSee('Atlas Dental — Search Console');
    }

    public function test_meta_campaign_filters_and_google_search_term_filter_work(): void
    {
        $meta = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $gads = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'google_ads',
            'module_id' => 'google-ads',
        ]);

        $this->get(route('operator.meta.campaigns', ['assetId' => $meta->id]))
            ->assertRedirect(route('operator.meta.overview', ['assetId' => (string) $meta->id, 'tab' => 'campaigns']));

        Livewire::test(OverviewPage::class, ['assetId' => (string) $gads->id])
            ->set('tab', 'search_terms')
            ->assertSet('tab', 'terms')
            ->assertDontSee('post bariatric dental turkey')
            ->assertDontSee('dental nurse jobs ankara');
    }

    public function test_meta_import_groups_work(): void
    {
        DemoState::reset();

        Livewire::test(MetaIntegrationPage::class)
            ->assertSee('Not configured')
            ->assertSee('Businesses discovered')
            ->assertSee('Ad accounts discovered')
            ->assertSee('Connection Health')
            ->assertDontSee('Import all Meta data')
            ->call('setTab', 'resources')
            ->assertSee('Available Ad Accounts')
            ->assertSee('No unbound ad accounts')
            ->assertSee('Connected Ad Accounts');
    }

    public function test_gbp_keyword_filters_work(): void
    {
        Livewire::test(GbpOverviewPage::class)->assertStatus(404);

        $gbp = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'google_business_profile',
        ]);
        Livewire::test(GbpOverviewPage::class, ['assetId' => (string) $gbp->id])
            ->assertDontSee('çankaya diş kliniği')
            ->assertDontSee('zirkonyum ankara');
    }
}
