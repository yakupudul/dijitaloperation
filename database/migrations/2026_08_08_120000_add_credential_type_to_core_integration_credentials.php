<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split Integration credentials into provider (application) vs authorization (OAuth tokens).
 * Existing rows are treated as authorization credentials (backwards compatible).
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

        $this->dropUniqueIfExists('core_integration_credentials_integration_id_unique');
        $this->dropUniqueIfExists('core_integration_credentials_integration_id_unique');

        // SQLite / generic: try dropping by column list.
        try {
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->dropUnique(['integration_id']);
            });
        } catch (Throwable) {
            // Already dropped or never existed under that definition.
        }

        if (! $this->hasIndexNamed('core_integration_credentials_integration_type_unique')) {
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->unique(['integration_id', 'credential_type'], 'core_integration_credentials_integration_type_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('core_integration_credentials')) {
            return;
        }

        $this->dropUniqueIfExists('core_integration_credentials_integration_type_unique');

        if (Schema::hasColumn('core_integration_credentials', 'credential_type')) {
            // Cannot safely collapse two rows per integration; keep column drop only when unique allows.
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->dropColumn('credential_type');
            });
        }

        try {
            Schema::table('core_integration_credentials', function (Blueprint $table): void {
                $table->unique('integration_id');
            });
        } catch (Throwable) {
            // ignore
        }
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
