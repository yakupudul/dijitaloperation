<?php

namespace Tests\Feature;

use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\DigitalAssetTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GscOperatingWorkspaceTest extends TestCase
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

    public function test_gsc_is_first_class_demo_asset_type(): void
    {
        $this->assertArrayHasKey('gsc', DigitalAssetTypes::options());
        $this->assertSame('Google Search Console', DigitalAssetTypes::options()['gsc']);

        $asset = DemoCatalog::asset(DemoCatalog::GSC_ASSET_ID);
        $this->assertNotNull($asset);
        $this->assertSame('gsc', $asset['type']);
        $this->assertSame('primary_managed', DemoCatalog::assetTaxonomy('gsc')['role']);
        $this->assertSame('Atlas Dental — Search Console', $asset['name']);
    }

    public function test_primary_tabs_render_without_dead_routes(): void
    {
        $id = DemoCatalog::GSC_ASSET_ID;
        foreach (['overview', 'performance', 'demand', 'pages', 'indexing', 'relationships', 'operations'] as $tab) {
            $this->get(route('demo.search-console', ['assetId' => $id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Atlas Dental — Search Console')
                ->assertDontSee('404');
        }
    }

    public function test_overview_scan_surface_and_no_fake_scores(): void
    {
        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])
            ->assertSee('Atlas Dental — Search Console')
            ->assertSee('Needs attention')
            ->assertSee('Search momentum')
            ->assertSee('Discoverability')
            ->assertSee('Page pulse')
            ->assertSee('Clicks')
            ->assertSee('Impressions')
            ->assertDontSee('SEO Score')
            ->assertDontSee('Organic Health Score')
            ->assertDontSee('Search Visibility Score')
            ->assertDontSee('Ask SEO AI')
            ->assertDontSee('Request indexing now');
    }

    public function test_performance_demand_pages_indexing(): void
    {
        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])
            ->call('setTab', 'performance')
            ->assertSee('Search performance')
            ->assertSee('Mobile')
            ->assertSee('Türkiye')
            ->assertSee('Derived')
            ->assertSee('Brand')
            ->assertSee('Non-brand')
            ->assertDontSee('ranks #')
            ->call('setMetric', 'impressions')
            ->assertSet('metric', 'impressions')
            ->call('setTab', 'demand')
            ->assertSee('Queries')
            ->assertSee('Implant Turkey')
            ->assertSee('observed')
            ->call('setDemandSub', 'ownership')
            ->assertSee('ownership')
            ->assertSee('/implant')
            ->assertSee('fragmented')
            ->assertSee('Cannibalization candidate')
            ->assertDontSee('Cannibalization confirmed')
            ->call('openCluster', 'cl-implant-tr-ankara')
            ->assertSet('cluster', 'cl-implant-tr-ankara')
            ->call('setTab', 'pages')
            ->assertSee('Pages')
            ->assertSee('Service / Product')
            ->assertSee('page-level')
            ->call('openPage', '/implant')
            ->assertSet('page', '/implant')
            ->call('setTab', 'indexing')
            ->assertSee('Indexing')
            ->assertSee('Google index state')
            ->assertSee('not a Live URL test')
            ->assertSee('No Force Index')
            ->assertDontSee('Request indexing now')
            ->call('setIndexSub', 'sitemaps')
            ->assertSee('sitemap.xml')
            ->call('setIndexSub', 'inspection')
            ->assertSee('Canonical')
            ->call('openUrl', 'url-implant')
            ->assertSet('url', 'url-implant');
    }

    public function test_relationships_operations_and_legacy_remap(): void
    {
        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])
            ->call('setTab', 'relationships')
            ->assertSee('Relationships')
            ->assertSee('Observes')
            ->assertSee('Atlas Dental Website')
            ->assertSee('Provides evidence')
            ->assertSee('Technical connection')
            ->assertSee('sc-domain:atlasdental.example')
            ->call('setOps', 'findings')
            ->assertSee('Implant Turkey')
            ->call('openFinding', 'gsc-f-implant-visibility')
            ->assertSet('finding', 'gsc-f-implant-visibility')
            ->call('setOps', 'outcomes')
            ->assertSee('Improvement observed')
            ->assertSee('Still observed')
            ->assertSee('do not claim the Task caused')
            ->assertDontSee('Task caused recovery')
            ->set('tab', 'queries')
            ->assertSet('tab', 'demand')
            ->set('tab', 'url_inspection')
            ->assertSet('tab', 'indexing')
            ->assertSet('index_sub', 'inspection');
    }

    public function test_custom_period_recalculates_and_refresh_is_deterministic(): void
    {
        $baseline = GscWorkspaceFixtures::workspace('last_28');
        $baselineClicks = (int) $baseline['glance']['clicks']['raw'];

        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->assertSet('periodStart', '2026-08-01')
            ->assertSet('periodEnd', '2026-08-10')
            ->call('setTab', 'demand')
            ->assertSet('period', 'custom')
            ->call('refreshData')
            ->call('runAnalysis');

        $custom = GscWorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $this->assertNotSame($baselineClicks, (int) $custom['glance']['clicks']['raw']);
        $this->assertSame($baseline, GscWorkspaceFixtures::workspace('last_28'));
    }
}
