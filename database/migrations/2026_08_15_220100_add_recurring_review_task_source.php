<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 46 — bounded Task source: recurring_review_check + run_item FK.
 * Extends Prompt 43 source XOR; no unrestricted morphTo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'recurring_review_run_item_id')) {
                $table->foreignId('recurring_review_run_item_id')
                    ->nullable()
                    ->after('client_request_id')
                    ->constrained('recurring_review_run_items')
                    ->nullOnDelete();
            }
        });

        if (! $this->hasIndexNamed('tasks_recurring_review_run_item_id_index')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index('recurring_review_run_item_id', 'tasks_recurring_review_run_item_id_index');
            });
        }

        $this->replacePostgresSourceCheck();
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_shape_check');
            DB::statement("
                ALTER TABLE tasks
                ADD CONSTRAINT tasks_source_shape_check CHECK (
                    (source_kind = 'recommendation' AND recommendation_id IS NOT NULL AND client_request_id IS NULL)
                    OR (source_kind = 'client_request' AND client_request_id IS NOT NULL AND recommendation_id IS NULL)
                    OR (source_kind = 'direct' AND recommendation_id IS NULL AND client_request_id IS NULL)
                    OR (source_kind IS NULL)
                )
            ");
        }

        if (! Schema::hasColumn('tasks', 'recurring_review_run_item_id')) {
            return;
        }

        if ($this->hasIndexNamed('tasks_recurring_review_run_item_id_index')) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS "tasks_recurring_review_run_item_id_index"');
            } else {
                Schema::table('tasks', function (Blueprint $table): void {
                    $table->dropIndex('tasks_recurring_review_run_item_id_index');
                });
            }
        }

        Schema::table('tasks', function (Blueprint $table): void {
            try {
                $table->dropForeign(['recurring_review_run_item_id']);
            } catch (Throwable) {
                // SQLite / drivers without an explicit FK name.
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'recurring_review_run_item_id')) {
                $table->dropColumn('recurring_review_run_item_id');
            }
        });
    }

    private function replacePostgresSourceCheck(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_shape_check');
        DB::statement("
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_source_shape_check CHECK (
                (source_kind = 'recommendation'
                    AND recommendation_id IS NOT NULL
                    AND client_request_id IS NULL
                    AND recurring_review_run_item_id IS NULL)
                OR (source_kind = 'client_request'
                    AND client_request_id IS NOT NULL
                    AND recommendation_id IS NULL
                    AND recurring_review_run_item_id IS NULL)
                OR (source_kind = 'direct'
                    AND recommendation_id IS NULL
                    AND client_request_id IS NULL
                    AND recurring_review_run_item_id IS NULL)
                OR (source_kind = 'recurring_review_check'
                    AND recurring_review_run_item_id IS NOT NULL
                    AND recommendation_id IS NULL
                    AND client_request_id IS NULL)
                OR (source_kind IS NULL)
            )
        ");
    }

    private function hasIndexNamed(string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);

            return $rows !== [];
        }

        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]);

            return $rows !== [];
        }

        return Schema::hasIndex('tasks', $name);
    }
};
