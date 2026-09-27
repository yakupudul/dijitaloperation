<?php

namespace Tests;

use App\Services\DataPool\Compact\CompactFactStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /** PostgreSQL test database: monthly fact partitions created once per process (see preparePostgresPartitions). */
    private static bool $postgresPartitionsPrepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        // CI/gate does not always produce public/build; Filament HTTP tests must not require Vite assets.
        $this->withoutVite();

        // Compact fact storage caches dictionary ids per process; each test's rolled-back data must not leak.
        CompactFactStore::forgetCache();

        // Tests never reach real providers: any HTTP call without a matching fake fails loudly.
        Http::preventStrayRequests();

        $this->preparePostgresPartitions();
    }

    /**
     * On PostgreSQL, fact tables are range-partitioned by month and production writers create the month partition
     * before writing (PartitionManager::ensureRange). Many tests seed facts with plain inserts, so once per process
     * each partitioned table of the migrated test database gets a DEFAULT partition that takes rows of any month.
     * The DDL runs on a separate connection so it is committed and outlives the per-test transaction; every test's
     * rows are rolled back, so the default partition is empty whenever a test creates a month partition.
     * No-op on SQLite.
     */
    private function preparePostgresPartitions(): void
    {
        if (self::$postgresPartitionsPrepared || ! isset($this->app) || DB::getDriverName() !== 'pgsql') {
            return;
        }

        $name = 'pgsql_test_partitions';
        config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
        $ddl = DB::connection($name);
        try {
            $tables = collect($ddl->select(
                'SELECT c.relname FROM pg_partitioned_table p JOIN pg_class c ON c.oid = p.partrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema()'
            ))->pluck('relname')->map(fn ($table): string => (string) $table);
            if ($tables->isEmpty()) {
                return; // not migrated yet (test without RefreshDatabase); try again in a later test
            }
            self::$postgresPartitionsPrepared = true;

            foreach ($tables as $table) {
                try {
                    $ddl->statement(sprintf('CREATE TABLE IF NOT EXISTS "%s_default" PARTITION OF "%s" DEFAULT', $table, $table));
                } catch (Throwable) {
                    // the table already has a default partition
                }
            }
        } finally {
            DB::purge($name);
        }
    }
}
