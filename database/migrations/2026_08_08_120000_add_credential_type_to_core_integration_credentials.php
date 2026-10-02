<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split Integration credentials into provider (application) vs authorization (OAuth tokens).
 * Existing rows are treated as authorization credentials (backwards compatible).
 *
 * Greenfield installs already create `credential_type` in 2026_08_08_100001; this migration
 * upgrades databases that ran the original unique(integration_id)-only schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('core_integration_credentials')) {
            return;
        }

        if (! Schema::hasColumn('core_integration_credentials', 'credential_type')) {
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->string('credential_type', 32)->default('authorization')->after('integration_id');
            });
        }

        DB::table('core_integration_credentials')
            ->where(function ($query): void {
                $query->whereNull('credential_type')->orWhere('credential_type', '');
            })
            ->update(['credential_type' => 'authorization']);

        // Avoid try/catch around DDL on PostgreSQL: a failed statement aborts the migration transaction.
        if ($this->hasIndexNamed('core_integration_credentials_integration_id_unique')) {
            $this->dropUniqueIfExists('core_integration_credentials_integration_id_unique');
        }

        if (! $this->hasIndexNamed('core_integration_credentials_integration_type_unique')) {
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->unique(['integration_id', 'credential_type'], 'core_integration_credentials_integration_type_unique');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive relative to greenfield: only reverse changes this migration made.
        // Do not drop credential_type when newer installs created it in 100001 — full table
        // rollback is owned by that migration.
        if (! Schema::hasTable('core_integration_credentials')) {
            return;
        }

        // If both credential types exist for one integration, collapsing uniqueness is unsafe.
        $duplicates = DB::table('core_integration_credentials')
            ->select('integration_id')
            ->groupBy('integration_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            return;
        }

        // Leave schema as-is for safety; rolling back further drops the table via 100001.
    }

    private function dropUniqueIfExists(string $indexName): void
    {
        try {
            Schema::table('core_integration_credentials', function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        } catch (Throwable) {
            // ignore
        }
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list(core_integration_credentials)');

            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = $connection->select(
                'SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND INDEX_NAME = ? LIMIT 1',
                [$database, 'core_integration_credentials', $indexName],
            );

            return $rows !== [];
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                ['core_integration_credentials', $indexName],
            );

            return $rows !== [];
        }

        return false;
    }
};
