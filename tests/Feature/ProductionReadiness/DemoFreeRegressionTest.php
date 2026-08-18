<?php

namespace Tests\Feature\ProductionReadiness;

use App\Livewire\Demo\Dashboard;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Gsc\GscSpecialistReadService;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Support\Reality\DemoCatalogAssetGuard;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 68 final Prompt67 reality regression — production numeric IDs must not Demo-fallback.
 */
class DemoFreeRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_production_asset_ids_are_not_demo_catalog(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'ga4']);

        $this->assertFalse(DemoCatalogAssetGuard::isDemoCatalogAssetId((string) $asset->id));
        $this->assertTrue(ctype_digit((string) $asset->id));
    }

    public function test_specialist_services_do_not_emit_demo_catalog_mode_for_numeric_ids(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $ga4 = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'ga4', 'module_id' => 'google_analytics']);
        $gsc = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'gsc', 'module_id' => 'search_console']);
        $meta = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'module_id' => 'meta_ads']);

        foreach ([
            app(Ga4SpecialistReadService::class)->workspace((string) $ga4->id, 'last_28'),
            app(GscSpecialistReadService::class)->workspace((string) $gsc->id, 'last_28'),
            app(MetaAdsSpecialistReadService::class)->workspace((string) $meta->id, 'last_28'),
        ] as $workspace) {
            $this->assertNotSame('demo_catalog', $workspace['migration_mode'] ?? null);
            $encoded = json_encode($workspace);
            $this->assertStringNotContainsString('Atlas Dental', (string) $encoded);
        }
    }

    public function test_dashboard_does_not_surface_atlas_recent_value_narrative(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Atlas Dental — GA4');
    }
}
