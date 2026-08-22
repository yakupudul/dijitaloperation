<?php

namespace Tests\Feature\TrackA;

use App\Livewire\Operator\Assets\AnalyticsPage;
use App\Livewire\Operator\Assets\SearchConsolePage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Gsc\GscSpecialistReadService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionOperatorDemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    #[Test]
    public function production_numeric_assets_never_read_gsc_or_ga4_workspace_fixtures(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $gsc = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'gsc']);
        $ga4 = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'ga4']);

        $gscWorkspace = app(GscSpecialistReadService::class)->workspace((string) $gsc->id, 'last_28');
        $ga4Workspace = app(Ga4SpecialistReadService::class)->workspace((string) $ga4->id, 'last_28');
        $fixtureClicks = GscWorkspaceFixtures::workspace('last_28')['glance']['clicks']['raw'];
        $fixtureSessions = Ga4WorkspaceFixtures::workspace('last_28')['glance']['sessions']['raw'];

        $this->assertNotSame('demo_catalog', $gscWorkspace['migration_mode'] ?? null);
        $this->assertNotSame('demo_catalog', $ga4Workspace['migration_mode'] ?? null);
        $this->assertNotSame($fixtureClicks, $gscWorkspace['glance']['clicks']['raw'] ?? null);
        $this->assertNotSame($fixtureSessions, $ga4Workspace['glance']['sessions']['raw'] ?? null);
        $this->assertSame('—', $gscWorkspace['glance']['clicks']['value'] ?? null);
        $this->assertSame('—', $ga4Workspace['glance']['sessions']['value'] ?? $ga4Workspace['glance']['sessions'] ?? '—');

        $this->get(route('operator.search-console', ['assetId' => $gsc->id]))
            ->assertOk()
            ->assertDontSee('Atlas Dental — Search Console')
            ->assertDontSee('Implant Turkey')
            ->assertDontSee('sc-domain:atlasdental.example');
        $this->get(route('operator.analytics', ['assetId' => $ga4->id]))
            ->assertOk()
            ->assertDontSee('Atlas Dental — GA4')
            ->assertDontSee('Demo Mode · product vision fixtures');
    }

    #[Test]
    public function crafted_catalog_string_ids_cannot_produce_fixture_backed_gsc_or_ga4_workspaces(): void
    {
        $gscRoute = app('router')->getRoutes()->getByName('operator.search-console');
        $ga4Route = app('router')->getRoutes()->getByName('operator.analytics');
        $this->assertSame('[0-9]+', $gscRoute?->wheres['assetId'] ?? null);
        $this->assertSame('[0-9]+', $ga4Route?->wheres['assetId'] ?? null);

        $this->get('/assets/search-console/'.DemoCatalog::GSC_ASSET_ID)->assertNotFound();
        $this->get('/assets/analytics/'.DemoCatalog::GA4_ASSET_ID)->assertNotFound();
        $this->get('/assets/search-console/not-a-number')->assertNotFound();
        $this->get('/assets/analytics/not-a-number')->assertNotFound();

        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])->assertStatus(404);
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])->assertStatus(404);

        $gscWorkspace = app(GscSpecialistReadService::class)->workspace(DemoCatalog::GSC_ASSET_ID);
        $ga4Workspace = app(Ga4SpecialistReadService::class)->workspace(DemoCatalog::GA4_ASSET_ID);
        $fixtureClicks = GscWorkspaceFixtures::workspace('last_28')['glance']['clicks']['raw'];
        $fixtureSessions = Ga4WorkspaceFixtures::workspace('last_28')['glance']['sessions']['raw'];

        $this->assertNotSame('demo_catalog', $gscWorkspace['migration_mode'] ?? null);
        $this->assertNotSame('demo_catalog', $ga4Workspace['migration_mode'] ?? null);
        $this->assertNotSame($fixtureClicks, $gscWorkspace['glance']['clicks']['raw'] ?? null);
        $this->assertNotSame($fixtureSessions, $ga4Workspace['glance']['sessions']['raw'] ?? null);
        $this->assertSame('—', $gscWorkspace['glance']['clicks']['value'] ?? null);
        $this->assertSame('—', $ga4Workspace['glance']['sessions']['value'] ?? null);
        $this->assertNull($gscWorkspace['glance']['clicks']['raw'] ?? null);
    }

    #[Test]
    public function optional_gsc_and_ga4_routes_without_an_id_do_not_fall_back_to_fixtures(): void
    {
        $this->get('/assets/search-console')->assertNotFound();
        $this->get('/assets/analytics')->assertNotFound();
        $this->get('/app/assets/search-console/'.DemoCatalog::GSC_ASSET_ID)->assertGone();
        $this->get('/system/assets/analytics/'.DemoCatalog::GA4_ASSET_ID)->assertGone();
    }
}
