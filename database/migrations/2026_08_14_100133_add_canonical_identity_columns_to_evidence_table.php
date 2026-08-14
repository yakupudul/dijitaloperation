<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive canonical Evidence identity columns.
 * Legacy JSON Evidence rows remain valid with null definition/fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evidence')) {
            return;
        }

        Schema::table('evidence', function (Blueprint $table): void {
            if (! Schema::hasColumn('evidence', 'definition_id')) {
                $table->string('definition_id', 128)->nullable()->after('type');
            }
            if (! Schema::hasColumn('evidence', 'evidence_fingerprint')) {
                $table->char('evidence_fingerprint', 64)->nullable()->after('definition_id');
            }
            if (! Schema::hasColumn('evidence', 'is_canonical')) {
                $table->boolean('is_canonical')->default(false)->after('evidence_fingerprint');
            }
            if (! Schema::hasColumn('evidence', 'eligibility_status')) {
                $table->string('eligibility_status', 64)->nullable()->after('is_canonical');
            }
            if (! Schema::hasColumn('evidence', 'collection_run_id')) {
                $table->unsignedBigInteger('collection_run_id')->nullable()->after('run_id');
            }
            if (! Schema::hasColumn('evidence', 'brand_goal_id')) {
                $table->unsignedBigInteger('brand_goal_id')->nullable();
            }
            if (! Schema::hasColumn('evidence', 'brand_offering_id')) {
                $table->unsignedBigInteger('brand_offering_id')->nullable();
            }
            if (! Schema::hasColumn('evidence', 'is_derived')) {
                $table->boolean('is_derived')->default(false);
            }
            if (! Schema::hasColumn('evidence', 'generated_by_ai')) {
                $table->boolean('generated_by_ai')->default(false);
            }
        });

        Schema::table('evidence', function (Blueprint $table): void {
            if (! $this->hasIndexNamed('evidence_canonical_identity_unique')) {
                $table->unique(['digital_asset_id', 'evidence_fingerprint'], 'evidence_canonical_identity_unique');
            }
            if (! $this->hasIndexNamed('evidence_definition_id_index')) {
                $table->index('definition_id');
            }
            if (! $this->hasIndexNamed('evidence_is_canonical_index')) {
                $table->index(['digital_asset_id', 'is_canonical']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evidence')) {
            return;
        }

        Schema::table('evidence', function (Blueprint $table): void {
            if ($this->hasIndexNamed('evidence_canonical_identity_unique')) {
                $table->dropUnique('evidence_canonical_identity_unique');
            }
            if ($this->hasIndexNamed('evidence_definition_id_index')) {
                $table->dropIndex('evidence_definition_id_index');
            }
            if ($this->hasIndexNamed('evidence_is_canonical_index')) {
                $table->dropIndex('evidence_is_canonical_index');
            }
        });

        Schema::table('evidence', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('evidence', 'definition_id') ? 'definition_id' : null,
                Schema::hasColumn('evidence', 'evidence_fingerprint') ? 'evidence_fingerprint' : null,
                Schema::hasColumn('evidence', 'is_canonical') ? 'is_canonical' : null,
                Schema::hasColumn('evidence', 'eligibility_status') ? 'eligibility_status' : null,
                Schema::hasColumn('evidence', 'collection_run_id') ? 'collection_run_id' : null,
                Schema::hasColumn('evidence', 'brand_goal_id') ? 'brand_goal_id' : null,
                Schema::hasColumn('evidence', 'brand_offering_id') ? 'brand_offering_id' : null,
                Schema::hasColumn('evidence', 'is_derived') ? 'is_derived' : null,
                Schema::hasColumn('evidence', 'generated_by_ai') ? 'generated_by_ai' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list(evidence)');
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                ['evidence', $indexName],
            );

            return $rows !== [];
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, 'evidence', $indexName],
        );

        return $rows !== [];
    }
};
