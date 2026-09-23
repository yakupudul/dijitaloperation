<?php

namespace Tests\Feature;

use App\Livewire\Demo\Website\OverviewPage;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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

        foreach (['overview', 'seo', 'search_console', 'ga4_analysis', 'content', 'health', 'standards', 'infrastructure', 'setup'] as $tab) {
            $this->get(route('operator.website', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Northwind Website')
                ->assertDontSee('Atlas Dental Website')
                ->assertDontSee('Page not found');
        }

        foreach (['connections', 'settings', 'activity', 'visibility', 'performance', 'operations'] as $legacy) {
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
            ->assertSee('Technical health observations')
            ->assertSee('No technical Website data collected yet')
            ->assertDontSee('88% Healthy')
            ->call('setTab', 'infrastructure')
            ->assertSee('WordPress inside state')
            ->call('refreshData')
            ->call('runDiagnosis')
            ->assertSet('tab', 'health');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'technical'])
            ->assertSet('tab', 'health');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'search'])
            ->assertSet('tab', 'search_console');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'conversions'])
            ->assertSet('tab', 'ga4_analysis');

        foreach (['visibility' => 'search_console', 'performance' => 'ga4_analysis', 'operations' => 'overview'] as $retired => $target) {
            Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => $retired])
                ->assertSet('tab', $target);
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertSee(__('operator_website.tabs.health'))
            ->assertSee(__('operator_website.tabs.standards'))
            ->assertDontSee(__('operator.website.tabs.operations'))
            ->assertDontSee(__('operator.website.tabs.visibility'))
            ->assertDontSee(__('operator.website.tabs.performance'))
            ->assertDontSee('Organic Search')
            ->call('refreshSeoIntelligence')
            ->assertSet('tab', 'search_console');
    }

    public function test_overview_lists_findings_and_recommendations_with_readable_labels(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Northwind Website');

        $findings = Finding::factory()->count(12)->create([
            'digital_asset_id' => $asset->id,
            'customer_id' => $asset->brand?->customer_id,
            'brand_id' => $asset->brand_id,
            'severity' => 'critical',
            'status' => 'open',
        ]);

        Recommendation::factory()->create([
            'digital_asset_id' => $asset->id,
            'finding_id' => $findings->first()->id,
            'title' => 'Northwind canonical fix',
            'priority' => 'high',
            'status' => Recommendation::STATUS_OPEN,
        ]);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertSee(__('operator_website.severity.critical'))
            ->assertSee(__('operator_website.finding_status.open'))
            ->assertSee('Northwind canonical fix')
            ->assertSee(__('operator_website.priority.high').' · '.__('operator_website.recommendation_status.open'))
            ->assertDontSee('>critical<', false)
            ->assertDontSee('high · open');
    }
}
