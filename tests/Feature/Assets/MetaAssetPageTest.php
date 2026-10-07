<?php

namespace Tests\Feature\Assets;

use App\Livewire\Operator\Meta\OverviewPage;
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
 * Meta asset page: no auto-picked account without an id; old per-entity pages and old tab keys land on the new tabs.
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

    public function test_old_meta_entity_pages_redirect_to_the_matching_tab(): void
    {
        $id = $this->asset->id;
        $cases = [
            ['operator.meta.campaigns', [], ['tab' => 'campaigns']],
            ['operator.meta.adsets', [], ['tab' => 'campaigns', 'level' => 'adsets']],
            ['operator.meta.adset', ['adSetId' => '456'], ['tab' => 'campaigns', 'level' => 'adsets']],
            ['operator.meta.ads', [], ['tab' => 'campaigns', 'level' => 'ads']],
            ['operator.meta.ad', ['adId' => '789'], ['tab' => 'campaigns', 'level' => 'ads']],
            ['operator.meta.creatives', [], ['tab' => 'creatives']],
            ['operator.meta.breakdowns', [], ['tab' => 'audience']],
            ['operator.meta.insights', [], ['tab' => 'overview']],
        ];

        foreach ($cases as [$name, $extra, $target]) {
            $this->get(route($name, ['assetId' => $id] + $extra))
                ->assertRedirect(route('operator.meta.overview', ['assetId' => (string) $id] + $target));
        }
    }

    public function test_old_meta_entity_pages_reject_non_meta_assets(): void
    {
        $website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);

        $this->get(route('operator.meta.campaigns', ['assetId' => $website->id]))->assertNotFound();
        $this->get(route('operator.meta.insights', ['assetId' => 'not-a-number']))->assertNotFound();
    }

    public function test_old_tab_keys_open_the_matching_new_tab(): void
    {
        foreach (['overview' => 'campaigns', 'campaigns' => 'campaigns', 'audience' => 'analysis', 'funnel' => 'analysis', 'creatives' => 'todo', 'strategy' => 'todo', 'measurement' => 'measurement', 'operations' => 'todo', 'advisor' => 'todo'] as $old => $new) {
            Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $old])->assertSet('tab', $new);
        }
    }
}
