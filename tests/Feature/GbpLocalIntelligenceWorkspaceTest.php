<?php

namespace Tests\Feature;

use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class GbpLocalIntelligenceWorkspaceTest extends TestCase
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

    public function test_gbp_without_asset_id_is_not_found(): void
    {
        $this->get(route('operator.gbp'))->assertNotFound();
        Livewire::test(OverviewPage::class)->assertStatus(404);
    }

    public function test_real_gbp_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('google_business_profile', 'Northwind GBP');

        foreach (['overview', 'performance', 'reviews', 'profile', 'advisor', 'visibility', 'competitors', 'operations'] as $tab) {
            $this->get(route('operator.gbp', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Google Business Profile')
                ->assertDontSee('Atlas Dental Ankara')
                ->assertDontSee('Demo local rank tracking')
                ->assertDontSee('Demo AI analysis');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'queries'])
            ->assertSet('tab', 'performance')
            ->assertDontSee('acil dişçi çankaya')
            ->assertDontSee('Local SEO Score')
            ->assertDontSee('GBP Score');
    }
}
