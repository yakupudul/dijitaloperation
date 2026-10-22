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
        $this->resetPostgresIds();
    }

    /**
     * PostgreSQL run (audit T2): a rolled-back insert does not give its id back, while tests written on SQLite expect
     * the first row of a table to be 1. Every id sequence continues right after the rows the test can see.
     */
    private function resetPostgresIds(): void
    {
        if (getenv('NO_ID_RESET') || ! isset($this->app) || DB::getDriverName() !== 'pgsql' || DB::transactionLevel() === 0) {
            return;
        }
        try {
            DB::statement(<<<'SQL'
                DO $$
                DECLARE r record;
                BEGIN
                    FOR r IN SELECT s.relname AS seq, t.relname AS tbl, a.attname AS col
                        FROM pg_class s JOIN pg_depend d ON d.objid = s.oid AND d.deptype IN ('a', 'i')
                        JOIN pg_class t ON t.oid = d.refobjid JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
                        JOIN pg_namespace n ON n.oid = s.relnamespace
                        WHERE s.relkind = 'S' AND n.nspname = current_schema() AND t.relkind IN ('r', 'p')
                    LOOP
                        EXECUTE format('SELECT setval(%L, COALESCE((SELECT max(%I) FROM %I), 0) + 1, false)', r.seq, r.col, r.tbl);
                    END LOOP;
                END $$;
                SQL);
        } catch (Throwable) {
            // not migrated yet
        }
    }

    /**
     * A test written for SQLite only (file databases, sqlite3 dumps, SQLite pragmas): on the PostgreSQL run (audit T2,
     * the deploy also tests on the real database type) it is skipped instead of touching the test database.
     */
    protected function onlyOnSqlite(string $why = 'SQLite-specific test'): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped($why);
        }
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
