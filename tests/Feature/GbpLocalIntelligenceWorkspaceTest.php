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
use Tests\TestCase;

class GbpLocalIntelligenceWorkspaceTest extends TestCase
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

    public function test_gbp_primary_tabs_render_without_404(): void
    {
        foreach (['overview', 'profile', 'visibility', 'performance', 'reviews', 'competitors', 'operations'] as $tab) {
            $this->get(route('demo.gbp', ['tab' => $tab]))
                ->assertOk()
                ->assertSee('Atlas Dental Ankara')
                ->assertSee('Google Business Profile');
        }
    }

    public function test_legacy_queries_and_insights_tabs_remap(): void
    {
        Livewire::test(OverviewPage::class, ['tab' => 'queries'])
            ->assertSet('tab', 'performance')
            ->assertSet('perf_sub', 'queries')
            ->assertSee('Search queries');

        Livewire::test(OverviewPage::class, ['tab' => 'insights'])
            ->assertSet('tab', 'overview')
            ->assertSee('Needs attention');
    }

    public function test_overview_surfaces_and_no_fake_score(): void
    {
        Livewire::test(OverviewPage::class)
            ->assertSee('Needs attention')
            ->assertSee('Local visibility snapshot')
            ->assertSee('Profile coverage')
            ->assertSee('Customer interactions')
            ->assertSee('Review pulse')
            ->assertSee('Website consistency')
            ->assertSee('Local opportunities')
            ->assertSee('Recent operational outcomes')
            ->assertSee('14 / 17')
            ->assertSee('Demo AI analysis')
            ->assertSee('Demo local rank tracking')
            ->assertDontSee('Local SEO Score')
            ->assertDontSee('GBP Score')
            ->assertDontSee('Visibility Score')
            ->assertDontSee('Reputation Score');
    }

    public function test_profile_coverage_categories_services_and_entity_consistency(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'profile')
            ->assertSee('Profile coverage')
            ->assertSee('reviewed fields present')
            ->assertSee('reviewed profile fields, not Google')
            ->assertSee('Dental clinic')
            ->assertSee('Service coverage')
            ->assertSee('Invisalign')
            ->assertSee('Not represented')
            ->assertSee('Entity consistency')
            ->assertSee('Mismatch')
            ->assertSee('/subeler/cankaya/')
            ->assertSee('Matched')
            ->assertSee('No percentage score is assigned')
            ->assertDontSee('Local SEO Score');
    }

    public function test_visibility_map_fixture_and_fallback_table(): void
    {
        $visibility = GbpWorkspaceFixtures::visibility();
        $default = $visibility['default_keyword'];
        $points = $visibility['scans'][$default]['current']['points'];

        $this->assertNotEmpty($points);
        $this->assertArrayHasKey('lat', $points[0]);
        $this->assertArrayHasKey('lng', $points[0]);
        $this->assertArrayHasKey('rank', $points[0]);
        $this->assertSame(GbpWorkspaceFixtures::BUSINESS_LAT, $visibility['business']['lat']);

        Livewire::test(OverviewPage::class)
            ->call('setTab', 'visibility')
            ->assertSee('Local visibility')
            ->assertSeeHtml('data-gbp-rank-map')
            ->assertSee('View point data')
            ->assertSee('Demo local rank tracking')
            ->assertSee('Keyword comparison')
            ->assertSee('Geographic coverage')
            ->assertDontSee('maps.googleapis.com')
            ->assertDontSee('Google Maps API')
            ->assertDontSee('Market Share')
            ->call('setKeyword', 'ankara implant')
            ->assertSee('ankara implant')
            ->assertSee('Visibility weakens south-west')
            ->call('selectPoint', 'p-1')
            ->assertSee('Observed rank')
            ->assertSee('Observed top results')
            ->call('toggleScanCompare')
            ->assertSet('scan_compare', true)
            ->call('setVisMode', 'change')
            ->assertSet('vis_mode', 'change');
    }

    public function test_performance_discovery_actions_and_queries(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setPerfSub', 'discovery')
            ->assertSee('Search impressions')
            ->assertSee('Maps impressions')
            ->assertSee('Total observed profile impressions')
            ->assertDontSee('Calls received')
            ->assertDontSee('Store visits')
            ->call('setPerfSub', 'actions')
            ->assertSee('Website clicks')
            ->assertSee('Call clicks')
            ->assertSee('Direction requests')
            ->assertSee('Call clicks ≠ phone calls')
            ->call('setPerfSub', 'queries')
            ->assertSee('Search queries')
            ->assertSee('Last month')
            ->assertSee('Derived')
            ->assertSee('acil dişçi çankaya')
            ->assertSee('Tracked')
            ->call('setQueryFilter', 'Website gap')
            ->assertSee('acil dişçi çankaya')
            ->assertDontSeeHtml('>atlas dental</td>');
    }

    public function test_reviews_inbox_topics_queue_and_no_external_reply(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'reviews')
            ->assertSee('Needs reply')
            ->assertSee('Demo AI analysis')
            ->assertDontSee('Reply on Google')
            ->assertDontSee('Reputation Score')
            ->set('review_stars', '2')
            ->assertSee('M. Demir')
            ->assertDontSee('E. Yılmaz')
            ->call('setReviewsSub', 'topics')
            ->assertSee('What customers are talking about')
            ->assertSee('Waiting time')
            ->assertSee('Waiting-time complaints increased')
            ->call('setReviewsSub', 'queue')
            ->assertSee('Response queue')
            ->assertSee('Create task')
            ->call('createReviewTask', 'rv-2')
            ->assertSee('Internal Task created');
    }

    public function test_competitors_observed_presence_without_market_share(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'competitors')
            ->assertSee('Observed local competitors')
            ->assertSee('Nova Dental Ankara')
            ->assertSee('Capital Smile Clinic')
            ->assertSee('Observed Top 3 presence')
            ->assertSee('17 / 25')
            ->assertDontSee('Market Share')
            ->assertSee('No live Maps scrape');
    }

    public function test_operations_finding_recommendation_task_outcome_chain(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setOps', 'findings')
            ->assertSee('GBP phone number differs')
            ->call('openFinding', 'gf-phone-mismatch')
            ->assertSee('What happened?')
            ->assertSee('Why it matters')
            ->assertSee('Tasks are not auto-created')
            ->call('setOps', 'recommendations')
            ->assertSee('Confirm the correct primary business phone')
            ->call('setOps', 'tasks')
            ->assertSee('Confirm correct phone and update controlled sources')
            ->assertSee('blocked')
            ->call('setOps', 'outcomes')
            ->assertSee('Improvement observed')
            ->assertSee('Still observed')
            ->assertDontSee('The task caused rankings')
            ->assertDontSee('Success');
    }

    public function test_header_actions_and_cross_asset_links(): void
    {
        Livewire::test(OverviewPage::class)
            ->assertSee('Refresh data')
            ->assertSee('Run local visibility scan')
            ->assertSee('Open Brand')
            ->assertSee('Open Website')
            ->call('refreshData')
            ->assertSee('no live Google Business Profile API')
            ->call('runLocalVisibilityScan')
            ->assertSet('tab', 'visibility')
            ->assertSee('Demo Mode');
    }

    public function test_demo_fixtures_are_deterministic(): void
    {
        $a = GbpWorkspaceFixtures::workspace('last_28');
        $b = GbpWorkspaceFixtures::workspace('last_28');

        $this->assertSame($a['glance'], $b['glance']);
        $this->assertSame($a['visibility']['scans']['ankara implant']['current']['points'], $b['visibility']['scans']['ankara implant']['current']['points']);
        $this->assertSame($a['reviews']['glance']['total'], $b['reviews']['glance']['total']);
        $this->assertSame($a['competitors']['rows'][0]['name'], $b['competitors']['rows'][0]['name']);
    }
}
