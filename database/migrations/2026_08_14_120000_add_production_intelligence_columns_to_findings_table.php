<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive production-intelligence columns on the canonical findings table.
 * Does not create FindingV2 / ProductionFinding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('findings')) {
            return;
        }

        Schema::table('findings', function (Blueprint $table): void {
            if (! Schema::hasColumn('findings', 'origin')) {
                $table->string('origin', 32)->default('legacy_unverified')->after('source_module');
            }
            if (! Schema::hasColumn('findings', 'rule_id')) {
                $table->string('rule_id', 128)->nullable()->after('origin');
            }
            if (! Schema::hasColumn('findings', 'rule_version')) {
                $table->unsignedInteger('rule_version')->nullable()->after('rule_id');
            }
            if (! Schema::hasColumn('findings', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('digital_asset_id');
            }
            if (! Schema::hasColumn('findings', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('findings', 'subject_kind')) {
                $table->string('subject_kind', 64)->nullable()->after('brand_id');
            }
            if (! Schema::hasColumn('findings', 'subject_id')) {
                $table->string('subject_id', 191)->nullable()->after('subject_kind');
            }
            if (! Schema::hasColumn('findings', 'condition_state')) {
                $table->string('condition_state', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('findings', 'brand_goal_id')) {
                $table->unsignedBigInteger('brand_goal_id')->nullable();
            }
            if (! Schema::hasColumn('findings', 'brand_offering_id')) {
                $table->unsignedBigInteger('brand_offering_id')->nullable();
            }
            if (! Schema::hasColumn('findings', 'latest_evaluation_id')) {
                $table->unsignedBigInteger('latest_evaluation_id')->nullable();
            }
            if (! Schema::hasColumn('findings', 'semantic_fingerprint')) {
                $table->char('semantic_fingerprint', 64)->nullable();
            }
        });

        Schema::table('findings', function (Blueprint $table): void {
            if (! $this->hasIndexNamed('findings_customer_id_index')) {
                $table->index('customer_id', 'findings_customer_id_index');
            }
            if (! $this->hasIndexNamed('findings_brand_id_index')) {
                $table->index('brand_id', 'findings_brand_id_index');
            }
            if (! $this->hasIndexNamed('findings_rule_id_index')) {
                $table->index('rule_id', 'findings_rule_id_index');
            }
            if (! $this->hasIndexNamed('findings_status_index')) {
                $table->index('status', 'findings_status_index');
            }
            if (! $this->hasIndexNamed('findings_origin_index')) {
                $table->index('origin', 'findings_origin_index');
            }
            if (! $this->hasIndexNamed('findings_subject_index')) {
                $table->index(['subject_kind', 'subject_id'], 'findings_subject_index');
            }
            if (! $this->hasIndexNamed('findings_semantic_fingerprint_index')) {
                $table->index('semantic_fingerprint', 'findings_semantic_fingerprint_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('findings')) {
            return;
        }

        Schema::table('findings', function (Blueprint $table): void {
            foreach ([
                'findings_customer_id_index',
                'findings_brand_id_index',
                'findings_rule_id_index',
                'findings_status_index',
                'findings_origin_index',
                'findings_subject_index',
                'findings_semantic_fingerprint_index',
            ] as $indexName) {
                if ($this->hasIndexNamed($indexName)) {
                    $table->dropIndex($indexName);
                }
            }
        });

        Schema::table('findings', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('findings', 'origin') ? 'origin' : null,
                Schema::hasColumn('findings', 'rule_id') ? 'rule_id' : null,
                Schema::hasColumn('findings', 'rule_version') ? 'rule_version' : null,
                Schema::hasColumn('findings', 'customer_id') ? 'customer_id' : null,
                Schema::hasColumn('findings', 'brand_id') ? 'brand_id' : null,
                Schema::hasColumn('findings', 'subject_kind') ? 'subject_kind' : null,
                Schema::hasColumn('findings', 'subject_id') ? 'subject_id' : null,
                Schema::hasColumn('findings', 'condition_state') ? 'condition_state' : null,
                Schema::hasColumn('findings', 'brand_goal_id') ? 'brand_goal_id' : null,
                Schema::hasColumn('findings', 'brand_offering_id') ? 'brand_offering_id' : null,
                Schema::hasColumn('findings', 'latest_evaluation_id') ? 'latest_evaluation_id' : null,
                Schema::hasColumn('findings', 'semantic_fingerprint') ? 'semantic_fingerprint' : null,
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
            $rows = $connection->select('PRAGMA index_list(findings)');
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
                ['findings', $indexName],
            );

            return $rows !== [];
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, 'findings', $indexName],
        );

        return $rows !== [];
    }
};
