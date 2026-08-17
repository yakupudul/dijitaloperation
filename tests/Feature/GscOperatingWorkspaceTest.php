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
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class GscOperatingWorkspaceTest extends TestCase
{
    use CreatesCanonicalPortfolio;
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

    public function test_gsc_is_first_class_asset_type_and_catalog_fixture_still_exists(): void
    {
        $this->assertArrayHasKey('gsc', DigitalAssetTypes::options());
        $this->assertSame('Google Search Console', DigitalAssetTypes::options()['gsc']);

        $asset = DemoCatalog::asset(DemoCatalog::GSC_ASSET_ID);
        $this->assertNotNull($asset);
        $this->assertSame('gsc', $asset['type']);
        $this->assertSame('primary_managed', DemoCatalog::assetTaxonomy('gsc')['role']);
        $this->assertSame('Atlas Dental — Search Console', $asset['name']);
    }

    public function test_catalog_gsc_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('demo.search-console', ['assetId' => DemoCatalog::GSC_ASSET_ID]))->assertNotFound();
        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])->assertStatus(404);
    }

    public function test_real_gsc_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('gsc', 'Northwind GSC', ['module_id' => 'search-console']);

        foreach (['overview', 'performance', 'demand', 'pages', 'indexing', 'operations'] as $tab) {
            $this->get(route('demo.search-console', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Northwind GSC')
                ->assertDontSee('Atlas Dental — Search Console')
                ->assertDontSee('Page not found');
        }

        Livewire::test(SearchConsolePage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind GSC')
            ->assertDontSee('SEO Score')
            ->assertDontSee('Organic Health Score')
            ->assertDontSee('Search Visibility Score')
            ->assertDontSee('Ask SEO AI')
            ->assertDontSee('Request indexing now')
            ->assertDontSee('Implant Turkey')
            ->assertDontSee('sc-domain:atlasdental.example')
            ->set('tab', 'queries')
            ->assertSet('tab', 'demand')
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->call('refreshData')
            ->call('runAnalysis');
    }

    public function test_gsc_workspace_fixtures_remain_deterministic_outside_http(): void
    {
        $baseline = GscWorkspaceFixtures::workspace('last_28');
        $baselineClicks = (int) $baseline['glance']['clicks']['raw'];
        $custom = GscWorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $this->assertNotSame($baselineClicks, (int) $custom['glance']['clicks']['raw']);
        $this->assertSame($baseline, GscWorkspaceFixtures::workspace('last_28'));
    }
}
