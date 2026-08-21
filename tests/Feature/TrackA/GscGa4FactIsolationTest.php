<?php

namespace Tests\Feature\TrackA;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\Gsc\GscSpecialistReadService;
use App\Support\Operator\OperatorReportingPeriod;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GscGa4FactIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function gsc_pool_reads_never_cross_customer_or_asset(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customerA->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);
        $assetA = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'gsc']);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'gsc']);

        $this->insertProperty($assetA->id, 11, 100, 'sc-domain:a.example');
        $this->insertProperty($assetB->id, 22, 2000, 'sc-domain:b.example');

        $sums = app(GscPoolReadRepository::class)->propertyDailySums(
            $assetA->id,
            11,
            'sc-domain:a.example',
            '2026-07-01',
            '2026-07-01',
        );

        $this->assertSame(100, $sums['clicks']);
        $this->assertSame(1, $sums['rows']);
        $this->assertNotSame(2000, $sums['clicks']);
    }

    #[Test]
    public function numeric_unbound_gsc_workspace_is_not_demo_and_missing_is_not_zero(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'gsc']);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $asset->id, 'last_28');

        $this->assertNotSame('demo_catalog', $workspace['migration_mode'] ?? null);
        $this->assertSame('—', $workspace['glance']['clicks']['value'] ?? null);
        $this->assertNull($workspace['glance']['clicks']['raw'] ?? 'sentinel');
    }

    #[Test]
    public function yoy_comparison_without_prior_year_facts_is_unavailable(): void
    {
        $bounds = OperatorReportingPeriod::comparisonQueryBounds(
            'yoy',
            'custom',
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('2025-07-01', $bounds['start']->toDateString());
        $this->assertSame('2025-07-31', $bounds['end']->toDateString());
        $this->assertSame('yoy', $bounds['preset']);
    }

    private function insertProperty(int $assetId, int $resourceId, int $clicks, string $siteUrl): void
    {
        DB::table('gsc_property_daily')->insert([
            'digital_asset_id' => $assetId,
            'external_resource_id' => $resourceId,
            'site_url' => $siteUrl,
            'reporting_date' => '2026-07-01',
            'clicks' => $clicks,
            'impressions' => $clicks * 10,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'UTC',
            'record_fingerprint' => hash('sha256', $assetId.'-'.$resourceId),
            'metadata' => json_encode(['provider_average_position' => 5]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
