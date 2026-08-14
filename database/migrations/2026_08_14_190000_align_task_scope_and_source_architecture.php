<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 43 — Task scope kinds + bounded source architecture; relax DigitalAsset/Brand
 * nullability for typed Customer/Brand-level execution.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'scope_kind')) {
                $table->string('scope_kind', 32)->nullable()->after('digital_asset_id');
            }
            if (! Schema::hasColumn('tasks', 'source_kind')) {
                $table->string('source_kind', 32)->nullable()->after('client_request_id');
            }
            if (! Schema::hasColumn('tasks', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->unique();
            }
        });

        DB::table('tasks')->whereNull('scope_kind')->whereNotNull('digital_asset_id')->update([
            'scope_kind' => 'digital_asset',
        ]);
        DB::table('tasks')->whereNull('scope_kind')->whereNotNull('brand_id')->whereNull('digital_asset_id')->update([
            'scope_kind' => 'brand',
        ]);
        DB::table('tasks')->whereNull('scope_kind')->whereNull('brand_id')->update([
            'scope_kind' => 'customer',
        ]);

        DB::table('tasks')->whereNull('source_kind')->whereNotNull('recommendation_id')->update([
            'source_kind' => 'recommendation',
        ]);
        DB::table('tasks')->whereNull('source_kind')->whereNotNull('client_request_id')->update([
            'source_kind' => 'client_request',
        ]);
        DB::table('tasks')->whereNull('source_kind')->update([
            'source_kind' => 'direct',
        ]);

        $this->relaxForeignKeys();

        if (! $this->hasIndexNamed('tasks_scope_kind_index')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index('scope_kind');
            });
        }
        if (! $this->hasIndexNamed('tasks_source_kind_index')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index('source_kind');
            });
        }

        DB::table('tasks')->whereNull('scope_kind')->update(['scope_kind' => 'digital_asset']);
        DB::table('tasks')->whereNull('source_kind')->update(['source_kind' => 'direct']);

        $this->addPostgresChecks();
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        $this->dropPostgresChecks();

        foreach (['tasks_scope_kind_index', 'tasks_source_kind_index'] as $index) {
            if ($this->hasIndexNamed($index)) {
                if (Schema::getConnection()->getDriverName() === 'sqlite') {
                    DB::statement('DROP INDEX IF EXISTS "'.$index.'"');
                } else {
                    Schema::table('tasks', function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index);
                    });
                }
            }
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $columns = [];
            foreach (['scope_kind', 'source_kind', 'idempotency_key'] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                if (in_array('idempotency_key', $columns, true)) {
                    if (Schema::getConnection()->getDriverName() === 'sqlite') {
                        DB::statement('DROP INDEX IF EXISTS "tasks_idempotency_key_unique"');
                    } elseif ($this->hasIndexNamed('tasks_idempotency_key_unique')) {
                        $table->dropUnique('tasks_idempotency_key_unique');
                    }
                }
                $table->dropColumn($columns);
            }
        });
    }

    private function relaxForeignKeys(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->sqliteMakeNullable(['brand_id', 'digital_asset_id']);

            return;
        }

        // Without doctrine/dbal, use raw ALTER for PostgreSQL / MySQL.
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks ALTER COLUMN brand_id DROP NOT NULL');
            DB::statement('ALTER TABLE tasks ALTER COLUMN digital_asset_id DROP NOT NULL');

            return;
        }

        if ($driver === 'mysql') {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropForeign(['digital_asset_id']);
                $table->dropForeign(['brand_id']);
            });
            DB::statement('ALTER TABLE tasks MODIFY brand_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE tasks MODIFY digital_asset_id BIGINT UNSIGNED NULL');
            Schema::table('tasks', function (Blueprint $table): void {
                $table->foreign('digital_asset_id')->references('id')->on('digital_assets')->nullOnDelete();
                $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function sqliteMakeNullable(array $columns): void
    {
        $info = DB::select('PRAGMA table_info(tasks)');
        $needs = false;
        foreach ($info as $col) {
            $name = is_object($col) ? ($col->name ?? '') : ($col['name'] ?? '');
            $notnull = is_object($col) ? (int) ($col->notnull ?? 0) : (int) ($col['notnull'] ?? 0);
            if (in_array($name, $columns, true) && $notnull === 1) {
                $needs = true;
            }
        }
        if (! $needs) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=OFF');

        $suffix = substr(md5((string) microtime(true)), 0, 8);
        $old = 'tasks_p43_'.$suffix;
        Schema::rename('tasks', $old);

        $indexes = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name NOT LIKE 'sqlite_%'",
            [$old]
        );
        foreach ($indexes as $index) {
            $name = is_object($index) ? ($index->name ?? null) : ($index['name'] ?? null);
            if (is_string($name) && $name !== '') {
                DB::statement('DROP INDEX IF EXISTS "'.$name.'"');
            }
        }

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recommendation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('client_request_id')->nullable();
            $table->string('client_request_task_idempotency_key')->nullable()->unique();
            $table->string('source_kind', 32)->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_kind', 32)->nullable();
            $table->string('title');
            $table->text('action');
            $table->text('rationale')->nullable();
            $table->string('priority');
            $table->json('snapshot_json')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by_id')->nullable();
            $table->text('completion_note')->nullable();
            $table->timestamp('outcome_review_after_at')->nullable();
            $table->string('outcome_status')->nullable();
            $table->timestamp('outcome_checked_at')->nullable();
            $table->foreignId('outcome_run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->json('outcome_json')->nullable();
            $table->timestamps();

            $table->index('recommendation_id');
            $table->index('client_request_id');
            $table->index('customer_id');
            $table->index('brand_id');
            $table->index('digital_asset_id');
            $table->index('assignee_id');
            $table->index('status');
            $table->index('scope_kind');
            $table->index('source_kind');
            $table->index('outcome_status');
        });

        $oldColumns = collect(DB::select('PRAGMA table_info('.$old.')'))
            ->map(fn ($c) => is_object($c) ? $c->name : $c['name'])
            ->all();
        $newColumns = collect(DB::select('PRAGMA table_info(tasks)'))
            ->map(fn ($c) => is_object($c) ? $c->name : $c['name'])
            ->all();
        $copy = array_values(array_intersect($oldColumns, $newColumns));
        $list = implode(', ', array_map(fn ($c) => '"'.$c.'"', $copy));

        DB::statement("INSERT INTO tasks ({$list}) SELECT {$list} FROM {$old}");
        Schema::drop($old);

        DB::statement('PRAGMA foreign_keys=ON');
    }

    private function addPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_scope_shape_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_shape_check');

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_scope_shape_check CHECK (
            (scope_kind = 'customer' AND brand_id IS NULL AND digital_asset_id IS NULL)
            OR (scope_kind = 'brand' AND brand_id IS NOT NULL AND digital_asset_id IS NULL)
            OR (scope_kind = 'digital_asset' AND brand_id IS NOT NULL AND digital_asset_id IS NOT NULL)
        )");

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_source_shape_check CHECK (
            (source_kind = 'recommendation' AND recommendation_id IS NOT NULL AND client_request_id IS NULL)
            OR (source_kind = 'client_request' AND client_request_id IS NOT NULL AND recommendation_id IS NULL)
            OR (source_kind = 'direct' AND recommendation_id IS NULL AND client_request_id IS NULL)
        )");
    }

    private function dropPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_scope_shape_check');
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_source_shape_check');
    }

    private function hasIndexNamed(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            foreach ($connection->select('PRAGMA index_list(tasks)') as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            return $connection->select(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
                ['tasks', $indexName],
            ) !== [];
        }

        return false;
    }
};
