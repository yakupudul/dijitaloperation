<?php

namespace Tests\Feature\TrackA;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Gsc\GscSpecialistReadService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\GscWorkspaceFixtures;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionOperatorDemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function production_numeric_assets_never_read_gsc_or_ga4_workspace_fixtures(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $gsc = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'gsc']);
        $ga4 = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'ga4']);

        $gscWorkspace = app(GscSpecialistReadService::class)->workspace((string) $gsc->id, 'last_28');
        $ga4Workspace = app(Ga4SpecialistReadService::class)->workspace((string) $ga4->id, 'last_28');
        $fixtureClicks = GscWorkspaceFixtures::workspace('last_28')['glance']['clicks']['raw'];

        $this->assertNotSame('demo_catalog', $gscWorkspace['migration_mode'] ?? null);
        $this->assertNotSame('demo_catalog', $ga4Workspace['migration_mode'] ?? null);
        $this->assertNotSame($fixtureClicks, $gscWorkspace['glance']['clicks']['raw'] ?? null);
        $this->assertSame('—', $gscWorkspace['glance']['clicks']['value'] ?? null);
        $this->assertSame('—', $ga4Workspace['glance']['sessions']['value'] ?? $ga4Workspace['glance']['sessions'] ?? '—');
    }

    #[Test]
    public function demo_catalog_string_ids_remain_the_only_fixture_entry(): void
    {
        $workspace = app(GscSpecialistReadService::class)->workspace(DemoCatalog::GSC_ASSET_ID);
        $this->assertSame('demo_catalog', $workspace['migration_mode']);
    }
}
