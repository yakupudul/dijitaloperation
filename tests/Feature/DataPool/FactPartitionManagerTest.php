<?php

namespace Tests\Feature\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\DataPool\PartitionManager;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Production: "no partition of relation gsc_f_page_country found for row". Compact Search Console fact tables are
 * monthly partitioned while their logical datasets are declared without partitioning, so the writer never ensured
 * a partition for them. The writer now ensures partitions for any partitioned target (PostgreSQL); a DEFAULT partition
 * and a daily "ensure next months" command are the safety net. On SQLite the SQL / date-range logic is checked; the
 * PostgreSQL path runs in tests/Integration (group postgres).
 */
final class FactPartitionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_months_cover_a_sixteen_month_backfill_across_year_boundaries(): void
    {
        $partitions = new PartitionManager;
        $months = $partitions->monthsInRange('2025-06-10', '2026-10-02');

        $this->assertCount(17, $months);
        $this->assertSame('gsc_f_page_country_2025_06', $partitions->partitionName('gsc_f_page_country', $months[0]));
        $this->assertSame('gsc_f_page_country_2026_10', $partitions->partitionName('gsc_f_page_country', end($months)));
        // Order-insensitive, single month.
        $this->assertSame(['2026-09-01'], array_map(fn (CarbonImmutable $m): string => $m->toDateString(), $partitions->monthsInRange('2026-09-30', '2026-09-01')));
    }

    public function test_month_partition_sql_moves_rows_out_of_the_default_partition(): void
    {
        $partitions = new PartitionManager;

        $this->assertSame(
            ['CREATE TABLE IF NOT EXISTS "gsc_f_query_2026_10" PARTITION OF "gsc_f_query" FOR VALUES FROM (\'2026-10-01\') TO (\'2026-11-01\')'],
            $partitions->createMonthStatements('gsc_f_query', 'gsc_f_query_2026_10', '2026-10-01', '2026-11-01'),
        );

        $moving = $partitions->createMonthStatements('gsc_f_query', 'gsc_f_query_2026_10', '2026-10-01', '2026-11-01', 'reporting_date', true);
        $this->assertCount(5, $moving);
        $this->assertStringStartsWith('ALTER TABLE "gsc_f_query" DETACH PARTITION "gsc_f_query_default"', $moving[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "gsc_f_query_2026_10" PARTITION OF', $moving[1]);
        $this->assertStringContainsString('INSERT INTO "gsc_f_query" OVERRIDING SYSTEM VALUE SELECT * FROM "gsc_f_query_default" WHERE "reporting_date" >= \'2026-10-01\'', $moving[2]);
        $this->assertStringStartsWith('DELETE FROM "gsc_f_query_default"', $moving[3]);
        $this->assertSame('ALTER TABLE "gsc_f_query" ATTACH PARTITION "gsc_f_query_default" DEFAULT', $moving[4]);

        $this->assertSame('CREATE TABLE IF NOT EXISTS "gsc_f_query_default" PARTITION OF "gsc_f_query" DEFAULT', $partitions->createDefaultStatement('gsc_f_query'));
    }

    public function test_writer_ensures_partitions_for_undeclared_datasets_over_the_written_date_range(): void
    {
        Storage::fake('raw_ingestion');
        config(['moxdop-data-pool.raw_disk' => 'raw_ingestion']);
        $spy = new class extends PartitionManager
        {
            /** @var list<array{0: string, 1: bool, 2: string, 3: string}> */
            public array $calls = [];

            public function ensureForWrite(string $table, bool $declaredPartitioned, CarbonImmutable|string $from, CarbonImmutable|string $to): bool
            {
                $this->calls[] = [$table, $declaredPartitioned, (string) $from, (string) $to];

                return parent::ensureForWrite($table, $declaredPartitioned, $from, $to);
            }
        };
        $this->app->instance(PartitionManager::class, $spy);
        $run = CollectionDatasetRun::factory()->create(['dataset_contract_id' => 'gsc_query_country_daily', 'provider_or_source' => 'SEARCH_CONSOLE']);
        $record = fn (string $date, string $query): array => [
            'external_resource_id' => 9, 'site_url' => 'sc-domain:example.test', 'reporting_date' => $date, 'search_type' => 'web',
            'query' => $query, 'country' => 'tur', 'clicks' => 1, 'impressions' => 10,
        ];

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'gsc_query_country_daily', datasetRunId: (int) $run->id, contractVersion: 1, batchKey: 'k1',
            records: [$record('2026-09-02', 'a'), $record('2025-06-30', 'b')], externalResourceId: 9, providerOrSource: 'SEARCH_CONSOLE',
        ));

        $this->assertTrue($receipt->isCommitted());
        // Declared NONE, still asked with the batch's min / max date (on PostgreSQL the compact gsc_f_* target).
        $this->assertSame([['gsc_query_country_daily', false, '2025-06-30', '2026-09-02']], $spy->calls);
        $this->assertFalse($spy->ensureForWrite('gsc_query_country_daily', false, '2026-01-01', '2026-01-31'), 'no partitioning on SQLite');
    }

    public function test_ensure_partitions_command_is_a_safe_no_op_off_postgres(): void
    {
        $this->artisan('moxdop:db:ensure-partitions', ['--months' => 3])
            ->expectsOutputToContain('Yalnız PostgreSQL için')
            ->assertSuccessful();
    }
}
