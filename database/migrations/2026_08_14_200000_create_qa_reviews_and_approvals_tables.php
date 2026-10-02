<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 44 — Canonical QA Reviews and Approvals (distinct from Task status).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qa_reviews')) {
            Schema::create('qa_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('status', 32);
                $table->string('result', 32)->nullable();
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->string('subject_fingerprint', 64);
                $table->string('subject_title_snapshot');
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->index(['task_id', 'status']);
                $table->index(['task_id', 'completed_at']);
                $table->index('reviewer_id');
                $table->index('result');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_kind', 32);
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('kind', 32);
                $table->string('status', 32);
                $table->string('decision', 32)->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decided_by_actor_kind', 32)->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by_customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->text('reason')->nullable();
                $table->boolean('waiting_on_client')->default(false);
                $table->string('subject_fingerprint', 64);
                $table->string('subject_title_snapshot');
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->index(['task_id', 'status']);
                $table->index(['subject_kind', 'status']);
                $table->index('kind');
                $table->index('decision');
                $table->index('requested_at');
                $table->index('decided_at');
                $table->index('decided_by_user_id');
            });
        }

        $this->addPostgresChecks();
    }

    public function down(): void
    {
        $this->dropPostgresChecks();
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('qa_reviews');
    }

    private function addPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE qa_reviews DROP CONSTRAINT IF EXISTS qa_reviews_status_check');
        DB::statement('ALTER TABLE qa_reviews DROP CONSTRAINT IF EXISTS qa_reviews_result_shape_check');
        DB::statement("ALTER TABLE qa_reviews ADD CONSTRAINT qa_reviews_status_check CHECK (status IN ('pending','in_review','completed','cancelled'))");
        DB::statement("ALTER TABLE qa_reviews ADD CONSTRAINT qa_reviews_result_shape_check CHECK (
            (status IN ('pending','in_review','cancelled') AND result IS NULL)
            OR (status = 'completed' AND result IN ('passed','failed','needs_changes'))
        )");

        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_status_check');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_decision_shape_check');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_subject_kind_check');
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_status_check CHECK (status IN ('pending','decided','cancelled'))");
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_subject_kind_check CHECK (subject_kind = 'task')");
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_decision_shape_check CHECK (
            (status IN ('pending','cancelled') AND decision IS NULL)
            OR (status = 'decided' AND decision IN ('approved','rejected','changes_requested'))
        )");
    }

    private function dropPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE qa_reviews DROP CONSTRAINT IF EXISTS qa_reviews_status_check');
        DB::statement('ALTER TABLE qa_reviews DROP CONSTRAINT IF EXISTS qa_reviews_result_shape_check');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_status_check');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_decision_shape_check');
        DB::statement('ALTER TABLE approvals DROP CONSTRAINT IF EXISTS approvals_subject_kind_check');
    }
};
