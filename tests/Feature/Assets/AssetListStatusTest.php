<?php

namespace Tests\Feature\Assets;

use App\Livewire\Demo\Portfolio\AssetEdit;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\AssetRuntimeStatusReader;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The asset list shows real connection, freshness and open work; assets have an operator edit form and
 * the Data Sources page renders.
 */
final class AssetListStatusTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
    }

    public function test_status_reflects_bindings_sync_age_and_open_work(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $fresh = $this->boundAsset('google_business_profile', 'Taze Profil', $integration, now()->subHours(5));
        $stale = $this->boundAsset('google_business_profile', 'Eski Profil', $integration, now()->subDays(6));
        $boundEmpty = $this->boundAsset('google_ads', 'Boş Ads', $integration, null);
        $unbound = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'meta_ads', 'name' => 'Bağsız Meta']);
        $instagram = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'instagram', 'name' => 'Insta']);

        $plan = AdvisorPlan::query()->create(['channel' => 'google_business_profile', 'brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $fresh->id, 'status' => 'completed']);
        AdvisorItem::query()->create(['channel' => 'google_business_profile', 'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $fresh->id, 'item_key' => 'k1', 'category' => 'profile', 'rule_id' => 'x', 'severity' => 'high', 'priority_score' => 800, 'title' => 'Açıklama yaz', 'reason' => 'r', 'evidence' => [], 'checklist' => [], 'status' => 'open', 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id]);

        $status = app(AssetRuntimeStatusReader::class)->forAssets(DigitalAsset::query()->get());

        $this->assertSame('fresh', $status[$fresh->id]['data_state']);
        $this->assertSame(1, $status[$fresh->id]['open_tasks']);
        $this->assertSame('stale', $status[$stale->id]['data_state']);
        $this->assertSame('unavailable', $status[$boundEmpty->id]['data_state']);
        $this->assertTrue($status[$boundEmpty->id]['connected']);
        $this->assertSame(__('operator.states.not_collected'), $status[$boundEmpty->id]['data_state_label']);
        $this->assertSame(__('operator.states.not_connected'), $status[$unbound->id]['data_state_label']);
        $this->assertSame('not_applicable', $status[$instagram->id]['data_state']);

        Livewire::test(AssetsIndex::class)
            ->assertSee(__('operator.states.fresh'))
            ->assertSee(__('operator.states.stale'))
            ->call('setQuickView', 'data_issues')
            ->assertSee('Eski Profil')
            ->assertSee('Boş Ads')
            ->assertDontSee('Taze Profil');
    }

    public function test_edit_form_updates_asset_and_keeps_type(): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'Eski Ad', 'domain' => 'ornek.test']);

        $this->get(route('operator.asset.edit', ['assetId' => $asset->id]))->assertOk()->assertSee(__('operator.forms.edit_digital_asset'));
        Livewire::test(AssetEdit::class, ['assetId' => (string) $asset->id])
            ->assertSet('name', 'Eski Ad')
            ->set('name', 'Yeni Ad')
            ->set('type', 'meta_ads')
            ->set('domain', 'yeni.test')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('operator.website', ['assetId' => $asset->id]));

        $asset->refresh();
        $this->assertSame('Yeni Ad', $asset->name);
        $this->assertSame('website', $asset->type, 'type is fixed after creation');
        $this->assertSame('yeni.test', $asset->domain);
        $this->get(route('operator.asset.edit', ['assetId' => 999999]))->assertNotFound();
    }

    public function test_data_sources_page_renders_with_a_website_collection_run(): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'Site', 'domain' => 'ornek.test', 'primary_url' => 'https://ornek.test/']);

        $this->get(route('operator.asset.sources', ['assetId' => $asset->id]))
            ->assertOk()
            ->assertSee(route('operator.website', ['assetId' => $asset->id]), false);
    }

    private function boundAsset(string $type, string $name, CoreIntegration $integration, ?Carbon $syncedAt): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'name' => $name]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => 'ext/'.$name, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $type, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        if ($syncedAt !== null) {
            DB::table('gbp_location_snapshots')->insert(['run_id' => random_int(1000, 999999), 'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'location_name' => 'locations/'.$resource->id, 'captured_at' => $syncedAt, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $asset;
    }
}
