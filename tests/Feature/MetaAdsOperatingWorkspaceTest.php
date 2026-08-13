<?php

namespace Tests\Feature;

use App\Livewire\Demo\Meta\CampaignDetailPage;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MetaAdsOperatingWorkspaceTest extends TestCase
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

    public function test_primary_tabs_render_without_dead_routes(): void
    {
        $meta = DemoCatalog::META_ASSET_ID;
        foreach (['overview', 'campaigns', 'creatives', 'audience', 'funnel', 'measurement', 'operations'] as $tab) {
            $this->get(route('demo.meta.overview', ['assetId' => $meta, 'tab' => $tab]))
                ->assertOk();
        }
    }

    public function test_overview_scan_surface_and_no_fake_scores(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->assertSee('Atlas Health — Europe')
            ->assertSee('Result Mix')
            ->assertSee('Needs attention')
            ->assertSee('Creative pulse')
            ->assertSee('Budget pacing')
            ->assertDontSee('Fatigue Score')
            ->assertDontSee('Meta Health Score')
            ->assertDontSee('Creative Score')
            ->assertDontSee('Lead Quality Score')
            ->assertDontSee('Total Results');
    }

    public function test_result_mix_not_summed_and_campaign_result_semantics(): void
    {
        $workspace = MetaAdsWorkspaceFixtures::workspace('last_28');
        $labels = collect($workspace['result_mix']['items'])->pluck('label')->all();
        $this->assertContains('Leads', $labels);
        $this->assertContains('Messaging conversations', $labels);
        $this->assertContains('Instagram profile visits', $labels);

        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->call('setTab', 'campaigns')
            ->assertSee('Leads')
            ->assertSee('Messaging conversations')
            ->assertSee('Instagram profile visits');
    }

    public function test_creatives_gallery_fatigue_candidate_and_insufficient_history(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->call('setTab', 'creatives')
            ->assertSee('Creatives')
            ->assertSee('Trust V3')
            ->assertSee('Transformation V2')
            ->assertSee('Fatigue candidate')
            ->assertSee('Insufficient history')
            ->assertSee('Angle performance')
            ->assertDontSee('Fatigue Score')
            ->call('openCreative', 'cr-transform-v2')
            ->assertSet('creative', 'cr-transform-v2');
    }

    public function test_audience_funnel_measurement_operations_stories(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->call('setTab', 'audience')
            ->assertSee('Configured')
            ->assertSee('Observed')
            ->assertSee('Instagram Reels')
            ->call('setTab', 'funnel')
            ->assertSee('Instant Form')
            ->assertSee('Website destination')
            ->assertSee('Messaging')
            ->assertSee('Instagram')
            ->call('setTab', 'measurement')
            ->assertSee('Result mapping')
            ->assertSee('Business outcome funnel')
            ->assertSee('Missing ≠ zero')
            ->assertDontSee('122 Qualified Leads')
            ->call('setOps', 'findings')
            ->assertSee('Transformation V2')
            ->call('openFinding', 'meta-f-fatigue')
            ->assertSet('finding', 'meta-f-fatigue');
    }

    public function test_campaign_context_and_ad_sets_on_detail(): void
    {
        Livewire::test(CampaignDetailPage::class, [
            'assetId' => DemoCatalog::META_ASSET_ID,
            'campaignId' => 'camp-pb-eu',
        ])
            ->assertSee('Post Bariatric — Diaspora Lead')
            ->call('setSection', 'strategy')
            ->assertSee('Meta Campaign Context')
            ->assertSee('Diaspora prospecting')
            ->assertSee('Qualified consultation')
            ->call('setSection', 'adsets')
            ->assertSee('DE Turkish 30–54 Broad');
    }

    public function test_demo_coherence_spend_and_business_funnel_bounds(): void
    {
        $workspace = MetaAdsWorkspaceFixtures::workspace('last_28');
        $campaignSpend = (int) array_sum(array_column($workspace['campaigns'], 'spend'));
        $this->assertSame((int) $workspace['glance']['spend']['raw'], $campaignSpend);

        $leads = collect($workspace['result_mix']['items'])->firstWhere('label', 'Leads');
        $this->assertNotNull($leads);
        $platformLeads = (int) $leads['count'];
        $funnel = $workspace['measurement']['business_outcome_funnel'];
        $this->assertSame('Platform leads', $funnel[0]['stage']);
        $this->assertSame($platformLeads, (int) $funnel[0]['count']);
        for ($i = 1; $i < count($funnel); $i++) {
            // PHPUnit: assertLessThanOrEqual($expected, $actual) ⇒ $actual <= $expected
            $this->assertLessThanOrEqual((int) $funnel[$i - 1]['count'], (int) $funnel[$i]['count']);
        }

        $reload = MetaAdsWorkspaceFixtures::workspace('last_28');
        $this->assertSame($workspace['glance']['spend']['raw'], $reload['glance']['spend']['raw']);
    }

    public function test_no_provider_write_actions_visible(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])
            ->assertDontSee('Pause campaign')
            ->assertDontSee('Edit budget')
            ->assertDontSee('Upload creative')
            ->assertSee('Refresh data')
            ->assertSee('Run analysis');
    }
}
