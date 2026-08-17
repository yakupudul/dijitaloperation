<?php

namespace Tests\Feature;

use App\Livewire\Demo\GoogleAds\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\GoogleAdsWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class GoogleAdsOperatingWorkspaceTest extends TestCase
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

    public function test_google_ads_without_asset_id_is_not_found(): void
    {
        $this->get(route('demo.google-ads.overview'))->assertNotFound();
        Livewire::test(OverviewPage::class)->assertStatus(404);
    }

    public function test_catalog_google_ads_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('demo.google-ads.overview', ['assetId' => DemoCatalog::GOOGLE_ADS_ASSET_ID]))->assertNotFound();
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::GOOGLE_ADS_ASSET_ID])->assertStatus(404);
    }

    public function test_real_google_ads_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('google_ads', 'Northwind Google Ads', ['module_id' => 'google-ads']);

        foreach (['overview', 'campaigns', 'search_demand', 'ads_assets', 'landing_pages', 'measurement', 'operations'] as $tab) {
            $this->get(route('demo.google-ads.overview', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Google Ads')
                ->assertSee('Northwind Google Ads')
                ->assertDontSee('Atlas Dental — Europe')
                ->assertDontSee('Page not found');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind Google Ads')
            ->assertSee('Needs attention')
            ->assertSee('Budget pacing')
            ->assertSee('Campaign portfolio')
            ->assertDontSee('₺48,320')
            ->assertDontSee('Ahead of plan')
            ->assertDontSee('PPC Score')
            ->assertDontSee('Optimization Score')
            ->assertDontSee('Account Score')
            ->assertDontSee('Post Bariatric — UK Search')
            ->assertDontSee('breast lift cost uk');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'search_terms'])
            ->assertSet('tab', 'search_demand')
            ->assertSee('Search & demand');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'conversions'])
            ->assertSet('tab', 'measurement')
            ->assertSee('Measurement');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'insights'])
            ->assertSet('tab', 'overview')
            ->assertSee('Needs attention');
    }

    public function test_demo_totals_are_coherent_and_deterministic(): void
    {
        $a = GoogleAdsWorkspaceFixtures::workspace('last_28');
        $b = GoogleAdsWorkspaceFixtures::workspace('last_28');

        $this->assertSame($a['glance'], $b['glance']);
        $spend = (int) array_sum(array_column($a['campaigns'], 'spend'));
        $leads = (int) array_sum(array_column($a['campaigns'], 'leads'));
        $this->assertSame(48320, $spend);
        $this->assertSame(114, $leads);
        $this->assertSame(424, (int) round($spend / $leads));
        $this->assertSame($a['search']['review_spend'], 4820);
    }
}
