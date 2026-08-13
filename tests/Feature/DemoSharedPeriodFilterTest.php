<?php

namespace Tests\Feature;

use App\Livewire\Demo\Assets\AnalyticsPage;
use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Livewire\Demo\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Demo\Meta\OverviewPage as MetaOverviewPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoSharedPeriodFilterTest extends TestCase
{
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

    public function test_demo_period_previous_bounds_match_selected_day_count(): void
    {
        $current = DemoPeriod::bounds('custom', '2026-07-06', '2026-08-12');
        $prev = DemoPeriod::previousBounds('custom', '2026-07-06', '2026-08-12');

        $this->assertSame(38, $current['days']);
        $this->assertSame(38, $prev['days']);
        $this->assertSame('2026-05-29', $prev['start']->toDateString());
        $this->assertSame('2026-07-05', $prev['end']->toDateString());
    }

    public function test_custom_period_validation_rejects_inverted_and_future_ranges(): void
    {
        $this->assertNotNull(DemoPeriod::validateCustom(null, '2026-08-01'));
        $this->assertNotNull(DemoPeriod::validateCustom('2026-08-10', '2026-08-01'));
        $this->assertNotNull(DemoPeriod::validateCustom('2026-08-13', '2026-08-14'));
        $this->assertNull(DemoPeriod::validateCustom('2026-07-06', '2026-08-12'));
    }

    public function test_meta_custom_apply_cancel_and_aggregation(): void
    {
        $baseline = MetaAdsWorkspaceFixtures::workspace('last_28');
        $baselineSpend = (int) $baseline['glance']['spend']['raw'];

        $component = Livewire::test(MetaOverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->assertSet('period', 'last_28')
            ->call('openCustomPicker')
            ->assertSet('showCustomPicker', true)
            ->set('draftPeriodStart', '2026-08-10')
            ->set('draftPeriodEnd', '2026-08-01')
            ->call('applyCustomPeriod')
            ->assertSet('showCustomPicker', true)
            ->assertNotSet('customPeriodError', null);

        $component
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->assertSet('periodStart', '2026-08-01')
            ->assertSet('periodEnd', '2026-08-10')
            ->assertSet('showCustomPicker', false)
            ->assertSee('Aug 1')
            ->assertSee('10');

        $custom = MetaAdsWorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $customSpend = (int) $custom['glance']['spend']['raw'];
        $this->assertNotSame($baselineSpend, $customSpend);
        $this->assertLessThan($baselineSpend, $customSpend);

        $component
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-07-01')
            ->set('draftPeriodEnd', '2026-07-15')
            ->call('cancelCustomPeriod')
            ->assertSet('period', 'custom')
            ->assertSet('periodStart', '2026-08-01')
            ->assertSet('periodEnd', '2026-08-10');
    }

    public function test_meta_period_persists_across_tabs_and_compare_label_renders(): void
    {
        Livewire::test(MetaOverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->call('setPeriod', 'last_7')
            ->assertSet('period', 'last_7')
            ->call('setTab', 'creatives')
            ->assertSet('period', 'last_7')
            ->assertSet('tab', 'creatives')
            ->assertSet('compare', true)
            ->assertSee('vs');
    }

    public function test_website_and_google_ads_still_accept_shared_period_presets(): void
    {
        Livewire::test(WebsiteOverviewPage::class)
            ->call('setPeriod', 'last_14')
            ->assertSet('period', 'last_14')
            ->assertOk();

        Livewire::test(GoogleAdsOverviewPage::class)
            ->call('setPeriod', 'last_7')
            ->assertSet('period', 'last_7')
            ->assertSee('Google Ads');
    }

    public function test_ga4_custom_period_recalculates_and_persists_across_tabs(): void
    {
        $baseline = Ga4WorkspaceFixtures::workspace('last_28');
        $baselineSessions = (int) $baseline['glance']['sessions']['raw'];

        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-07-06')
            ->set('draftPeriodEnd', '2026-08-12')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->assertSet('periodStart', '2026-07-06')
            ->assertSet('periodEnd', '2026-08-12')
            ->call('setTab', 'measurement')
            ->assertSet('period', 'custom')
            ->call('setTab', 'acquisition')
            ->assertSet('period', 'custom')
            ->assertSet('compare', true)
            ->assertSee('vs');

        $custom = Ga4WorkspaceFixtures::workspace('custom', '2026-07-06', '2026-08-12');
        $this->assertNotSame($baselineSessions, (int) $custom['glance']['sessions']['raw']);
    }

    public function test_gsc_custom_period_recalculates_and_persists_across_tabs(): void
    {
        $baseline = GscWorkspaceFixtures::workspace('last_28');
        $baselineClicks = (int) $baseline['glance']['clicks']['raw'];

        Livewire::test(SearchConsolePage::class, ['assetId' => DemoCatalog::GSC_ASSET_ID])
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-07-06')
            ->set('draftPeriodEnd', '2026-08-12')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->call('setTab', 'demand')
            ->assertSet('period', 'custom')
            ->call('setTab', 'pages')
            ->assertSet('period', 'custom')
            ->assertSet('compare', true)
            ->assertSee('vs');

        $custom = GscWorkspaceFixtures::workspace('custom', '2026-07-06', '2026-08-12');
        $this->assertNotSame($baselineClicks, (int) $custom['glance']['clicks']['raw']);
    }

    public function test_last_90_preset_resolves_three_month_window(): void
    {
        $bounds = DemoPeriod::bounds('last_90');
        $this->assertSame(90, $bounds['days']);
        $this->assertSame('2026-05-15', $bounds['start']->toDateString());
        $this->assertSame('2026-08-12', $bounds['end']->toDateString());
    }

    public function test_custom_factors_change_with_day_span(): void
    {
        $short = DemoCatalog::periodFactors('custom', '2026-08-06', '2026-08-12');
        $long = DemoCatalog::periodFactors('custom', '2026-07-01', '2026-08-12');

        $this->assertSame(7, $short['days']);
        $this->assertGreaterThan($short['days'], $long['days']);
        $this->assertNotEquals($short['spend_factor'], $long['spend_factor']);
    }
}
