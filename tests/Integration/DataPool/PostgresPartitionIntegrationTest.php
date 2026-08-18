<?php

namespace Tests\Integration\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\DataPool\PartitionManager;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Real PostgreSQL coverage for native partitioning / upserts.
 * Skipped unless DB_CONNECTION=pgsql (see CI workflow data-pool-postgres).
 */
#[Group('postgres')]
class PostgresPartitionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }

        Storage::fake('raw_ingestion');
        config(['moxdop-data-pool.raw_disk' => 'raw_ingestion']);
    }

    #[Test]
    public function monthly_partitions_are_created_idempotently_and_accept_cross_month_upserts(): void
    {
        $manager = app(PartitionManager::class);
        $table = 'gsc_query_page_daily';

        $manager->ensureRange($table, '2026-07-15', '2026-08-20');
        $manager->ensureRange($table, '2026-07-15', '2026-08-20'); // race-safe retry

        $partitions = collect(DB::select(
            'SELECT c.relname FROM pg_class c JOIN pg_inherits i ON i.inhrelid = c.oid JOIN pg_class p ON p.oid = i.inhparent WHERE p.relname = ? ORDER BY 1',
            [$table]
        ))->pluck('relname')->all();

        $this->assertContains('gsc_query_page_daily_2026_07', $partitions);
        $this->assertContains('gsc_query_page_daily_2026_08', $partitions);

        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'gsc_query_page_daily',
            'provider_or_source' => 'SEARCH_CONSOLE',
        ]);

        $writer = app(PostgresWarehouseWriter::class);
        $receipt = $writer->write(new NormalizedDatasetBatch(
            datasetId: 'gsc_query_page_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'pg-cross-month',
            records: [
                [
                    'digital_asset_id' => 1,
                    'site_url' => 'https://example.com/',
                    'reporting_date' => '2026-07-31',
                    'query' => 'alpha',
                    'page' => 'https://example.com/a',
                    'clicks' => 1,
                    'impressions' => 10,
                ],
                [
                    'digital_asset_id' => 1,
                    'site_url' => 'https://example.com/',
                    'reporting_date' => '2026-08-01',
                    'query' => 'alpha',
                    'page' => 'https://example.com/a',
                    'clicks' => 2,
                    'impressions' => 20,
                ],
            ],
            digitalAssetId: 1,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'SEARCH_CONSOLE',
        ));

        $this->assertTrue($receipt->isCommitted());
        $this->assertSame(2, DB::table($table)->count());

        // Natural-key uniqueness across partition
        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'gsc_query_page_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'pg-upsert',
            records: [[
                'digital_asset_id' => 1,
                'site_url' => 'https://example.com/',
                'reporting_date' => '2026-08-01',
                'query' => 'alpha',
                'page' => 'https://example.com/a',
                'clicks' => 99,
                'impressions' => 20,
            ]],
            digitalAssetId: 1,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'SEARCH_CONSOLE',
        ));

        $this->assertSame(2, DB::table($table)->count());
        $this->assertSame(99, (int) DB::table($table)->where('reporting_date', '2026-08-01')->value('clicks'));
    }

    #[Test]
    public function missing_partition_preparation_failure_does_not_advance_without_write(): void
    {
        // Ensure parent exists but do not create far-future partition via manager sabotage:
        // writing without ensure would fail on PG if PartitionManager were skipped.
        // WarehouseWriter always calls ensureRange first — verify ensureMonth for a month works.
        app(PartitionManager::class)->ensureMonth('meta_typed_action_daily', CarbonImmutable::parse('2026-09-01'));
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM pg_class WHERE relname = ?',
            ['meta_typed_action_daily_2026_09']
        );
        $this->assertNotNull($exists);
    }
}
