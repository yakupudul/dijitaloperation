<?php

namespace Tests\Feature\PhaseE;

use App\Livewire\Demo\SettingsPage;
use App\Livewire\Operator\Assets\AnalyticsPage;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Operator\OperatorPeriod;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class PhaseEOperatorUxRegressionTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        DemoState::reset();
        $this->travelTo('2026-08-20 10:00:00');
    }

    #[Test]
    public function canonical_routes_render_and_retired_surfaces_are_gone(): void
    {
        $this->get('/')->assertOk();
        $this->get('/customers')->assertOk();
        $this->get('/brands')->assertOk();
        $this->get('/assets')->assertOk();
        $this->get('/integrations')->assertOk();
        $this->get('/settings')->assertOk();
        foreach (['/activity', '/findings', '/recommendations', '/tasks', '/opportunities', '/alerts', '/archive', '/compliance'] as $retired) {
            $this->get($retired)->assertRedirect('/');
        }
        $this->get('/profile')->assertOk();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Toggle Mobile Menu', $html);
        $this->assertStringNotContainsString('Atlas Dental Ankara', $html);

        $this->get('/app/customers')->assertStatus(410);
        $this->get('/system/login')->assertStatus(410);
        $this->get(route('operator.website', ['assetId' => DemoCatalog::WEBSITE_ASSET_ID]))->assertNotFound();
        $this->get(route('operator.analytics', ['assetId' => DemoCatalog::GA4_ASSET_ID]))->assertNotFound();
    }

    #[Test]
    public function production_period_uses_operator_clock_not_demo_anchor(): void
    {
        $asset = $this->createPortfolioAsset('ga4', 'Northwind GA4');

        $component = Livewire::test(AnalyticsPage::class, ['assetId' => (string) $asset->id])
            ->call('setPeriod', 'last_7');

        $operator = OperatorPeriod::bounds('last_7');
        $demo = DemoPeriod::usingFixtureAnchor(fn () => DemoPeriod::bounds('last_7'));

        $component
            ->assertSet('periodStart', $operator['start']->toDateString())
            ->assertSet('periodEnd', $operator['end']->toDateString());

        $this->assertNotSame($demo['end']->toDateString(), $operator['end']->toDateString());
        $this->assertSame(DemoPeriod::ANCHOR_DATE, $demo['end']->toDateString());
        $this->assertSame('2026-08-20', $operator['end']->toDateString());
        $this->assertSame('2026-08-14', $operator['start']->toDateString());
    }

    #[Test]
    public function async_run_is_queued_and_team_member_cannot_mutate_settings(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Activity Website');
        $queued = app(AsyncOperationService::class)->queueFindingEvaluation($asset, $this->admin);
        $this->assertTrue($queued['ok'] ?? false);

        $member = User::factory()->create(['locale' => 'en']);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Hijacked Phase E')
            ->call('saveGeneral')
            ->assertForbidden();
    }
}
