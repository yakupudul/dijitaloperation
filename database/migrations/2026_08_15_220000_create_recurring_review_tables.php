<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 46 — Recurring Review production persistence.
 * Distinct from qa_reviews. No background scheduler registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurring_review_schedules')) {
            Schema::create('recurring_review_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('scope_kind', 32);
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('playbook_id')->constrained('playbooks')->restrictOnDelete();
                $table->string('cadence', 32);
                $table->string('timezone', 64);
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->string('status', 32);
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('default_reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('next_due_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->index(['customer_id', 'status'], 'rr_schedules_customer_status_index');
                $table->index(['brand_id', 'status'], 'rr_schedules_brand_status_index');
                $table->index(['digital_asset_id', 'status'], 'rr_schedules_asset_status_index');
                $table->index(['playbook_id'], 'rr_schedules_playbook_index');
                $table->index(['status', 'next_due_at'], 'rr_schedules_status_next_due_index');
            });
        }

        if (! Schema::hasTable('recurring_review_check_definitions')) {
            Schema::create('recurring_review_check_definitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('recurring_review_schedules')->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('title');
                $table->text('description')->nullable();
                $table->boolean('is_required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->string('finding_rule_stable_id', 128)->nullable();
                $table->string('opportunity_rule_stable_id', 128)->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'position'], 'rr_check_defs_schedule_position_unique');
                $table->index(['schedule_id', 'is_active'], 'rr_check_defs_schedule_active_index');
            });
        }

        if (! Schema::hasTable('recurring_review_runs')) {
            Schema::create('recurring_review_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('schedule_id')->constrained('recurring_review_schedules')->cascadeOnDelete();
                $table->string('occurrence_key', 191);
                $table->string('occurrence_kind', 32);
                $table->timestamp('due_at');
                $table->foreignId('playbook_id')->constrained('playbooks')->restrictOnDelete();
                $table->foreignId('playbook_revision_id')->constrained('playbook_revisions')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('scope_kind', 32);
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
                $table->json('service_scope_context_json')->nullable();
                $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('summary_json')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'occurrence_key'], 'rr_runs_schedule_occurrence_unique');
                $table->index(['schedule_id', 'due_at'], 'rr_runs_schedule_due_index');
                $table->index(['status', 'due_at'], 'rr_runs_status_due_index');
                $table->index(['customer_id', 'status'], 'rr_runs_customer_status_index');
                $table->index(['reviewer_user_id'], 'rr_runs_reviewer_index');
                $table->index(['completed_at'], 'rr_runs_completed_index');
            });
        }

        if (! Schema::hasTable('recurring_review_run_items')) {
            Schema::create('recurring_review_run_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('recurring_review_runs')->cascadeOnDelete();
                $table->foreignId('check_definition_id')->constrained('recurring_review_check_definitions')->restrictOnDelete();
                $table->unsignedInteger('position');
                $table->string('title_snapshot');
                $table->text('description_snapshot')->nullable();
                $table->boolean('is_required_snapshot')->default(true);
                $table->string('finding_rule_stable_id_snapshot', 128)->nullable();
                $table->string('opportunity_rule_stable_id_snapshot', 128)->nullable();
                $table->string('state', 32);
                $table->string('outcome_kind', 32)->nullable();
                $table->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
                $table->foreignId('finding_id')->nullable()->constrained('findings')->nullOnDelete();
                $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->text('note')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('outcome_idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->unique(['run_id', 'check_definition_id'], 'rr_run_items_run_check_unique');
                $table->index(['run_id', 'position'], 'rr_run_items_run_position_index');
                $table->index(['check_definition_id'], 'rr_run_items_check_index');
                $table->index(['outcome_kind'], 'rr_run_items_outcome_index');
                $table->index(['finding_id'], 'rr_run_items_finding_index');
                $table->index(['opportunity_id'], 'rr_run_items_opportunity_index');
                $table->index(['task_id'], 'rr_run_items_task_index');
            });
        }

        if (! Schema::hasTable('recurring_review_run_item_task_links')) {
            Schema::create('recurring_review_run_item_task_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_item_id')->constrained('recurring_review_run_items')->cascadeOnDelete();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->string('relation_kind', 32);
                $table->timestamps();

                $table->unique(['run_item_id', 'task_id'], 'rr_run_item_task_links_unique');
                $table->index(['task_id'], 'rr_run_item_task_links_task_index');
            });
        }

        $this->addPostgresChecks();
    }

    public function down(): void
    {
        $this->dropPostgresChecks();
        Schema::dropIfExists('recurring_review_run_item_task_links');
        Schema::dropIfExists('recurring_review_run_items');
        Schema::dropIfExists('recurring_review_runs');
        Schema::dropIfExists('recurring_review_check_definitions');
        Schema::dropIfExists('recurring_review_schedules');
    }

    private function addPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            ALTER TABLE recurring_review_schedules
            DROP CONSTRAINT IF EXISTS rr_schedules_scope_shape_check,
            DROP CONSTRAINT IF EXISTS rr_schedules_status_check,
            DROP CONSTRAINT IF EXISTS rr_schedules_cadence_check
        ');
        DB::statement("
            ALTER TABLE recurring_review_schedules
            ADD CONSTRAINT rr_schedules_scope_shape_check CHECK (
                (scope_kind = 'customer' AND brand_id IS NULL AND digital_asset_id IS NULL)
                OR (scope_kind = 'brand' AND brand_id IS NOT NULL AND digital_asset_id IS NULL)
                OR (scope_kind = 'digital_asset' AND brand_id IS NOT NULL AND digital_asset_id IS NOT NULL)
            )
        ");
        DB::statement("
            ALTER TABLE recurring_review_schedules
            ADD CONSTRAINT rr_schedules_status_check CHECK (status IN ('active', 'paused', 'ended'))
        ");
        DB::statement("
            ALTER TABLE recurring_review_schedules
            ADD CONSTRAINT rr_schedules_cadence_check CHECK (cadence IN ('weekly', 'monthly', 'quarterly'))
        ");

        DB::statement('
            ALTER TABLE recurring_review_runs
            DROP CONSTRAINT IF EXISTS rr_runs_status_check,
            DROP CONSTRAINT IF EXISTS rr_runs_occurrence_kind_check
        ');
        DB::statement("
            ALTER TABLE recurring_review_runs
            ADD CONSTRAINT rr_runs_status_check CHECK (status IN ('scheduled', 'in_progress', 'completed', 'skipped', 'cancelled'))
        ");
        DB::statement("
            ALTER TABLE recurring_review_runs
            ADD CONSTRAINT rr_runs_occurrence_kind_check CHECK (occurrence_kind IN ('scheduled', 'manual'))
        ");

        DB::statement('
            ALTER TABLE recurring_review_run_items
            DROP CONSTRAINT IF EXISTS rr_run_items_state_check,
            DROP CONSTRAINT IF EXISTS rr_run_items_outcome_check,
            DROP CONSTRAINT IF EXISTS rr_run_items_outcome_fk_check
        ');
        DB::statement("
            ALTER TABLE recurring_review_run_items
            ADD CONSTRAINT rr_run_items_state_check CHECK (state IN ('pending', 'completed', 'skipped', 'not_applicable'))
        ");
        DB::statement("
            ALTER TABLE recurring_review_run_items
            ADD CONSTRAINT rr_run_items_outcome_check CHECK (
                outcome_kind IS NULL OR outcome_kind IN ('no_issue', 'finding', 'opportunity', 'task')
            )
        ");
        DB::statement("
            ALTER TABLE recurring_review_run_items
            ADD CONSTRAINT rr_run_items_outcome_fk_check CHECK (
                (outcome_kind IS NULL AND finding_id IS NULL AND opportunity_id IS NULL AND task_id IS NULL)
                OR (outcome_kind = 'no_issue' AND finding_id IS NULL AND opportunity_id IS NULL AND task_id IS NULL)
                OR (outcome_kind = 'finding' AND finding_id IS NOT NULL AND opportunity_id IS NULL AND task_id IS NULL)
                OR (outcome_kind = 'opportunity' AND finding_id IS NULL AND opportunity_id IS NOT NULL AND task_id IS NULL)
                OR (outcome_kind = 'task' AND finding_id IS NULL AND opportunity_id IS NULL AND task_id IS NOT NULL)
            )
        ");

        DB::statement('
            ALTER TABLE recurring_review_run_item_task_links
            DROP CONSTRAINT IF EXISTS rr_run_item_task_links_kind_check
        ');
        DB::statement("
            ALTER TABLE recurring_review_run_item_task_links
            ADD CONSTRAINT rr_run_item_task_links_kind_check CHECK (relation_kind IN ('created', 'existing_linked'))
        ");
    }

    private function dropPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE recurring_review_schedules DROP CONSTRAINT IF EXISTS rr_schedules_scope_shape_check');
        DB::statement('ALTER TABLE recurring_review_schedules DROP CONSTRAINT IF EXISTS rr_schedules_status_check');
        DB::statement('ALTER TABLE recurring_review_schedules DROP CONSTRAINT IF EXISTS rr_schedules_cadence_check');
        DB::statement('ALTER TABLE recurring_review_runs DROP CONSTRAINT IF EXISTS rr_runs_status_check');
        DB::statement('ALTER TABLE recurring_review_runs DROP CONSTRAINT IF EXISTS rr_runs_occurrence_kind_check');
        DB::statement('ALTER TABLE recurring_review_run_items DROP CONSTRAINT IF EXISTS rr_run_items_state_check');
        DB::statement('ALTER TABLE recurring_review_run_items DROP CONSTRAINT IF EXISTS rr_run_items_outcome_check');
        DB::statement('ALTER TABLE recurring_review_run_items DROP CONSTRAINT IF EXISTS rr_run_items_outcome_fk_check');
        DB::statement('ALTER TABLE recurring_review_run_item_task_links DROP CONSTRAINT IF EXISTS rr_run_item_task_links_kind_check');
    }
};
