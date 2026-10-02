<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layouts of generic compact fact tables (GA4, Meta, Google Ads), written by moxdop:db:compact when a table is
 * converted. PostgreSQL only; SQLite (tests) keeps the regular tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS compact_fact_layouts (
            logical varchar(128) PRIMARY KEY,
            layout jsonb NOT NULL,
            created_at timestamptz NULL,
            updated_at timestamptz NULL
        )');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP TABLE IF EXISTS compact_fact_layouts');
    }
};
