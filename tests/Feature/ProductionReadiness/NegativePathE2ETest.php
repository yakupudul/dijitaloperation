<?php

namespace Tests\Feature\ProductionReadiness;

use App\Enums\DataPool\DataSourceState;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistReadService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prompt 68 negative / demo-free production path checks.
 */
class NegativePathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_ga4_asset_does_not_fall_back_to_demo_fixtures(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'ga4',
            'module_id' => 'google_analytics',
        ]);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $asset->id, 'last_28');
        $this->assertNotSame('demo_catalog', $workspace['migration_mode'] ?? null);
        $this->assertSame([], $workspace['needs_attention'] ?? ['not-empty']);
        $this->assertSame([], $workspace['opportunities'] ?? ['not-empty']);
        foreach ($workspace['data_provenance'] ?? [] as $state) {
            $this->assertNotSame(DataSourceState::Demo->value, $state);
        }
    }
}
