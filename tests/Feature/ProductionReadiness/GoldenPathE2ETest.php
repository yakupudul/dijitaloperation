<?php

namespace Tests\Feature\ProductionReadiness;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prompt 68 golden path using production services + synthetic records.
 * Does not use DemoState / DemoCatalog fixtures as business truth.
 */
class GoldenPathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_path_asset_ids_are_numeric_production_ids(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        $this->assertTrue(ctype_digit((string) $asset->id));
        $this->assertDoesNotMatchRegularExpression('/^atlas-/', (string) $asset->id);
    }
}
