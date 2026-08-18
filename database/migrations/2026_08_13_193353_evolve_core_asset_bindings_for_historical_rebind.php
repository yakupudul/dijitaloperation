<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 23 — historical Binding support.
 *
 * Absolute uniqueness on (digital_asset_id, capability) and
 * (digital_asset_id, external_resource_id) prevented deactivate+create rebind.
 * Active-only partial unique indexes preserve history while keeping cardinality.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('core_asset_bindings')) {
            return;
        }

        $this->dropIndexIfExists('core_asset_bindings_unique_resource');
        $this->dropIndexIfExists('core_asset_bindings_unique_capability');

        if (! $this->indexExists('core_asset_bindings_active_pair_unique')) {
            DB::statement(
                "CREATE UNIQUE INDEX core_asset_bindings_active_pair_unique ON core_asset_bindings (digital_asset_id, external_resource_id) WHERE status = 'active'"
            );
        }

        if (! $this->indexExists('core_asset_bindings_active_capability_unique')) {
            DB::statement(
                "CREATE UNIQUE INDEX core_asset_bindings_active_capability_unique ON core_asset_bindings (digital_asset_id, capability) WHERE status = 'active'"
            );
        }

        if (! $this->indexExists('core_asset_bindings_active_resource_unique')) {
            DB::statement(
                "CREATE UNIQUE INDEX core_asset_bindings_active_resource_unique ON core_asset_bindings (external_resource_id) WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('core_asset_bindings')) {
            return;
        }

        $this->dropIndexIfExists('core_asset_bindings_active_pair_unique');
        $this->dropIndexIfExists('core_asset_bindings_active_capability_unique');
        $this->dropIndexIfExists('core_asset_bindings_active_resource_unique');

        Schema::table('core_asset_bindings', function (Blueprint $table): void {
            $table->unique(
                ['digital_asset_id', 'external_resource_id'],
                'core_asset_bindings_unique_resource',
            );
            $table->unique(
                ['digital_asset_id', 'capability'],
                'core_asset_bindings_unique_capability',
            );
        });
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! $this->indexExists($index)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS \"{$index}\"");

            return;
        }

        if ($driver === 'pgsql') {
            // Laravel $table->unique() creates a named UNIQUE CONSTRAINT on PostgreSQL.
            // DROP INDEX fails while the constraint still exists (SQLSTATE 2BP01).
            DB::statement("ALTER TABLE core_asset_bindings DROP CONSTRAINT IF EXISTS {$index}");
            DB::statement("DROP INDEX IF EXISTS {$index}");

            return;
        }

        try {
            Schema::table('core_asset_bindings', function (Blueprint $table) use ($index): void {
                $table->dropUnique($index);
            });
        } catch (Throwable) {
            // Best-effort for other drivers.
        }
    }

    private function indexExists(string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index],
            );

            return $rows !== [];
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$index]);

            return $rows !== [];
        }

        return false;
    }
};
