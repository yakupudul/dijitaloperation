<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const string UNIQUE_NAME = 'collection_dataset_runs_scope_unique';

    public function up(): void
    {
        if (! Schema::hasTable('collection_dataset_runs')) {
            return;
        }

        if (! Schema::hasColumn('collection_dataset_runs', 'execution_variant')) {
            Schema::table('collection_dataset_runs', function (Blueprint $table): void {
                $table->string('execution_variant', 64)->default('')->after('request_family_id');
            });
        }

        // Preserve any Search Console runs that may already have search_type in
        // metadata. Other providers intentionally use the empty default variant.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE collection_dataset_runs
                SET execution_variant = lower(coalesce(metadata->>'execution_variant', metadata->>'search_type', ''))
                WHERE execution_variant = ''
                  AND metadata IS NOT NULL
            SQL);

            DB::statement('ALTER TABLE collection_dataset_runs DROP CONSTRAINT IF EXISTS '.self::UNIQUE_NAME);
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_NAME);
            DB::statement(<<<'SQL'
                ALTER TABLE collection_dataset_runs
                ADD CONSTRAINT collection_dataset_runs_scope_unique
                UNIQUE (
                    collection_run_id,
                    collection_resource_run_id,
                    dataset_contract_id,
                    request_family_id,
                    execution_variant
                )
            SQL);

            return;
        }

        Schema::table('collection_dataset_runs', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_NAME);
            $table->unique([
                'collection_run_id',
                'collection_resource_run_id',
                'dataset_contract_id',
                'request_family_id',
                'execution_variant',
            ], self::UNIQUE_NAME);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('collection_dataset_runs') || ! Schema::hasColumn('collection_dataset_runs', 'execution_variant')) {
            return;
        }

        $hasVariants = DB::table('collection_dataset_runs')
            ->select([
                'collection_run_id',
                'collection_resource_run_id',
                'dataset_contract_id',
                'request_family_id',
            ])
            ->groupBy([
                'collection_run_id',
                'collection_resource_run_id',
                'dataset_contract_id',
                'request_family_id',
            ])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasVariants) {
            throw new RuntimeException('Cannot safely roll back execution_variant while multiple provider variants exist.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE collection_dataset_runs DROP CONSTRAINT IF EXISTS '.self::UNIQUE_NAME);
            DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_NAME);
            DB::statement(<<<'SQL'
                ALTER TABLE collection_dataset_runs
                ADD CONSTRAINT collection_dataset_runs_scope_unique
                UNIQUE (
                    collection_run_id,
                    collection_resource_run_id,
                    dataset_contract_id,
                    request_family_id
                )
            SQL);
        } else {
            Schema::table('collection_dataset_runs', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_NAME);
                $table->unique([
                    'collection_run_id',
                    'collection_resource_run_id',
                    'dataset_contract_id',
                    'request_family_id',
                ], self::UNIQUE_NAME);
            });
        }

        Schema::table('collection_dataset_runs', function (Blueprint $table): void {
            $table->dropColumn('execution_variant');
        });
    }
};
