<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 45 — Canonical versioned Playbooks (SOP / knowledge), distinct from Task/QA/AI Skill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('playbooks')) {
            Schema::create('playbooks', function (Blueprint $table): void {
                $table->id();
                $table->string('stable_key', 64)->nullable()->unique();
                $table->string('status', 32);
                $table->unsignedBigInteger('current_revision_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasTable('playbook_revisions')) {
            Schema::create('playbook_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_id')->constrained('playbooks')->cascadeOnDelete();
                $table->unsignedInteger('revision_number');
                $table->string('title');
                $table->text('summary')->nullable();
                $table->json('knowledge')->nullable();
                $table->string('cadence', 32)->nullable();
                $table->string('service_applicability_mode', 32);
                $table->string('asset_applicability_mode', 32);
                $table->string('execution_scope_mode', 32);
                $table->string('content_fingerprint', 64);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->unique(['playbook_id', 'revision_number']);
                $table->index(['playbook_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('playbook_instructions')) {
            Schema::create('playbook_instructions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('title')->nullable();
                $table->text('body');
                $table->timestamps();

                $table->unique(['playbook_revision_id', 'position']);
            });
        }

        if (! Schema::hasTable('playbook_references')) {
            Schema::create('playbook_references', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->cascadeOnDelete();
                $table->string('kind', 32);
                $table->string('label');
                $table->string('url', 2048)->nullable();
                $table->string('route_name', 191)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('position');
                $table->timestamps();

                $table->index(['playbook_revision_id', 'position']);
            });
        }

        if (! Schema::hasTable('playbook_revision_services')) {
            Schema::create('playbook_revision_services', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->cascadeOnDelete();
                $table->foreignId('service_definition_id')->constrained('service_definitions')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['playbook_revision_id', 'service_definition_id'], 'playbook_rev_service_unique');
            });
        }

        if (! Schema::hasTable('playbook_revision_asset_types')) {
            Schema::create('playbook_revision_asset_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->cascadeOnDelete();
                $table->string('asset_type', 64);
                $table->timestamps();

                $table->unique(['playbook_revision_id', 'asset_type'], 'playbook_rev_asset_unique');
                $table->index('asset_type');
            });
        }

        if (! Schema::hasTable('playbook_revision_execution_scopes')) {
            Schema::create('playbook_revision_execution_scopes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->cascadeOnDelete();
                $table->string('scope_kind', 32);
                $table->timestamps();

                $table->unique(['playbook_revision_id', 'scope_kind'], 'playbook_rev_scope_unique');
            });
        }

        if (Schema::hasTable('playbooks') && Schema::hasTable('playbook_revisions')) {
            Schema::table('playbooks', function (Blueprint $table): void {
                if (! $this->hasForeignKey('playbooks', 'playbooks_current_revision_id_foreign')) {
                    $table->foreign('current_revision_id')
                        ->references('id')
                        ->on('playbook_revisions')
                        ->nullOnDelete();
                }
            });
        }

        $this->addPostgresChecks();
    }

    public function down(): void
    {
        $this->dropPostgresChecks();

        if (Schema::hasTable('playbooks')) {
            Schema::table('playbooks', function (Blueprint $table): void {
                if ($this->hasForeignKey('playbooks', 'playbooks_current_revision_id_foreign')) {
                    $table->dropForeign(['current_revision_id']);
                }
            });
        }

        Schema::dropIfExists('playbook_revision_execution_scopes');
        Schema::dropIfExists('playbook_revision_asset_types');
        Schema::dropIfExists('playbook_revision_services');
        Schema::dropIfExists('playbook_references');
        Schema::dropIfExists('playbook_instructions');
        Schema::dropIfExists('playbook_revisions');
        Schema::dropIfExists('playbooks');
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            return false;
        }

        $schema = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$schema, $table, $name, 'FOREIGN KEY']
        );

        return $rows !== [];
    }

    private function addPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE playbooks DROP CONSTRAINT IF EXISTS playbooks_status_check');
        DB::statement("ALTER TABLE playbooks ADD CONSTRAINT playbooks_status_check CHECK (status IN ('active','archived'))");

        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_service_mode_check');
        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_asset_mode_check');
        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_exec_mode_check');
        DB::statement("ALTER TABLE playbook_revisions ADD CONSTRAINT playbook_revisions_service_mode_check CHECK (service_applicability_mode IN ('any','explicit'))");
        DB::statement("ALTER TABLE playbook_revisions ADD CONSTRAINT playbook_revisions_asset_mode_check CHECK (asset_applicability_mode IN ('any','explicit'))");
        DB::statement("ALTER TABLE playbook_revisions ADD CONSTRAINT playbook_revisions_exec_mode_check CHECK (execution_scope_mode IN ('any','explicit'))");

        DB::statement('ALTER TABLE playbook_references DROP CONSTRAINT IF EXISTS playbook_references_kind_check');
        DB::statement("ALTER TABLE playbook_references ADD CONSTRAINT playbook_references_kind_check CHECK (kind IN ('external_url','internal_route'))");
    }

    private function dropPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE playbooks DROP CONSTRAINT IF EXISTS playbooks_status_check');
        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_service_mode_check');
        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_asset_mode_check');
        DB::statement('ALTER TABLE playbook_revisions DROP CONSTRAINT IF EXISTS playbook_revisions_exec_mode_check');
        DB::statement('ALTER TABLE playbook_references DROP CONSTRAINT IF EXISTS playbook_references_kind_check');
    }
};
