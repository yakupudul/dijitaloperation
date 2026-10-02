<?php

namespace Tests\Integration\DataPool;

use App\Services\DataPool\PartitionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legacy asset-bound GSC rows collected for two Digital Assets collide on the
 * resource-first natural key; the migration must dedupe before the unique index.
 */
#[Group('postgres')]
class GscCentralResourceKeyDedupePostgresTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'database/migrations/2026_08_22_124500_expand_gsc_central_resource_data_pool.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }
    }

    #[Test]
    public function rows_bound_to_two_assets_are_deduped_to_the_latest_before_the_resource_unique_index(): void
    {
        DB::statement('DROP INDEX IF EXISTS gsc_prop_res_st_nk');
        DB::statement('DROP INDEX IF EXISTS gsc_query_res_st_nk');
        $this->assertFalse(Schema::hasIndex('gsc_property_daily', 'gsc_prop_res_st_nk'));
        app(PartitionManager::class)->ensureRange('gsc_query_daily', '2026-07-01', '2026-07-01');

        $this->insertProperty(assetId: 11, clicks: 5, collectedAt: '2026-08-01 10:00:00');
        $this->insertProperty(assetId: 12, clicks: 9, collectedAt: '2026-08-05 10:00:00');
        $this->insertProperty(assetId: 11, clicks: 3, collectedAt: '2026-08-01 10:00:00', date: '2026-07-02');
        // Rows without a provider resource never collide in the unique index and are kept.
        $this->insertProperty(assetId: 11, clicks: 1, collectedAt: '2026-08-01 10:00:00', date: '2026-07-03', resourceId: null);
        $this->insertProperty(assetId: 12, clicks: 2, collectedAt: '2026-08-02 10:00:00', date: '2026-07-03', resourceId: null);
        $this->insertQuery(assetId: 11, collectedAt: '2026-08-06 10:00:00');
        $this->insertQuery(assetId: 12, collectedAt: '2026-08-02 10:00:00');

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        // Idempotent: a second run neither fails nor deletes anything else.
        $migration->up();

        $this->assertTrue(Schema::hasIndex('gsc_property_daily', 'gsc_prop_res_st_nk'));
        $this->assertTrue(Schema::hasIndex('gsc_query_daily', 'gsc_query_res_st_nk'));

        $this->assertSame(2, DB::table('gsc_property_daily')->whereNull('external_resource_id')->count());

        $rows = DB::table('gsc_property_daily')->whereNotNull('external_resource_id')->orderBy('reporting_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(12, (int) $rows[0]->digital_asset_id);
        $this->assertSame(9, (int) $rows[0]->clicks);
        $this->assertSame('2026-07-02', substr((string) $rows[1]->reporting_date, 0, 10));

        $this->assertSame(1, DB::table('gsc_query_daily')->count());
        $this->assertSame(11, (int) DB::table('gsc_query_daily')->value('digital_asset_id'));
    }

    private function insertProperty(int $assetId, int $clicks, string $collectedAt, string $date = '2026-07-01', ?int $resourceId = 77): void
    {
        DB::table('gsc_property_daily')->insert([
            'digital_asset_id' => $assetId,
            'external_resource_id' => $resourceId,
            'site_url' => 'sc-domain:example.com',
            'reporting_date' => $date,
            'search_type' => 'web',
            'clicks' => $clicks,
            'impressions' => 100,
            'contract_version' => 1,
            'first_collected_at' => $collectedAt,
            'last_collected_at' => $collectedAt,
            'source_timezone' => 'America/Los_Angeles',
            'record_fingerprint' => hash('sha256', $assetId.'-'.$date),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertQuery(int $assetId, string $collectedAt): void
    {
        DB::table('gsc_query_daily')->insert([
            'digital_asset_id' => $assetId,
            'external_resource_id' => 77,
            'site_url' => 'sc-domain:example.com',
            'reporting_date' => '2026-07-01',
            'search_type' => 'web',
            'query' => 'shoes',
            'clicks' => 1,
            'impressions' => 10,
            'contract_version' => 1,
            'first_collected_at' => $collectedAt,
            'last_collected_at' => $collectedAt,
            'source_timezone' => 'America/Los_Angeles',
            'record_fingerprint' => hash('sha256', 'q-'.$assetId),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
