<?php

namespace Tests\Feature;

use App\Livewire\Demo\Assets\AnalyticsPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\DigitalAssetTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class Ga4OperatingWorkspaceTest extends TestCase
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

    public function test_ga4_is_first_class_asset_type_and_catalog_fixture_still_exists(): void
    {
        $this->assertArrayHasKey('ga4', DigitalAssetTypes::options());
        $this->assertSame('Google Analytics', DigitalAssetTypes::options()['ga4']);
        $this->assertArrayNotHasKey('google_analytics', DigitalAssetTypes::options());

        $asset = DemoCatalog::asset(DemoCatalog::GA4_ASSET_ID);
        $this->assertNotNull($asset);
        $this->assertSame('ga4', $asset['type']);
        $this->assertSame('primary_managed', $asset['role'] ?? DemoCatalog::assetTaxonomy('ga4')['role']);
        $this->assertSame('Atlas Dental — GA4', $asset['name']);
    }

    public function test_catalog_ga4_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('operator.analytics', ['assetId' => DemoCatalog::GA4_ASSET_ID]))->assertNotFound();
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])->assertStatus(404);
    }

    public function test_real_ga4_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('ga4', 'Northwind GA4', ['module_id' => 'analytics']);

        foreach (['overview', 'measurement', 'acquisition', 'behavior', 'journeys', 'operations'] as $tab) {
            $this->get(route('operator.analytics', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Northwind GA4')
                ->assertDontSee('Atlas Dental — GA4')
                ->assertDontSee('Page not found');
        }

        Livewire::test(AnalyticsPage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind GA4')
            ->assertDontSee('Measurement Score')
            ->assertDontSee('Data Quality Score')
            ->assertDontSee('Analytics Health')
            ->assertDontSee('Journey Score')
            ->assertDontSee('Ask Analytics AI')
            ->assertDontSee('G-DEMOATLAS')
            ->assertDontSee('Atlas Dental Web')
            ->set('tab', 'key_events')
            ->assertSet('tab', 'measurement')
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->call('refreshData')
            ->call('runAnalysis')
            ->assertDontSee('Atlas Dental — GA4');
    }

    public function test_ga4_workspace_fixtures_remain_deterministic_outside_http(): void
    {
        $baseline = Ga4WorkspaceFixtures::workspace('last_28');
        $baselineSessions = (int) $baseline['glance']['sessions']['raw'];
        $custom = Ga4WorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $this->assertNotSame($baselineSessions, (int) $custom['glance']['sessions']['raw']);
        $this->assertLessThan($baselineSessions, (int) $custom['glance']['sessions']['raw']);
        $this->assertSame($baseline, Ga4WorkspaceFixtures::workspace('last_28'));
    }
}
