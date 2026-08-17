<?php

namespace Tests\Feature;

use App\Livewire\Demo\Website\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\WebsiteWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class WebsiteOperatingWorkspaceTest extends TestCase
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

    public function test_website_without_asset_id_is_not_found(): void
    {
        $this->get(route('operator.website'))->assertNotFound();
        Livewire::test(OverviewPage::class)->assertStatus(404);
    }

    public function test_catalog_website_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('operator.website', ['assetId' => DemoCatalog::WEBSITE_ASSET_ID]))->assertNotFound();
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::WEBSITE_ASSET_ID])->assertStatus(404);
    }

    public function test_real_website_asset_renders_tabs_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Northwind Website');

        foreach (['overview', 'health', 'visibility', 'content', 'performance', 'infrastructure', 'operations', 'setup'] as $tab) {
            $this->get(route('operator.website', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Northwind Website')
                ->assertDontSee('Atlas Dental Website')
                ->assertDontSee('Page not found');
        }

        foreach (['connections', 'settings', 'activity'] as $legacy) {
            $this->get(route('operator.website', ['assetId' => $asset->id, 'tab' => $legacy]))
                ->assertOk()
                ->assertSee('Northwind Website');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind Website')
            ->assertSee('Needs attention')
            ->assertSee('Opportunities')
            ->assertSee('Site inventory')
            ->assertDontSee('Website Health')
            ->assertDontSee('SEO Score')
            ->assertDontSee('27 service pages have no self-referencing canonical')
            ->assertDontSee('Atlas Dental Website')
            ->call('setTab', 'health')
            ->assertSee('Website health')
            ->assertSee('0 checks evaluated')
            ->assertDontSee('88% Healthy')
            ->call('setTab', 'infrastructure')
            ->assertSee('not standalone assets')
            ->call('refreshData')
            ->call('runDiagnosis')
            ->assertSet('tab', 'health');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'technical'])
            ->assertSet('tab', 'health');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'search'])
            ->assertSet('tab', 'visibility');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'conversions'])
            ->assertSet('tab', 'performance')
            ->assertSet('perf_sub', 'conversions');
    }

    public function test_website_workspace_fixtures_remain_deterministic_outside_http(): void
    {
        $a = WebsiteWorkspaceFixtures::workspace('last_28');
        $b = WebsiteWorkspaceFixtures::workspace('last_28');

        $this->assertSame($a['identity']['title'], $b['identity']['title']);
        $this->assertSame('Atlas Dental Website', $a['identity']['title']);
        $this->assertSame($a['health']['summary'], $b['health']['summary']);
    }
}
