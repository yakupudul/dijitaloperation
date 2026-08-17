<?php

namespace Tests\Feature;

use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Demo\GbpWorkspaceFixtures;
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
        $this->get(route('demo.gbp'))->assertNotFound();
        Livewire::test(OverviewPage::class)->assertStatus(404);
    }

    public function test_real_gbp_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('google_business_profile', 'Northwind GBP');

        foreach (['overview', 'profile', 'visibility', 'performance', 'reviews', 'competitors', 'operations'] as $tab) {
            $this->get(route('demo.gbp', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Google Business Profile')
                ->assertDontSee('Atlas Dental Ankara')
                ->assertDontSee('Demo local rank tracking')
                ->assertDontSee('Demo AI analysis');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id, 'tab' => 'queries'])
            ->assertSet('tab', 'performance')
            ->assertSet('perf_sub', 'queries')
            ->assertDontSee('acil dişçi çankaya')
            ->assertDontSee('Local SEO Score')
            ->assertDontSee('GBP Score');
    }

    public function test_gbp_workspace_fixtures_remain_deterministic_outside_http(): void
    {
        $a = GbpWorkspaceFixtures::workspace('last_28');
        $b = GbpWorkspaceFixtures::workspace('last_28');

        $this->assertSame($a['glance'], $b['glance']);
        $this->assertSame(
            $a['visibility']['scans']['ankara implant']['current']['points'],
            $b['visibility']['scans']['ankara implant']['current']['points'],
        );
        $this->assertSame($a['reviews']['glance']['total'], $b['reviews']['glance']['total']);
        $this->assertSame($a['competitors']['rows'][0]['name'], $b['competitors']['rows'][0]['name']);

        $visibility = GbpWorkspaceFixtures::visibility();
        $default = $visibility['default_keyword'];
        $points = $visibility['scans'][$default]['current']['points'];
        $this->assertNotEmpty($points);
        $this->assertArrayHasKey('lat', $points[0]);
        $this->assertSame(GbpWorkspaceFixtures::BUSINESS_LAT, $visibility['business']['lat']);

        $attention = GbpWorkspaceFixtures::needsAttention();
        $this->assertLessThanOrEqual(4, count($attention));
    }
}
