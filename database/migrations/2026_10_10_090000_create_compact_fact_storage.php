<?php

use App\Services\DataPool\Compact\CompactFactStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compact fact storage (PostgreSQL only): one shared text dictionary and narrow, monthly-partitioned fact tables.
 * Converting a logical table (data copy + swapping it for a view with the same columns) is done by
 * `moxdop:db:compact`, table by table, because it moves data. SQLite (tests) keeps the regular tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS fact_dims (
            id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            kind smallint NOT NULL,
            value text NOT NULL,
            value_hash uuid NOT NULL,
            UNIQUE (kind, value_hash)
        )');

        foreach ((array) config('moxdop-compact-facts.tables') as $spec) {
            $fact = (string) $spec['fact'];
            $dims = count((array) $spec['dims']);
            $dimColumns = implode('', array_map(fn (int $i): string => "d{$i} integer NOT NULL, ", range(1, $dims)));
            $key = implode(', ', array_map(fn (int $i): string => "d{$i}", range(1, $dims)));
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$fact}\" (
                resource_id integer NOT NULL,
                reporting_date date NOT NULL,
                site_id integer NOT NULL,
                search_type_id integer NOT NULL,
                {$dimColumns}
                clicks integer NOT NULL DEFAULT 0,
                impressions integer NOT NULL DEFAULT 0,
                position real NULL,
                digital_asset_id integer NULL,
                run_id integer NULL,
                collected_at timestamptz NOT NULL,
                PRIMARY KEY (resource_id, reporting_date, site_id, search_type_id, {$key})
            ) PARTITION BY RANGE (reporting_date)");
            DB::statement("CREATE INDEX IF NOT EXISTS \"{$fact}_asset_idx\" ON \"{$fact}\" (digital_asset_id, reporting_date) WHERE digital_asset_id IS NOT NULL");
        }

        // Empty tables (fresh installs, emptied families) switch to the compact view right away.
        foreach (array_keys((array) config('moxdop-compact-facts.tables')) as $logical) {
            app(CompactFactStore::class)->convertIfEmpty($logical);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ((array) config('moxdop-compact-facts.tables') as $logical => $spec) {
            $isView = DB::selectOne("select 1 as x from pg_class where relname = ? and relkind = 'v'", [$logical]);
            if ($isView === null) {
                DB::statement('DROP TABLE IF EXISTS "'.$spec['fact'].'" CASCADE');
            }
        }
    }
};
