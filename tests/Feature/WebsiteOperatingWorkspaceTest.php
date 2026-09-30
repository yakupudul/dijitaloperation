<?php

namespace Tests\Feature;

use App\Livewire\Operator\Website\V2\WebsiteScreen;
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
        Livewire::test(WebsiteScreen::class)->assertStatus(404);
    }

    public function test_catalog_website_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('operator.website', ['assetId' => DemoCatalog::WEBSITE_ASSET_ID]))->assertNotFound();
    }

    public function test_real_website_asset_renders_tabs_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Northwind Website');

        foreach (['overview', 'search_console', 'ga4_analysis', 'content', 'health', 'infrastructure', 'setup'] as $tab) {
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

        Livewire::test(WebsiteScreen::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind Website')
            ->assertSee('Genel Bakış')
            ->assertSee('SEO Yapılacaklar')
            ->assertDontSee('SEO Score')
            ->assertDontSee('Atlas Dental Website')
            ->call('setTab', 'saglik')
            ->assertSet('tab', 'saglik');

        foreach (['technical' => 'saglik', 'health' => 'saglik', 'infrastructure' => 'saglik', 'search' => 'analiz', 'conversions' => 'analiz', 'search_console' => 'analiz', 'operations' => 'genel', 'setup' => 'ayarlar'] as $legacy => $target) {
            Livewire::test(WebsiteScreen::class, ['assetId' => (string) $asset->id, 'tab' => $legacy])
                ->assertSet('tab', $target);
        }
    }

    public function test_overview_replaces_legacy_findings_and_recommendations_with_open_work(): void
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

        // Legacy Finding / Recommendation rows stay in their own pages; the website screen does not show them.
        Livewire::test(WebsiteScreen::class, ['assetId' => (string) $asset->id])
            ->assertDontSee('Northwind canonical fix')
            ->assertDontSee(__('operator_website.overview.open_findings'))
            ->assertDontSee(__('operator_website.overview.recommendations'))
            ->assertDontSee('>critical<', false)
            ->assertDontSee('high · open');
    }
}
