<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filament database notifications query `data->>'format'` which requires JSON
 * on PostgreSQL. Laravel's default notifications migration stored `data` as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};
