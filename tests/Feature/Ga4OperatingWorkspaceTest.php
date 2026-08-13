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
use Tests\TestCase;

class Ga4OperatingWorkspaceTest extends TestCase
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

    public function test_ga4_is_first_class_demo_asset_type(): void
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

    public function test_primary_tabs_render_without_dead_routes(): void
    {
        $id = DemoCatalog::GA4_ASSET_ID;
        foreach (['overview', 'measurement', 'acquisition', 'behavior', 'journeys', 'relationships', 'operations'] as $tab) {
            $this->get(route('demo.analytics', ['assetId' => $id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('Atlas Dental — GA4')
                ->assertDontSee('Page not found');
        }
    }

    public function test_overview_scan_surface_and_no_fake_scores(): void
    {
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->assertSee('Atlas Dental — GA4')
            ->assertSee('Needs attention')
            ->assertSee('Acquisition mix')
            ->assertSee('Landing pulse')
            ->assertSee('Business actions')
            ->assertSee('Users')
            ->assertSee('Sessions')
            ->assertDontSee('Measurement Score')
            ->assertDontSee('Data Quality Score')
            ->assertDontSee('Analytics Health')
            ->assertDontSee('Journey Score')
            ->assertDontSee('Ask Analytics AI');
    }

    public function test_measurement_mapping_events_streams_quality(): void
    {
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->call('setTab', 'measurement')
            ->assertSee('Business actions')
            ->assertSee('Lead Form')
            ->assertSee('generate_lead')
            ->assertSee('WhatsApp')
            ->assertSee('Not mapped')
            ->assertSee('Appointment')
            ->call('setMeasSub', 'events')
            ->assertSee('Events')
            ->assertSee('whatsapp_click')
            ->call('setMeasSub', 'streams')
            ->assertSee('Atlas Dental Web')
            ->assertSee('G-DEMOATLAS')
            ->call('setMeasSub', 'quality')
            ->assertSee('Data quality')
            ->assertSee('Self-referral')
            ->assertDontSee('Measurement Score')
            ->assertDontSee('Mark as Key Event in GA4');
    }

    public function test_acquisition_related_ads_and_unmapped_source(): void
    {
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->call('setTab', 'acquisition')
            ->assertSee('Acquisition')
            ->assertSee('Organic Search')
            ->assertSee('Paid Search')
            ->assertSee('Paid Social')
            ->assertSee('google / cpc')
            ->assertSee('Google Ads')
            ->assertSee('facebook / paid')
            ->assertSee('Meta Ads')
            ->assertSee('(not set)')
            ->assertDontSee('Fatigue Score')
            ->assertDontSee('Ask Analytics AI');
    }

    public function test_behavior_journeys_relationships_operations(): void
    {
        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->call('setTab', 'behavior')
            ->assertSee('Behavior')
            ->assertSee('/implant')
            ->assertSee('Service')
            ->call('openLanding', '/implant')
            ->assertSet('landing', '/implant')
            ->call('setTab', 'journeys')
            ->assertSee('Journeys')
            ->assertSee('Organic')
            ->assertDontSee('user@')
            ->call('setTab', 'relationships')
            ->assertSee(__('operator.ga4.relationship_summary'))
            ->assertSee('Measures')
            ->assertSee('Atlas Dental Website')
            ->assertSee('Provides evidence')
            ->assertSee('Technical connection')
            ->assertSee('GA4 property binding')
            ->call('setOps', 'findings')
            ->assertSee('Lead Form signal interruption')
            ->call('openFinding', 'ga4-f-lead-interruption')
            ->assertSet('finding', 'ga4-f-lead-interruption')
            ->call('setOps', 'outcomes')
            ->assertSee('Improvement observed')
            ->assertSee('Still observed')
            ->assertDontSee('caused recovery')
            ->assertDontSee('Mark as Key Event');
    }

    public function test_legacy_tabs_remap_and_custom_period_recalculates(): void
    {
        $baseline = Ga4WorkspaceFixtures::workspace('last_28');
        $baselineSessions = (int) $baseline['glance']['sessions']['raw'];

        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->set('tab', 'key_events')
            ->assertSet('tab', 'measurement')
            ->assertSet('meas_sub', 'events')
            ->set('tab', 'landing_pages')
            ->assertSet('tab', 'behavior')
            ->call('openCustomPicker')
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSet('period', 'custom')
            ->assertSet('periodStart', '2026-08-01')
            ->assertSet('periodEnd', '2026-08-10');

        $custom = Ga4WorkspaceFixtures::workspace('custom', '2026-08-01', '2026-08-10');
        $this->assertNotSame($baselineSessions, (int) $custom['glance']['sessions']['raw']);
        $this->assertLessThan($baselineSessions, (int) $custom['glance']['sessions']['raw']);
    }

    public function test_header_actions_are_demo_only_and_deterministic_refresh(): void
    {
        $before = Ga4WorkspaceFixtures::workspace('last_28');

        Livewire::test(AnalyticsPage::class, ['assetId' => DemoCatalog::GA4_ASSET_ID])
            ->call('refreshData')
            ->call('runAnalysis')
            ->assertSee('Atlas Dental — GA4');

        $after = Ga4WorkspaceFixtures::workspace('last_28');
        $this->assertSame($before, $after);
    }
}
