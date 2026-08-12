<?php

namespace Tests\Feature;

use App\Livewire\Demo\GoogleAds\OverviewPage;
use App\Livewire\Demo\Meta\CampaignsPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoProductRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    public function test_guest_is_redirected_from_app_to_system_login(): void
    {
        auth()->logout();

        $this->get('/app')->assertRedirect('/system/login');
    }

    public function test_root_redirects_authenticated_users_to_app(): void
    {
        $this->get('/')->assertRedirect('/app');
    }

    public function test_dashboard_and_portfolio_routes_smoke(): void
    {
        $this->get('/app')
            ->assertOk()
            ->assertSee('Command Center')
            ->assertSee('Demo Mode');

        $this->get(route('demo.customers'))->assertOk()->assertSee('Customers');
        $this->get(route('demo.brands'))->assertOk()->assertSee('Brands');
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID]))
            ->assertOk()
            ->assertSee('Atlas Dental Ankara');
        $this->get(route('demo.assets'))->assertOk()->assertSee('Digital Assets');
    }

    public function test_operations_and_integrations_routes_smoke(): void
    {
        $this->get(route('demo.findings'))->assertOk()->assertSee('Findings');
        $this->get(route('demo.recommendations'))->assertOk()->assertSee('Recommendations');
        $this->get(route('demo.tasks'))->assertOk()->assertSee('Tasks');
        $this->get(route('demo.task', ['taskId' => 't-replace-creative']))->assertOk();
        $this->get(route('demo.activity'))->assertOk()->assertSee('Activity');
        $this->get(route('demo.integrations'))->assertOk()->assertSee('Integrations');
        $this->get(route('demo.integrations.meta'))->assertOk()->assertSee('Meta data import');
        $this->get(route('demo.settings'))->assertOk()->assertSee('Reset Demo Mode');
    }

    public function test_asset_workspace_routes_smoke(): void
    {
        $meta = DemoCatalog::META_ASSET_ID;

        $this->get(route('demo.meta.overview', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('How is paid-media efficiency changing?');
        $this->get(route('demo.meta.campaigns', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Campaigns');
        $this->get(route('demo.meta.adsets', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Ad Sets');
        $this->get(route('demo.meta.ads', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Ads');
        $this->get(route('demo.meta.breakdowns', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Breakdowns')
            ->assertSee('Placement');
        $this->get(route('demo.meta.campaign', ['assetId' => $meta, 'campaignId' => 'camp-pb-eu']))
            ->assertOk()
            ->assertSee('Post Bariatric');
        $this->get(route('demo.meta.creatives', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Creatives');
        $this->get(route('demo.meta.insights', ['assetId' => $meta]))->assertOk();
        $this->get(route('demo.google-ads.overview'))
            ->assertOk()
            ->assertSee('Google Ads')
            ->assertSee('Search terms');
        $this->get(route('demo.website'))->assertOk()->assertSee('Website');
        $this->get(route('demo.gbp'))->assertOk()->assertSee('Google Business Profile');
        $this->get(route('demo.analytics'))->assertOk()->assertSee('Google Analytics');
        $this->get(route('demo.search-console'))->assertOk()->assertSee('Search Console');
    }

    public function test_meta_campaign_filters_and_google_search_term_filter_work(): void
    {
        $meta = DemoCatalog::META_ASSET_ID;

        Livewire::test(CampaignsPage::class, ['assetId' => $meta])
            ->call('setStatusFilter', 'PAUSED')
            ->assertSee('Retargeting — Form')
            ->assertDontSee('Post Bariatric — Europe');

        Livewire::test(OverviewPage::class)
            ->set('tab', 'search_terms')
            ->call('setClassificationFilter', 'Keep')
            ->assertSee('implant ankara fiyat')
            ->assertDontSee('dişçi oyunu');
    }
}
