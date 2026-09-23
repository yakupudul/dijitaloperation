<?php

namespace Tests\Feature\Assets;

use App\Livewire\Operator\Meta\OverviewPage;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Meta Ads asset page: no auto-picked account without an id, recorded recommendations appear on the
 * insights tab and the header runs the analysis.
 */
final class MetaAssetPageTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Örnek Meta', 'module_id' => 'meta-ads']);
    }

    public function test_missing_asset_id_goes_to_the_filtered_asset_list(): void
    {
        $this->get(route('operator.meta.overview'))->assertRedirect(route('operator.assets', ['type' => 'meta_ads']));
    }

    public function test_insights_tab_lists_recorded_recommendations_and_header_runs_analysis(): void
    {
        $plan = AdvisorPlan::query()->create(['channel' => 'meta_ads', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->asset->id, 'status' => 'completed']);
        AdvisorItem::query()->create(['channel' => 'meta_ads', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->asset->id, 'item_key' => 'k1', 'category' => 'waste', 'rule_id' => 'x', 'severity' => 'high', 'priority_score' => 800, 'title' => 'Frekansı yüksek reklam setini yenile', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id]);

        $page = Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'operations']);
        $recommendations = $page->viewData('data')['operations']['recommendations'] ?? [];

        $this->assertSame(['Frekansı yüksek reklam setini yenile'], array_column($recommendations, 'title'));
        $page->assertSeeHtml('wire:click="runAnalysis"');
    }
}
