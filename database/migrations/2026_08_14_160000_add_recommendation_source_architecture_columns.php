<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Recommendation source architecture on the canonical recommendations table.
 *
 * A Recommendation is sourced by exactly one of Finding or Opportunity (XOR). Deleting the
 * source must never delete the Recommendation, so the finding_id foreign key moves from
 * cascade to restrict and opportunity_id is added with the same rule. No RecommendationV2.
 */
return new class extends Migration
{
    private const string XOR_CHECK = 'recommendations_source_xor_check';

    public function up(): void
    {
        if (! Schema::hasTable('recommendations')) {
            return;
        }

        Schema::table('recommendations', function (Blueprint $table): void {
            if (! Schema::hasColumn('recommendations', 'source_kind')) {
                $table->string('source_kind', 32)->nullable()->after('id');
            }
            if (! Schema::hasColumn('recommendations', 'opportunity_id')) {
                $table->unsignedBigInteger('opportunity_id')->nullable()->after('finding_id');
            }
            if (! Schema::hasColumn('recommendations', 'origin')) {
                $table->string('origin', 32)->nullable()->default(null)->after('source_module');
            }
            if (! Schema::hasColumn('recommendations', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('origin');
            }
        });

        Schema::table('recommendations', function (Blueprint $table): void {
            if (! $this->hasIndexNamed('recommendations_source_kind_index')) {
                $table->index('source_kind', 'recommendations_source_kind_index');
            }
            if (! $this->hasIndexNamed('recommendations_opportunity_id_index')) {
                $table->index('opportunity_id', 'recommendations_opportunity_id_index');
            }
            if (! $this->hasIndexNamed('recommendations_source_finding_index')) {
                $table->index(['source_kind', 'finding_id'], 'recommendations_source_finding_index');
            }
            if (! $this->hasIndexNamed('recommendations_source_opportunity_index')) {
                $table->index(['source_kind', 'opportunity_id'], 'recommendations_source_opportunity_index');
            }
            if (! $this->hasIndexNamed('recommendations_idempotency_key_unique')) {
                $table->unique('idempotency_key', 'recommendations_idempotency_key_unique');
            }
        });

        DB::table('recommendations')
            ->whereNotNull('finding_id')
            ->update([
                'source_kind' => 'finding',
                'origin' => DB::raw("COALESCE(origin, 'legacy')"),
            ]);

        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropForeign(['finding_id']);
            $table->unsignedBigInteger('finding_id')->nullable()->change();
            $table->foreign('finding_id', 'recommendations_finding_id_foreign')
                ->references('id')
                ->on('findings')
                ->restrictOnDelete();
            $table->foreign('opportunity_id', 'recommendations_opportunity_id_foreign')
                ->references('id')
                ->on('opportunities')
                ->restrictOnDelete();
        });

        $this->addXorCheckConstraint();
    }

    public function down(): void
    {
        if (! Schema::hasTable('recommendations')) {
            return;
        }

        $this->dropXorCheckConstraint();

        DB::table('recommendations')->whereNull('finding_id')->delete();

        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropForeign(['finding_id']);
            $table->dropForeign(['opportunity_id']);
            $table->unsignedBigInteger('finding_id')->nullable(false)->change();
            $table->foreign('finding_id', 'recommendations_finding_id_foreign')
                ->references('id')
                ->on('findings')
                ->cascadeOnDelete();
        });

        Schema::table('recommendations', function (Blueprint $table): void {
            foreach ([
                'recommendations_source_kind_index',
                'recommendations_opportunity_id_index',
                'recommendations_source_finding_index',
                'recommendations_source_opportunity_index',
            ] as $indexName) {
                if ($this->hasIndexNamed($indexName)) {
                    $table->dropIndex($indexName);
                }
            }

            if ($this->hasIndexNamed('recommendations_idempotency_key_unique')) {
                $table->dropUnique('recommendations_idempotency_key_unique');
            }
        });

        Schema::table('recommendations', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('recommendations', 'source_kind') ? 'source_kind' : null,
                Schema::hasColumn('recommendations', 'opportunity_id') ? 'opportunity_id' : null,
                Schema::hasColumn('recommendations', 'origin') ? 'origin' : null,
                Schema::hasColumn('recommendations', 'idempotency_key') ? 'idempotency_key' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * Database-level XOR guarantee where the driver supports adding a table check constraint.
     * SQLite cannot add a check constraint to an existing table; the application guard covers it.
     */
    private function addXorCheckConstraint(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE recommendations ADD CONSTRAINT %s CHECK ('
            ." (source_kind = 'finding' AND finding_id IS NOT NULL AND opportunity_id IS NULL)"
            ." OR (source_kind = 'opportunity' AND opportunity_id IS NOT NULL AND finding_id IS NULL)"
            .')',
            self::XOR_CHECK,
        ));
    }

    private function dropXorCheckConstraint(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS '.self::XOR_CHECK);
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list(recommendations)');
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
                ['recommendations', $indexName],
            );

            return $rows !== [];
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, 'recommendations', $indexName],
        );

        return $rows !== [];
    }
};
