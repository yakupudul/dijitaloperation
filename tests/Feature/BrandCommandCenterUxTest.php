<?php

namespace Tests\Feature;

use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsCanonicalWorkTasks;
use Tests\TestCase;

class BrandCommandCenterUxTest extends TestCase
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

        DemoState::reset();
        $this->seedCanonicalWorkTasks();
    }

    public function test_brands_directory_search_filters_and_rollups(): void
    {
        Livewire::test(BrandsIndex::class)
            ->assertSee('Brands')
            ->assertSee('Brands managed across customer accounts')
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Atlas Health Group')
            ->assertSee('digital assets')
            ->assertSee(__('operator.portfolio.add_brand_wizard'))
            ->assertSee(route('demo.setup', ['entry' => 'brand'], absolute: false))
            ->set('search', 'Atlas Dental')
            ->assertSee('Atlas Dental Ankara')
            ->set('search', 'NoSuchBrandXYZ')
            ->assertSee('No brands match these filters.')
            ->call('clearFilters')
            ->set('customer', DemoCatalog::CUSTOMER_ID)
            ->assertSee('Atlas Dental Ankara')
            ->set('sector', 'dental')
            ->assertSee('Atlas Dental Ankara')
            ->set('primary_market', 'TR')
            ->assertSee('Atlas Dental Ankara')
            ->set('asset_type', 'meta_ads')
            ->assertSee('Atlas Dental Ankara')
            ->set('responsible', 'u-ayse')
            ->assertSee('Atlas Dental Ankara')
            ->set('attention', 'needs_attention')
            ->assertSee('Needs attention')
            ->set('context', 'complete')
            ->assertSee('Atlas Dental Ankara')
            ->call('sortBy', 'findings')
            ->assertSee('Atlas Dental Ankara');
    }

    public function test_brands_directory_cta_is_localized(): void
    {
        app()->setLocale('tr');

        Livewire::test(BrandsIndex::class)
            ->assertSee(__('operator.portfolio.add_brand_wizard'))
            ->assertSee(route('demo.setup', ['entry' => 'brand'], absolute: false));
    }

    public function test_brands_directory_prevents_empty_onboarding_when_filtered(): void
    {
        Livewire::test(BrandsIndex::class)
            ->set('search', 'zzzz-no-match')
            ->assertSee('No brands match these filters.')
            ->assertDontSee('No brands yet');
    }

    public function test_brand_overview_command_center(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Atlas Health Group')
            ->assertSee('Needs attention')
            ->assertSee('Current priorities')
            ->assertSee('Digital estate')
            ->assertSee('Business context')
            ->assertSee('Cross-channel')
            ->assertSee('Recent decisions')
            ->assertSee('Recent activity')
            ->assertSee('Add digital asset')
            ->assertSee('Edit brand')
            ->assertSee('Open customer')
            ->assertSee('Business context · 6/8')
            ->assertDontSee('Brand Health')
            ->assertDontSee('Cross-channel summary')
            ->assertDontSee('Media spend');
    }

    public function test_brand_tabs_and_operations_subviews(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'assets')
            ->assertSee('Digital assets')
            ->assertSee('atlasdental.example')
            ->assertSee('Atlas Dental — Meta')
            ->call('setTab', 'cross_channel')
            ->assertSee('Evidence-based consistency checks')
            ->assertSee('Website ↔ Google Ads')
            ->assertSee('Not configured')
            ->call('setTab', 'context')
            ->assertSee('6 of 8 key areas completed')
            ->assertSee('Operator maintained')
            ->assertSee('Dental implants')
            ->call('setTab', 'operations')
            ->assertSee('Findings, decisions and active work')
            ->call('setOps', 'findings')
            ->assertSee('Meta CPL deteriorated')
            ->call('setOps', 'recommendations')
            ->assertSee('Replace underperforming Meta creative')
            ->call('setOps', 'tasks')
            ->assertSee('Improve /implant mobile LCP')
            ->call('setOps', 'outcomes')
            ->assertSee('Improvement observed')
            ->assertDontSee('This task fixed the issue');
    }

    public function test_public_discovery_candidates_and_human_review(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'discovery')
            ->assertSet('tab', 'business')
            ->assertSet('businessSection', 'discovery')
            ->assertSee('Public Discovery')
            ->assertSee('Observed Facts')
            ->assertSee('Candidates')
            ->assertSee('Conflicts')
            ->assertSee('Sources & History')
            ->assertSee('Observe public Brand identity')
            ->call('runPublicResearch')
            ->assertSet('tab', 'business')
            ->assertSet('businessSection', 'discovery')
            ->assertSee('Observed facts')
            ->assertSee('Awaiting review')
            ->assertSee('Public identity')
            ->assertSee('Dental Implant')
            ->call('setDiscovery', 'candidates')
            ->call('openCandidate', 'dc-offering-implant')
            ->assertSee('Map to existing')
            ->call('mapDiscoveryCandidate', 'dc-offering-implant', 'Implant Treatment')
            ->assertSee('mapped')
            ->call('openCandidate', 'dc-location-cankaya')
            ->call('acceptDiscoveryCandidate', 'dc-location-cankaya')
            ->assertSee('accepted')
            ->set('ignoreReason', 'irrelevant')
            ->call('openCandidate', 'dc-positioning')
            ->call('ignoreDiscoveryCandidate', 'dc-positioning')
            ->assertSee('ignored')
            ->assertDontSee('AI confidence 87')
            ->assertDontSee('Discovery Score');
    }

    public function test_brand_ai_scope_disclosure_without_dead_run_button_for_demo_brand(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'ai')
            ->assertSet('tab', 'growth')
            ->assertSee(__('operator.brand.tabs.growth'))
            ->assertSee('Analysis context')
            ->assertSee('Business Context')
            ->assertSee('Available')
            ->assertSee('Not connected')
            ->assertSee('Instagram')
            ->assertSee('Executive summary')
            ->assertSee(__('operator.opportunities.growth_observations'))
            ->assertSee('Unknowns / limitations')
            ->assertSee('Demo Mode')
            ->assertSee('Create recommendation')
            ->call('createRecommendationFromPriority', 0)
            ->assertSet('tab', 'operations')
            ->assertSet('ops', 'recommendations')
            ->assertSee('Replace underperforming Meta creative');
    }

    public function test_decision_history_chains_and_activity_separation(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'history')
            ->assertSet('tab', 'value')
            ->call('setValueSection', 'decisions')
            ->assertSee(__('operator.value.decision_history'))
            ->assertSee('Expand implant organic content coverage')
            ->assertSee('Replace underperforming Meta creative PB-Video-03')
            ->assertDontSee('Google Ads sync completed')
            ->assertSee(__('operator.value.decision_vs_activity'));
    }

    public function test_brand_scope_does_not_leak_other_brand_assets(): void
    {
        $state = DemoState::all();
        $state['brands'][] = [
            'id' => 'other-brand',
            'customer_id' => DemoCatalog::CUSTOMER_ID,
            'name' => 'Other Brand Leak Test',
            'sector' => 'dental',
            'primary_country' => 'TR',
            'target_markets' => ['TR'],
            'languages' => ['tr'],
            'responsible_user_ids' => [],
            'assets_count' => 0,
            'connected_assets' => 0,
            'open_findings' => 0,
            'open_tasks' => 0,
            'context_completed' => 0,
            'context_total' => 8,
        ];
        $state['demo_assets'] = [
            [
                'id' => 'web-other',
                'type' => 'website',
                'type_label' => 'Website',
                'name' => 'other-leak.example',
                'brand_id' => 'other-brand',
                'connection' => 'connected',
                'provenance' => 'Connected provider',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'open_findings' => 0,
                'open_tasks' => 0,
                'last_update' => 'Today',
                'route' => 'demo.website',
            ],
        ];
        DemoState::put($state);

        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setTab', 'assets')
            ->assertSee('atlasdental.example')
            ->assertDontSee('other-leak.example');

        Livewire::test(BrandShow::class, ['brand' => 'other-brand'])
            ->call('setTab', 'assets')
            ->assertSee('other-leak.example')
            ->assertDontSee('Atlas Dental — Meta');
    }

    public function test_legacy_research_tab_redirects_to_discovery(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'research'])
            ->assertSet('tab', 'business')
            ->assertSet('businessSection', 'discovery')
            ->assertSee('Public Discovery');
    }
}
