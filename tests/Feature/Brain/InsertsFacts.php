<?php

namespace Tests\Feature\Brain;

use App\Services\DataPool\Compact\CompactFactStore;
use App\Services\DataPool\PartitionManager;
use Illuminate\Support\Facades\DB;

/** Writes provider fact rows the way production does: through the compact store on PostgreSQL, plain insert on SQLite. */
trait InsertsFacts
{
    /** @param  array<string, mixed>  $row */
    private function insertFact(string $table, array $row): void
    {
        $store = app(CompactFactStore::class);
        if (DB::getDriverName() === 'pgsql' && $store->isCompact($table)) {
            $fact = (string) ($store->spec($table)['fact'] ?? $table);
            app(PartitionManager::class)->ensureRange($fact, (string) $row['reporting_date'], (string) $row['reporting_date']);
            $store->upsert($table, [$row]);

            return;
        }
        DB::table($table)->insert($row);
    }

    /**
     * Drop-in for DB::table($table)->insert($rows): one row or a list of rows.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $rows
     */
    private function insertFacts(string $table, array $rows): void
    {
        foreach (array_is_list($rows) ? $rows : [$rows] as $row) {
            $this->insertFact($table, $row);
        }
    }
}
