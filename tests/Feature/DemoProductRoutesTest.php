<?php

namespace Tests\Feature;

use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\GoogleAds\OverviewPage;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Demo\Meta\CampaignsPage;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Operations\TaskShow;
use App\Livewire\Demo\Operations\TasksIndex;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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
            ->assertSee('Needs your attention')
            ->assertSee('My Work')
            ->assertSee('Agency')
            ->assertSee('Recent outcomes')
            ->assertDontSee('Agency Health');

        $this->get(route('demo.customers'))->assertOk()->assertSee('Customers');
        $this->get(route('demo.brands'))->assertOk()->assertSee('Brands');
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID]))
            ->assertOk()
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Needs attention')
            ->assertSee('Digital estate');
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'discovery']))
            ->assertOk()
            ->assertSee('Public Discovery');
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'research']))
            ->assertOk()
            ->assertSee('Public Discovery');
        $this->get(route('demo.assets'))
            ->assertOk()
            ->assertSee('Digital Assets')
            ->assertSee('Managed Assets')
            ->assertSee('Estate Matrix')
            ->assertSee('Atlas Dental — GA4')
            ->assertSee('Atlas Dental — Search Console');
    }

    public function test_operations_and_integrations_routes_smoke(): void
    {
        $this->get(route('demo.findings'))
            ->assertOk()
            ->assertSee('Findings')
            ->assertSee('Critical')
            ->assertSee('Lead measurement requires investigation')
            ->assertSee('Meta CPL deteriorated');
        $this->get(route('demo.recommendations'))
            ->assertOk()
            ->assertSee('Recommendations')
            ->assertSee('Awaiting Decision')
            ->assertSee('Review conversion mapping');
        $this->get(route('demo.tasks'))
            ->assertOk()
            ->assertSee('Tasks')
            ->assertSee('My Tasks')
            ->assertSee('Board');
        $this->get(route('demo.task', ['taskId' => 't-replace-creative']))
            ->assertOk()
            ->assertSee('WHY')
            ->assertSee('DO')
            ->assertSee('MEASURE')
            ->assertSee('FOLLOW-UP');
        $this->get(route('demo.activity'))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('Google Analytics collection completed')
            ->assertSee('Recommendation accepted');
        $this->get(route('demo.integrations'))
            ->assertOk()
            ->assertSee('Integrations')
            ->assertSee('Platforms & Data')
            ->assertSee('Intelligence Providers')
            ->assertSee('Google')
            ->assertSee('Meta');
        $this->get(route('demo.integrations.google'))
            ->assertOk()
            ->assertSee('Google')
            ->assertSee('Resources & Bindings')
            ->assertSee('Dependent Digital Assets');
        $this->get(route('demo.integrations.google', ['tab' => 'resources']))
            ->assertOk()
            ->assertSee('Available / unbound')
            ->assertSee('Panorama Ankara GA4');
        $this->get(route('demo.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta data import')
            ->assertSee('Import all Meta data')
            ->assertSee('Ready')
            ->assertSee('Needs attention');
        $this->get(route('demo.settings'))
            ->assertOk()
            ->assertSee('General')
            ->assertSee('Team & Access')
            ->assertSee('AI & Intelligence');
        $this->get(route('demo.settings', ['section' => 'advanced']))
            ->assertOk()
            ->assertSee('Reset Demo Mode')
            ->assertDontSee('>Modules</');
    }

    public function test_asset_workspace_routes_smoke(): void
    {
        $meta = DemoCatalog::META_ASSET_ID;

        $this->get(route('demo.meta.overview', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Atlas Health — Europe')
            ->assertSee('Needs attention')
            ->assertSee('Result Mix');
        $this->get(route('demo.meta.overview', ['assetId' => $meta, 'tab' => 'campaigns']))
            ->assertOk()
            ->assertSee('Campaigns')
            ->assertSee('Post Bariatric');
        $this->get(route('demo.meta.campaigns', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Campaigns');
        $this->get(route('demo.meta.adsets', ['assetId' => $meta]))
            ->assertRedirect();
        $this->get(route('demo.meta.ads', ['assetId' => $meta]))
            ->assertRedirect();
        $this->get(route('demo.meta.breakdowns', ['assetId' => $meta]))
            ->assertRedirect();
        $this->followingRedirects()
            ->get(route('demo.meta.breakdowns', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Audience')
            ->assertSee('Placement');
        $this->get(route('demo.meta.campaign', ['assetId' => $meta, 'campaignId' => 'camp-pb-eu']))
            ->assertOk()
            ->assertSee('Post Bariatric')
            ->assertSee('Strategy')
            ->assertSee('Ad Sets');
        $this->get(route('demo.meta.creatives', ['assetId' => $meta]))
            ->assertRedirect();
        $this->followingRedirects()
            ->get(route('demo.meta.creatives', ['assetId' => $meta]))
            ->assertOk()
            ->assertSee('Creatives');
        $this->get(route('demo.meta.insights', ['assetId' => $meta]))->assertRedirect();
        $this->get(route('demo.meta.overview', ['assetId' => $meta, 'tab' => 'funnel']))
            ->assertOk()
            ->assertSee('Funnel')
            ->assertSee('Instant Form');
        $this->get(route('demo.meta.overview', ['assetId' => $meta, 'tab' => 'measurement']))
            ->assertOk()
            ->assertSee('Measurement')
            ->assertSee('Missing ≠ zero');
        $this->get(route('demo.meta.overview', ['assetId' => $meta, 'tab' => 'operations']))
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('Findings');
        $this->get(route('demo.google-ads.overview'))
            ->assertOk()
            ->assertSee('Google Ads')
            ->assertSee('Atlas Dental — Europe')
            ->assertSee('Search & Demand');
        $this->get(route('demo.google-ads.overview', ['tab' => 'search_demand']))
            ->assertOk()
            ->assertSee('Search & demand');
        $this->get(route('demo.website'))
            ->assertOk()
            ->assertSee('Atlas Dental Website')
            ->assertSee('Needs attention');
        $this->get(route('demo.website', ['tab' => 'health']))
            ->assertOk()
            ->assertSee('Website health')
            ->assertSee('checks evaluated');
        $this->get(route('demo.website', ['tab' => 'performance']))
            ->assertOk()
            ->assertSee('FIELD vitals')
            ->assertSee('LAB vitals');
        $this->get(route('demo.website', ['tab' => 'technical']))
            ->assertOk()
            ->assertSee('Website health');
        $this->get(route('demo.gbp'))
            ->assertOk()
            ->assertSee('Google Business Profile');
        $this->get(route('demo.gbp', ['tab' => 'visibility']))
            ->assertOk()
            ->assertSee('Local visibility')
            ->assertSee('Demo local rank tracking');
        $this->get(route('demo.analytics'))
            ->assertOk()
            ->assertSee('Google Analytics')
            ->assertSee('Atlas Dental — GA4')
            ->assertSee('Measurement')
            ->assertSee('Relationships');
        $this->get(route('demo.search-console'))
            ->assertOk()
            ->assertSee('Google Search Console')
            ->assertSee('Atlas Dental — Search Console')
            ->assertSee('Queries & Demand')
            ->assertSee('Relationships');
        $this->get(route('demo.domain'))
            ->assertOk()
            ->assertSee('Domain')
            ->assertSee('atlasdental.example');
        $this->get(route('demo.hosting'))
            ->assertOk()
            ->assertSee('Hosting')
            ->assertSee('DemoHost');
    }

    public function test_meta_campaign_filters_and_google_search_term_filter_work(): void
    {
        $meta = DemoCatalog::META_ASSET_ID;

        Livewire::test(CampaignsPage::class, ['assetId' => $meta])
            ->call('setStatusFilter', 'PAUSED')
            ->assertSee('Retargeting — Form')
            ->assertDontSee('Post Bariatric — Diaspora Lead');

        Livewire::test(OverviewPage::class)
            ->set('tab', 'search_terms')
            ->assertSet('tab', 'search_demand')
            ->call('setClassificationFilter', 'Keep')
            ->assertSee('post bariatric dental turkey')
            ->assertDontSee('dental nurse jobs ankara');
    }

    public function test_operations_filters_actions_and_meta_import_groups_work(): void
    {
        DemoState::reset();

        Livewire::test(FindingsIndex::class)
            ->assertSee('Critical')
            ->assertSee('Meta CPL deteriorated')
            ->call('setSeverity', 'critical')
            ->assertSee('Meta CPL deteriorated')
            ->assertDontSee('Creative frequency elevated')
            ->call('setAssetType', 'google_ads')
            ->assertDontSee('Meta CPL deteriorated')
            ->call('setSeverity', 'all')
            ->call('setAssetType', 'all')
            ->call('expand', 'f-meta-cpl')
            ->assertSee('Why it matters');

        Livewire::test(RecommendationsIndex::class)
            ->call('approve', 'r-replace-creative')
            ->assertSee('accepted')
            ->call('createTask', 'r-fix-lcp')
            ->assertSee('Task created');

        Livewire::test(TasksIndex::class)
            ->call('setView', 'all')
            ->assertSee('Replace PB-Video-03 creative')
            ->call('setStatus', 'blocked')
            ->assertSee('Clear unanswered GBP review backlog')
            ->assertDontSee('Replace PB-Video-03 creative')
            ->call('setStatus', 'all')
            ->call('setViewMode', 'board')
            ->assertSee('In progress')
            ->assertSee('Blocked');

        Livewire::test(TaskShow::class, ['taskId' => 't-replace-creative'])
            ->assertSee('WHY')
            ->assertSee('FOLLOW-UP')
            ->call('setStatus', 'completed')
            ->assertSee('improvement observed');

        Livewire::test(MetaIntegrationPage::class)
            ->assertSee('Ready')
            ->assertSee('Importing')
            ->assertSee('Queued')
            ->assertSee('Needs attention')
            ->assertSee('Import all Meta data')
            ->call('expandAccount', 'acc-atlas')
            ->assertSee('Daily facts');
    }

    public function test_website_severity_and_gbp_keyword_filters_work(): void
    {
        Livewire::test(WebsiteOverviewPage::class)
            ->set('tab', 'health')
            ->call('setSeverity', 'high')
            ->assertSee('27 service pages have no self-referencing canonical')
            ->assertDontSee('Missing Content-Security-Policy header');

        Livewire::test(GbpOverviewPage::class)
            ->call('setPerfSub', 'queries')
            ->assertSee('Search queries')
            ->assertSee('çankaya diş kliniği')
            ->call('setKeyword', 'çankaya diş kliniği')
            ->assertSet('tab', 'visibility')
            ->assertSee('Local visibility')
            ->assertDontSee('zirkonyum ankara');
    }
}
