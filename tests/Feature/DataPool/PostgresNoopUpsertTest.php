<?php

namespace Tests\Feature\DataPool;

use App\Services\DataPool\PostgresWarehouseWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Re-collected identical rows must not be rewritten on PostgreSQL (each rewrite leaves a dead row version and
 * bloated the warehouse); changed rows are still updated. Runs only against PostgreSQL.
 */
final class PostgresNoopUpsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_rows_are_not_rewritten_and_changed_rows_are(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only.');
        }
        $writer = app(PostgresWarehouseWriter::class);
        $upsert = new ReflectionMethod($writer, 'postgresUpsert');
        $row = fn (int $clicks, int $run): array => [
            'digital_asset_id' => null, 'external_resource_id' => 999, 'site_url' => 'sc-domain:upsert.test', 'reporting_date' => '2026-09-01',
            'search_type' => 'web', 'clicks' => $clicks, 'impressions' => 10, 'contract_version' => 1, 'last_collection_run_id' => $run,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64),
            'metadata' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
        ];
        $key = ['external_resource_id', 'site_url', 'reporting_date', 'search_type'];
        $update = ['clicks', 'impressions', 'metadata', 'last_collected_at', 'last_collection_run_id', 'contract_version', 'record_fingerprint', 'updated_at'];
        $version = fn (): object => DB::selectOne("select ctid::text as x, clicks, last_collection_run_id as run from gsc_property_daily where site_url = 'sc-domain:upsert.test'");

        $upsert->invoke($writer, 'gsc_property_daily', [$row(5, 1)], $key, $update);
        $first = $version();
        $upsert->invoke($writer, 'gsc_property_daily', [$row(5, 2)], $key, $update);
        $same = $version();
        $upsert->invoke($writer, 'gsc_property_daily', [$row(7, 3)], $key, $update);
        $changed = $version();

        $this->assertSame($first->x, $same->x);
        $this->assertSame(1, (int) $same->run);
        $this->assertNotSame($same->x, $changed->x);
        $this->assertSame(7, (int) $changed->clicks);
        $this->assertSame(3, (int) $changed->run);
    }
}
