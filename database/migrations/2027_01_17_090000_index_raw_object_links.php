<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marka Düzeni 1.5: write batches and HTML snapshots point at a raw object ("set null" when it is deleted) without an
 * index on that column, so every raw object the nightly cleanup deleted walked both tables from end to end (2026-11:
 * ~930.000 full scans each, 834 billion rows read on dataset_write_batches alone). With the index a delete is one lookup.
 */
return new class extends Migration
{
    private const array INDEXES = [
        'dataset_write_batches' => 'dataset_write_batches_raw_object_idx',
        'website_html_snapshot' => 'website_html_snapshot_raw_object_idx',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if (! $this->isTable($table) || ! Schema::hasColumn($table, 'raw_ingestion_object_id') || Schema::hasIndex($table, $index)) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->index('raw_ingestion_object_id', $index));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $index) {
            if ($this->isTable($table) && Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
    }

    /** A real table: on PostgreSQL some names are views over compact storage, which take no index. */
    private function isTable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return true;
        }

        return DB::table('information_schema.tables')->where('table_schema', DB::raw('current_schema()'))->where('table_name', $table)
            ->whereIn('table_type', ['BASE TABLE'])->exists();
    }
};
