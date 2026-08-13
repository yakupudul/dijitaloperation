<?php

namespace Tests\Feature;

use App\Livewire\Demo\GoogleAds\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Demo\GoogleAdsWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleAdsOperatingWorkspaceTest extends TestCase
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

    public function test_primary_tabs_render(): void
    {
        foreach (['overview', 'campaigns', 'search_demand', 'ads_assets', 'landing_pages', 'measurement', 'operations'] as $tab) {
            $this->get(route('demo.google-ads.overview', ['tab' => $tab]))
                ->assertOk()
                ->assertSee('Atlas Dental — Europe')
                ->assertSee('Google Ads');
        }
    }

    public function test_legacy_tabs_remap(): void
    {
        Livewire::test(OverviewPage::class, ['tab' => 'search_terms'])
            ->assertSet('tab', 'search_demand')
            ->assertSee('Search & demand');

        Livewire::test(OverviewPage::class, ['tab' => 'conversions'])
            ->assertSet('tab', 'measurement')
            ->assertSee('Measurement');

        Livewire::test(OverviewPage::class, ['tab' => 'insights'])
            ->assertSet('tab', 'overview')
            ->assertSee('Needs attention');
    }

    public function test_overview_glance_pacing_and_no_fake_scores(): void
    {
        Livewire::test(OverviewPage::class)
            ->assertSee('₺48,320')
            ->assertSee('114')
            ->assertSee('₺424')
            ->assertSee('Ahead of plan')
            ->assertSee('Needs attention')
            ->assertSee('Budget pacing')
            ->assertSee('Campaign portfolio')
            ->assertSee('Search demand')
            ->assertSee('Landing pages')
            ->assertSee('Measurement')
            ->assertSee('Recent outcomes')
            ->assertDontSee('PPC Score')
            ->assertDontSee('Optimization Score')
            ->assertDontSee('Account Score')
            ->assertDontSee('Wasted spend');
    }

    public function test_campaign_context_and_detail_drawer(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'campaigns')
            ->assertSee('Post Bariatric — UK Search')
            ->call('openCampaign', 'camp-pb-uk')
            ->assertSee('Campaign Context')
            ->assertSee('Turkey/Istanbul intent required')
            ->assertSee('Qualified treatment enquiry')
            ->assertSee('Lost IS · budget')
            ->assertSee('Lost IS · rank')
            ->assertSee('does not mutate Google Ads');
    }

    public function test_search_demand_inbox_and_no_external_write(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setSearchSub', 'inbox')
            ->assertSee('Decision Inbox')
            ->assertSee('Negative candidates')
            ->assertSee('breast lift cost uk')
            ->call('openCluster', 'cluster-bl-price-uk')
            ->assertSee('Why surfaced')
            ->assertSee('Mark reviewed')
            ->assertSee('External Google Ads keyword writes remain disabled')
            ->assertDontSee('Pause campaign')
            ->call('setSearchSub', 'drift')
            ->assertSee('Search intent drift')
            ->assertSee('Spend requiring review')
            ->call('setClassificationFilter', 'Keep')
            ->assertSet('tab', 'search_demand')
            ->assertSee('post bariatric dental turkey');
    }

    public function test_landing_pages_and_website_cross_link(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'landing_pages')
            ->assertSee('/implant/')
            ->assertSee('Message')
            ->call('openLanding', 'lp-implant')
            ->assertSee('Message match')
            ->assertSee('Open Website finding')
            ->assertSee('no numeric match score');
    }

    public function test_measurement_debt_and_missing_not_zero(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'measurement')
            ->assertSee('Conversion mapping')
            ->assertSee('Needs mapping')
            ->assertSee('No recent signal')
            ->assertSee('Measurement debt')
            ->assertSee('Possible duplicate lead measurement')
            ->assertSee('GA4 · measured Website behavior')
            ->assertSee('Performance interpretation limited')
            ->assertDontSee('Measurement Score');
    }

    public function test_operations_chain_and_causal_safety(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setOps', 'findings')
            ->assertSee('Search intent has materially drifted')
            ->call('openFinding', 'gads-f-intent-drift')
            ->assertSee('What happened')
            ->assertSee('Tasks are not auto-created')
            ->call('setOps', 'outcomes')
            ->assertSee('Improvement observed')
            ->assertSee('Insufficient evidence')
            ->assertSee('Decision history')
            ->assertDontSee('The Task caused')
            ->assertDontSee('Negative keyword cleanup caused');
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
