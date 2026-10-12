<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand page speed (panel önerileri §3, 2026-10-11): the site reader's last Search Console day
 * (`max(reporting_date) … where external_resource_id = ? and search_type = 'web'`) and its period sums filter by
 * resource, search type and date. The existing (external_resource_id, reporting_date) index made PostgreSQL read
 * every row of the resource to check search_type; with search_type in the key the max is one index step per
 * partition and the sums read only the window's web rows. On PostgreSQL the table is partitioned by reporting_date, so
 * the index is created on the parent (CONCURRENTLY is not allowed on a partitioned table).
 *
 * Where moxdop:db:compact turned the table into a view over gsc_f_query_page (live), nothing is created: a view takes
 * no index (the 2026-10-11 deploy failed here) and the fact table's primary key already leads with
 * (resource_id, reporting_date).
 */
return new class extends Migration
{
    private const string TABLE = 'gsc_query_page_daily';

    private const string INDEX = 'gsc_qp_res_type_date_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'search_type') || Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            if (! $this->isPlainTable()) {
                return;
            }
            DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON '.self::TABLE.' (external_resource_id, search_type, reporting_date)');

            return;
        }
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(['external_resource_id', 'search_type', 'reporting_date'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            if (! $this->isPlainTable()) {
                return;
            }
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

            return;
        }
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /** Whether the PostgreSQL relation is a table or partitioned table (not the compact view). */
    private function isPlainTable(): bool
    {
        $kind = DB::selectOne('select c.relkind as k from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = ?', [self::TABLE])->k ?? null;

        return in_array($kind, ['r', 'p'], true);
    }
};
