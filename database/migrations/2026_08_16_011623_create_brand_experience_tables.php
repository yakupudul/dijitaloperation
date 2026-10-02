<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 52 — Canonical Brand Experience Records (Brand Memory content).
 *
 * History-safe: stable experience + immutable revisions.
 * No generic memories table. No BusinessOutcome. No Sector aggregation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_experiences')) {
            Schema::create('brand_experiences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->string('status', 32);
                $table->unsignedBigInteger('current_revision_id')->nullable();
                $table->string('origin', 64);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable()->unique();
                $table->foreignId('supersedes_experience_id')->nullable()->constrained('brand_experiences')->nullOnDelete();
                $table->foreignId('superseded_by_experience_id')->nullable()->constrained('brand_experiences')->nullOnDelete();
                $table->timestamps();

                $table->index(['customer_id', 'brand_id', 'status']);
                $table->index(['brand_id', 'status', 'created_at']);
            });
        }

        if (! Schema::hasTable('brand_experience_revisions')) {
            Schema::create('brand_experience_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_experience_id')->constrained('brand_experiences')->cascadeOnDelete();
                $table->unsignedInteger('revision_number');
                $table->string('context_schema_version', 32);
                $table->json('context_snapshot');
                $table->string('market_code', 8)->nullable();
                $table->string('market_label')->nullable();
                $table->string('channel', 64)->nullable();
                $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
                $table->string('situation_summary', 2000);
                $table->timestamp('situation_period_start')->nullable();
                $table->timestamp('situation_period_end')->nullable();
                $table->foreignId('situation_finding_id')->nullable()->constrained('findings')->nullOnDelete();
                $table->foreignId('situation_opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
                $table->string('action_kind', 64);
                $table->string('action_summary', 2000);
                $table->timestamp('action_occurred_at');
                $table->foreignId('action_task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->foreignId('action_recommendation_id')->nullable()->constrained('recommendations')->nullOnDelete();
                $table->string('outcome_summary', 2000);
                $table->timestamp('outcome_observed_at');
                $table->timestamp('outcome_period_start')->nullable();
                $table->timestamp('outcome_period_end')->nullable();
                $table->string('outcome_clarity', 32);
                $table->string('support_status', 32);
                $table->json('quality_assessment');
                $table->string('quality_policy_version', 64);
                $table->timestamp('quality_assessed_at')->nullable();
                $table->string('causality_status', 64);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();

                $table->unique(['brand_experience_id', 'revision_number']);
                $table->index(['brand_experience_id', 'created_at']);
                $table->index('action_occurred_at');
                $table->index('outcome_observed_at');
                $table->index('channel');
                $table->index('market_code');
            });
        }

        if (! Schema::hasTable('brand_experience_goals')) {
            Schema::create('brand_experience_goals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_experience_revision_id')->constrained('brand_experience_revisions')->cascadeOnDelete();
                $table->foreignId('brand_goal_id')->constrained('brand_goals')->restrictOnDelete();
                $table->string('goal_label_snapshot');
                $table->timestamps();

                $table->unique(['brand_experience_revision_id', 'brand_goal_id'], 'be_goal_unique');
                $table->index('brand_goal_id');
            });
        }

        if (! Schema::hasTable('brand_experience_offerings')) {
            Schema::create('brand_experience_offerings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_experience_revision_id')->constrained('brand_experience_revisions')->cascadeOnDelete();
                $table->foreignId('brand_offering_id')->constrained('brand_offerings')->restrictOnDelete();
                $table->string('offering_label_snapshot');
                $table->timestamps();

                $table->unique(['brand_experience_revision_id', 'brand_offering_id'], 'be_offering_unique');
                $table->index('brand_offering_id');
            });
        }

        if (! Schema::hasTable('brand_experience_evidence_links')) {
            Schema::create('brand_experience_evidence_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_experience_revision_id')->constrained('brand_experience_revisions')->cascadeOnDelete();
                $table->foreignId('evidence_id')->constrained('evidence')->restrictOnDelete();
                $table->string('evidence_fingerprint', 128);
                $table->string('role', 32);
                $table->timestamps();

                $table->unique(['brand_experience_revision_id', 'evidence_id', 'role'], 'be_evidence_role_unique');
                $table->index(['evidence_id', 'evidence_fingerprint']);
                $table->index('role');
            });
        }

        // Add current_revision FK after revisions table exists (avoid circular create issues).
        if (Schema::hasTable('brand_experiences')) {
            Schema::table('brand_experiences', function (Blueprint $table): void {
                $table->foreign('current_revision_id')
                    ->references('id')
                    ->on('brand_experience_revisions')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_experience_evidence_links');
        Schema::dropIfExists('brand_experience_offerings');
        Schema::dropIfExists('brand_experience_goals');

        if (Schema::hasTable('brand_experiences')) {
            Schema::table('brand_experiences', function (Blueprint $table): void {
                $table->dropForeign(['current_revision_id']);
            });
        }

        Schema::dropIfExists('brand_experience_revisions');
        Schema::dropIfExists('brand_experiences');
    }
};
