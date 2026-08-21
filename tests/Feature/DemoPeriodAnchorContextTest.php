<?php

namespace Tests\Feature;

use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\User;
use App\Services\Gsc\GscSpecialistReadService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class DemoPeriodAnchorContextTest extends TestCase
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

    public function test_demo_fixture_presets_stay_on_anchor_even_when_env_is_not_testing(): void
    {
        $this->app['env'] = 'production';
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00', DemoPeriod::TIMEZONE));

        $workspace = GscWorkspaceFixtures::workspace('last_28');
        $catalogBounds = DemoPeriod::bounds('last_28', null, null, DemoCatalog::GSC_ASSET_ID);
        $catalogWorkspace = app(GscSpecialistReadService::class)->workspace(DemoCatalog::GSC_ASSET_ID, 'last_28');

        $this->assertSame(DemoPeriod::ANCHOR_DATE, $workspace['period_end']);
        $this->assertSame('2026-07-16', $workspace['period_start']);
        $this->assertSame(18420, $workspace['glance']['clicks']['raw']);
        $this->assertSame(DemoPeriod::ANCHOR_DATE, $catalogBounds['end']->toDateString());
        $this->assertSame('2026-07-16', $catalogBounds['start']->toDateString());
        $this->assertNotSame('demo_catalog', $catalogWorkspace['migration_mode'] ?? null);
        $this->assertNotSame(18420, $catalogWorkspace['glance']['clicks']['raw'] ?? null);
        $this->assertSame('—', $catalogWorkspace['glance']['clicks']['value'] ?? null);
    }

    public function test_real_operator_period_presets_follow_the_current_date(): void
    {
        $this->app['env'] = 'production';
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00', DemoPeriod::TIMEZONE));

        $realBounds = DemoPeriod::bounds('last_28');
        $numericAsset = $this->createPortfolioAsset('gsc', 'Northwind GSC', ['module_id' => 'search-console']);
        $realWorkspace = app(GscSpecialistReadService::class)->workspace((string) $numericAsset->id, 'last_28');
        $website = $this->createPortfolioAsset('website', 'Northwind Website');

        $this->assertSame('2026-09-01', $realBounds['end']->toDateString());
        $this->assertSame('2026-08-05', $realBounds['start']->toDateString());
        $this->assertSame('2026-09-01', $realWorkspace['period_end']);
        $this->assertSame('2026-08-05', $realWorkspace['period_start']);

        $this->assertNotNull(DemoPeriod::validateCustom('2026-09-02', '2026-09-03'));
        $this->assertNull(DemoPeriod::validateCustom('2026-08-20', '2026-09-01'));

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $website->id])
            ->call('setPeriod', 'last_7')
            ->assertSet('period', 'last_7')
            ->assertSet('periodStart', '2026-08-26')
            ->assertSet('periodEnd', '2026-09-01');
    }
}
