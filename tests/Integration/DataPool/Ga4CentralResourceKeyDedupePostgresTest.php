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
 * A GA4 resource rebound between Digital Assets left rows differing only by
 * digital_asset_id; the migration must dedupe them before creating the
 * resource-first unique indexes, without touching rows whose new key column is NULL.
 */
#[Group('postgres')]
class Ga4CentralResourceKeyDedupePostgresTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'database/migrations/2026_08_22_001000_expand_ga4_central_resource_data_pool.php';

    private const string LANDING_KEY_MIGRATION = 'database/migrations/2026_08_22_011500_align_ga4_landing_resource_key.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }
    }

    #[Test]
    public function rebound_asset_rows_are_deduped_to_the_latest_before_the_resource_unique_index(): void
    {
        DB::statement('DROP INDEX IF EXISTS ga4_property_daily_resource_nk_unique');
        DB::statement('DROP INDEX IF EXISTS ga4_landing_page_daily_resource_nk_unique');
        DB::statement('DROP INDEX IF EXISTS ga4_landing_page_daily_resource_landing_nk_unique');
        $this->assertFalse(Schema::hasIndex('ga4_property_daily', 'ga4_property_daily_resource_nk_unique'));
        app(PartitionManager::class)->ensureRange('ga4_landing_page_daily', '2026-07-01', '2026-07-01');

        $this->insertProperty(assetId: 11, sessions: 5, collectedAt: '2026-08-01 10:00:00');
        $this->insertProperty(assetId: 12, sessions: 9, collectedAt: '2026-08-05 10:00:00');
        $this->insertProperty(assetId: 11, sessions: 3, collectedAt: '2026-08-01 10:00:00', date: '2026-07-02');

        // Legacy landing rows have no landingPagePlusQueryString: they never collide in the
        // plus-query-string index (only in the landingPage key, deduped by its own migration).
        $this->insertLanding(assetId: 11, page: '/a', plusQuery: null, collectedAt: '2026-08-07 10:00:00');
        $this->insertLanding(assetId: 12, page: '/a', plusQuery: null, collectedAt: '2026-08-03 10:00:00');
        $this->insertLanding(assetId: 11, page: '/b', plusQuery: null);
        $this->insertLanding(assetId: 11, page: '/c', plusQuery: '/c?x=1', collectedAt: '2026-08-06 10:00:00');
        $this->insertLanding(assetId: 12, page: '/c', plusQuery: '/c?x=1', collectedAt: '2026-08-02 10:00:00');

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $this->assertSame(4, DB::table('ga4_landing_page_daily')->count(), 'NULL plus-query rows are not deduped by the plus-query key');
        $migration->up();

        $landingKey = require base_path(self::LANDING_KEY_MIGRATION);
        $landingKey->up();
        $landingKey->up();

        $this->assertTrue(Schema::hasIndex('ga4_property_daily', 'ga4_property_daily_resource_nk_unique'));
        $this->assertTrue(Schema::hasIndex('ga4_landing_page_daily', 'ga4_landing_page_daily_resource_nk_unique'));
        $this->assertTrue(Schema::hasIndex('ga4_landing_page_daily', 'ga4_landing_page_daily_resource_landing_nk_unique'));

        $rows = DB::table('ga4_property_daily')->orderBy('reporting_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(12, (int) $rows[0]->digital_asset_id);
        $this->assertSame(9, (int) $rows[0]->sessions);

        $this->assertSame(3, DB::table('ga4_landing_page_daily')->count());
        $this->assertSame(11, (int) DB::table('ga4_landing_page_daily')->where('landingPage', '/c')->value('digital_asset_id'));
        $this->assertSame(11, (int) DB::table('ga4_landing_page_daily')->where('landingPage', '/a')->value('digital_asset_id'));
    }

    private function insertProperty(int $assetId, int $sessions, string $collectedAt, string $date = '2026-07-01'): void
    {
        DB::table('ga4_property_daily')->insert([
            'digital_asset_id' => $assetId,
            'external_resource_id' => 77,
            'property_id' => '123',
            'reporting_date' => $date,
            'sessions' => $sessions,
            'contract_version' => 1,
            'first_collected_at' => $collectedAt,
            'last_collected_at' => $collectedAt,
            'record_fingerprint' => hash('sha256', $assetId.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLanding(int $assetId, string $page, ?string $plusQuery, string $collectedAt = '2026-08-01 10:00:00'): void
    {
        DB::table('ga4_landing_page_daily')->insert([
            'digital_asset_id' => $assetId,
            'external_resource_id' => 77,
            'property_id' => '123',
            'reporting_date' => '2026-07-01',
            'landingPage' => $page,
            'landingPagePlusQueryString' => $plusQuery,
            'sessions' => 1,
            'contract_version' => 1,
            'first_collected_at' => $collectedAt,
            'last_collected_at' => $collectedAt,
            'record_fingerprint' => hash('sha256', $assetId.'-'.$page),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
