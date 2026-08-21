<?php

namespace Tests\Integration\DataPool;

use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\PartitionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
class PostgresStorageContractSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }
    }

    #[Test]
    public function every_physical_dataset_column_exists_in_postgres_schema(): void
    {
        $registry = app(DataPoolStorageRegistry::class);

        foreach ($registry->physicalDatasets() as $dataset) {
            $table = (string) $dataset['table'];
            $actual = collect(DB::select(
                'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
                ['public', $table]
            ))->pluck('column_name')->all();

            foreach ($dataset['columns'] as $column) {
                $this->assertContains(
                    (string) $column['name'],
                    $actual,
                    sprintf('Table [%s] is missing storage-contract column [%s]', $table, $column['name'])
                );
            }
        }
    }

    #[Test]
    public function ga4_partitioned_datasets_match_contract_and_support_partition_creation(): void
    {
        $registry = app(DataPoolStorageRegistry::class);
        $partitions = app(PartitionManager::class);

        $expected = [
            'ga4_source_medium_daily' => ['sessionSource', 'sessionMedium', 'engagedSessions'],
            'ga4_campaign_daily' => ['sessionCampaignName', 'engagedSessions'],
            'ga4_landing_page_daily' => ['landingPage', 'engagedSessions'],
            'ga4_event_daily' => ['eventName', 'eventCount'],
            'ga4_event_channel_daily' => ['eventName', 'sessionDefaultChannelGroup', 'eventCount'],
            'ga4_event_campaign_daily' => ['eventName', 'sessionCampaignName', 'eventCount'],
            'ga4_event_landing_daily' => ['eventName', 'landingPage', 'eventCount'],
        ];

        foreach ($expected as $table => $columns) {
            $actual = collect(DB::select(
                'select column_name from information_schema.columns where table_schema = ? and table_name = ?',
                ['public', $table]
            ))->pluck('column_name')->all();

            foreach ($columns as $column) {
                $this->assertContains($column, $actual, "{$table} is missing {$column}");
            }

            $dataset = collect($registry->physicalDatasets())->firstWhere('table', $table);
            $this->assertNotNull($dataset, "Storage contract missing table {$table}");
            $this->assertSame('RANGE_MONTHLY', $dataset['partition_strategy']);

            $partitions->ensureRange($table, '2026-07-01', '2026-08-31');
            $partitionNames = collect(DB::select(
                'SELECT c.relname FROM pg_class c JOIN pg_inherits i ON i.inhrelid = c.oid JOIN pg_class p ON p.oid = i.inhparent WHERE p.relname = ? ORDER BY 1',
                [$table]
            ))->pluck('relname')->all();

            $this->assertContains("{$table}_2026_07", $partitionNames);
            $this->assertContains("{$table}_2026_08", $partitionNames);
        }
    }
}
