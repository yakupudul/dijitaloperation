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
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
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

    public function test_guest_is_redirected_from_app_to_app_login(): void
    {
        auth()->logout();

        $this->get('/app')->assertRedirect('/app/login');
    }

    public function test_root_redirects_authenticated_users_to_app(): void
    {
        $this->get('/')->assertRedirect('/app');
    }

    public function test_dashboard_and_portfolio_routes_smoke(): void
    {
        $this->get('/app')
            ->assertOk()
            ->assertSee(__('operator.dashboard_exec.needs_attention'))
            ->assertSee('My Work')
            ->assertSee('Agency')
            ->assertSee('Recent Outcomes')
            ->assertDontSee('Agency Health');

        $this->get(route('operator.customers'))->assertOk()->assertSee('Customers');
        $this->get(route('operator.brands'))->assertOk()->assertSee('Brands');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id]))
            ->assertOk()
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Digital estate');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id, 'tab' => 'discovery']))
            ->assertOk()
            ->assertSee('Public Discovery');
        $this->get(route('operator.brand', ['brand' => $this->workBrand->id, 'tab' => 'research']))
            ->assertOk()
            ->assertSee('Public Discovery');
        $this->get(route('operator.assets'))
            ->assertOk()
            ->assertSee('Digital Assets')
            ->assertSee('Managed Assets')
            ->assertSee('Estate Matrix')
            ->assertSee('Atlas Dental Website');
    }

    public function test_operations_and_integrations_routes_smoke(): void
    {
        $this->get(route('operator.findings'))
            ->assertOk()
            ->assertSee('Findings')
            ->assertSee('Critical')
            ->assertSee('No Findings yet')
            ->assertDontSee('Meta CPL deteriorated');
        $this->get(route('operator.recommendations'))
            ->assertOk()
            ->assertSee('Recommendations')
            ->assertSee('Awaiting Decision')
            ->assertDontSee('Review conversion mapping');
        $this->get(route('operator.tasks'))
            ->assertOk()
            ->assertSee(__('operator.work.title'))
            ->assertSee(__('operator.work.views.my'))
            ->assertSee(__('operator.work.views.tasks'));
        $this->get(route('operator.task', ['taskId' => 't-replace-creative']))
            ->assertNotFound();
        $this->get(route('operator.activity'))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('No activity matches this view');
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
            ->assertSee('Resources & Bindings')
            ->assertSee('Dependent Digital Assets')
            ->assertSee('Not configured');
        $this->get(route('operator.integrations.google', ['tab' => 'resources']))
            ->assertOk()
            ->assertSee('Available / unbound')
            ->assertSee('No resources discovered yet')
            ->assertDontSee('Panorama Ankara GA4');
        $this->get(route('operator.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta')
            ->assertSee('Resources & Bindings')
            ->assertSee('Not configured')
            ->assertSee('State separation')
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
            ->assertSee('Overview')
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

        Livewire::test(CampaignsPage::class, ['assetId' => (string) $meta->id])
            ->assertOk()
            ->assertDontSee('Retargeting — Form')
            ->assertDontSee('Post Bariatric — Diaspora Lead');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $gads->id])
            ->set('tab', 'search_terms')
            ->assertSet('tab', 'search_demand')
            ->assertDontSee('post bariatric dental turkey')
            ->assertDontSee('dental nurse jobs ankara');
    }

    public function test_operations_filters_actions_and_meta_import_groups_work(): void
    {
        DemoState::reset();

        $asset = DigitalAsset::factory()->create(['type' => 'meta_ads', 'name' => 'Meta Ads Account']);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'severity' => 'critical',
            'status' => Finding::STATUS_OPEN,
            'title' => 'Meta CPL deteriorated',
            'category' => 'performance',
        ]);
        Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'severity' => 'medium',
            'status' => Finding::STATUS_OPEN,
            'title' => 'Creative frequency elevated',
            'category' => 'creative',
        ]);

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
            ->call('expand', (string) Finding::query()->where('title', 'Meta CPL deteriorated')->value('id'))
            ->assertSee('What happened');

        $recommendation = Recommendation::factory()->create([
            'title' => 'Replace underperforming creative',
            'status' => Recommendation::STATUS_OPEN,
            'digital_asset_id' => $this->workAsset->id,
        ]);

        Livewire::test(RecommendationsIndex::class)
            ->call('approve', (string) $recommendation->id)
            ->assertSee('accepted')
            ->call('createTask', (string) $recommendation->id)
            ->assertSee('created from Recommendation');

        $this->assertSame(1, Task::query()->where('recommendation_id', $recommendation->id)->count());

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
            ->assertStatus(404);

        Livewire::test(MetaIntegrationPage::class)
            ->assertSee('Not configured')
            ->assertSee('Meta Businesses')
            ->assertSee('Ad Accounts discovered')
            ->assertSee('Authorization plane for Meta Ads')
            ->assertDontSee('Import all Meta data')
            ->call('setTab', 'resources')
            ->assertSee('Ad Accounts')
            ->assertSee('No unbound Ad Accounts in inventory')
            ->assertSee('Connected Ad Accounts');
    }

    public function test_website_severity_and_gbp_keyword_filters_work(): void
    {
        Livewire::test(WebsiteOverviewPage::class)->assertStatus(404);
        Livewire::test(GbpOverviewPage::class)->assertStatus(404);

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $this->workAsset->id])
            ->set('tab', 'health')
            ->assertDontSee('27 service pages have no self-referencing canonical');

        $gbp = DigitalAsset::factory()->create([
            'brand_id' => $this->workBrand->id,
            'type' => 'google_business_profile',
        ]);
        Livewire::test(GbpOverviewPage::class, ['assetId' => (string) $gbp->id])
            ->assertDontSee('çankaya diş kliniği')
            ->assertDontSee('zirkonyum ankara');
    }
}
