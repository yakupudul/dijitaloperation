<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 42 — canonical Client Request persistence + Request→Task bridge.
 *
 * Task.client_request_id uses an application-enforced relation. A database FK is
 * added on PostgreSQL only — SQLite cannot reliably drop FK-backed columns during
 * migrate:rollback (required by Prompt 41 migration backfill tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_requests')) {
            Schema::create('client_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('brand_id')->constrained()->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('service_definition_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_contact_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 32);
                $table->string('channel', 32)->nullable();
                $table->string('priority', 32)->nullable();
                $table->string('effort', 64)->nullable();
                $table->string('due_label', 64)->nullable();
                $table->date('due_date')->nullable();

                $table->string('intake_scope_state', 64)->nullable();
                $table->json('intake_scope_snapshot')->nullable();
                $table->timestamp('intake_scope_assessed_at')->nullable();

                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index(['digital_asset_id']);
                $table->index(['owner_user_id']);
                $table->index(['service_definition_id']);
                $table->index(['created_at']);
                $table->index(['priority']);
            });
        }

        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'client_request_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->unsignedBigInteger('client_request_id')->nullable()->after('recommendation_id');
                $table->string('client_request_task_idempotency_key')->nullable();
                $table->index('client_request_id');
                $table->unique('client_request_task_idempotency_key');
            });

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                Schema::table('tasks', function (Blueprint $table) {
                    $table->foreign('client_request_id')
                        ->references('id')
                        ->on('client_requests')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'client_request_id')) {
            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                Schema::table('tasks', function (Blueprint $table) {
                    $table->dropForeign(['client_request_id']);
                });
            }

            $this->dropIndexIfExists('tasks', 'tasks_client_request_task_idempotency_key_unique');
            $this->dropIndexIfExists('tasks', 'tasks_client_request_id_index');

            Schema::table('tasks', function (Blueprint $table) {
                $columns = ['client_request_id'];
                if (Schema::hasColumn('tasks', 'client_request_task_idempotency_key')) {
                    $columns[] = 'client_request_task_idempotency_key';
                }
                $table->dropColumn($columns);
            });
        }

        Schema::dropIfExists('client_requests');
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->hasIndexNamed($table, $indexName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS "'.$indexName.'"');

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            try {
                $blueprint->dropUnique($indexName);
            } catch (Throwable) {
                $blueprint->dropIndex($indexName);
            }
        });
    }

    private function hasIndexNamed(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list('.$table.')');
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
                [$table, $indexName],
            );

            return $rows !== [];
        }

        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName],
        );

        return $rows !== [];
    }
};
