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
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class MetaAdsOperatingWorkspaceTest extends TestCase
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

    public function test_catalog_meta_id_is_not_found_on_operator_routes(): void
    {
        $this->get(route('demo.meta.overview', ['assetId' => DemoCatalog::META_ASSET_ID]))->assertNotFound();
        Livewire::test(OverviewPage::class, ['assetId' => DemoCatalog::META_ASSET_ID])->assertStatus(404);
        Livewire::test(CampaignDetailPage::class, [
            'assetId' => DemoCatalog::META_ASSET_ID,
            'campaignId' => 'camp-pb-eu',
        ])->assertStatus(404);
    }

    public function test_real_meta_asset_renders_without_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('meta_ads', 'Northwind Meta', ['module_id' => 'meta-ads']);

        foreach (['overview', 'campaigns', 'creatives', 'audience', 'funnel', 'measurement', 'operations'] as $tab) {
            $this->get(route('demo.meta.overview', ['assetId' => $asset->id, 'tab' => $tab]))
                ->assertOk()
                ->assertDontSee('Atlas Health — Europe')
                ->assertDontSee('Post Bariatric');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertDontSee('Fatigue Score')
            ->assertDontSee('Meta Health Score')
            ->assertDontSee('Creative Score')
            ->assertDontSee('Lead Quality Score')
            ->assertDontSee('Trust V3')
            ->assertDontSee('Transformation V2')
            ->assertDontSee('Pause campaign')
            ->assertDontSee('Edit budget')
            ->assertDontSee('Upload creative')
            ->assertSee('Refresh data')
            ->assertSee('Run analysis');
    }

    public function test_meta_workspace_fixtures_remain_deterministic_outside_http(): void
    {
        $workspace = MetaAdsWorkspaceFixtures::workspace('last_28');
        $labels = collect($workspace['result_mix']['items'])->pluck('label')->all();
        $this->assertContains('Leads', $labels);
        $this->assertContains('Messaging conversations', $labels);
        $this->assertContains('Instagram profile visits', $labels);

        $campaignSpend = (int) array_sum(array_column($workspace['campaigns'], 'spend'));
        $this->assertSame((int) $workspace['glance']['spend']['raw'], $campaignSpend);

        $leads = collect($workspace['result_mix']['items'])->firstWhere('label', 'Leads');
        $this->assertNotNull($leads);
        $funnel = $workspace['measurement']['business_outcome_funnel'];
        $this->assertSame('Platform leads', $funnel[0]['stage']);
        $this->assertSame((int) $leads['count'], (int) $funnel[0]['count']);
        for ($i = 1; $i < count($funnel); $i++) {
            $this->assertLessThanOrEqual((int) $funnel[$i - 1]['count'], (int) $funnel[$i]['count']);
        }

        $reload = MetaAdsWorkspaceFixtures::workspace('last_28');
        $this->assertSame($workspace['glance']['spend']['raw'], $reload['glance']['spend']['raw']);
    }
}
