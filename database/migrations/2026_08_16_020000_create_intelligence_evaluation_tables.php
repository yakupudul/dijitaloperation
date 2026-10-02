<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 55 — Intelligence Evaluation persistence.
 *
 * Suite / Dataset / Case definitions are versioned in code catalogs.
 * Runs pin those versions and store assertion / review history.
 * No EAV assertion engine. No magic AI score column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('suite_key', 120);
            $table->string('suite_version', 80);
            $table->string('dataset_key', 120);
            $table->string('dataset_version', 80);
            $table->string('evaluation_policy_version', 80);
            $table->string('assertion_registry_version', 80);
            $table->string('human_rubric_version', 80)->nullable();
            $table->string('run_mode', 40);
            $table->string('status', 40);
            $table->string('safety_gate_status', 40);
            $table->string('quality_gate_status', 40);
            $table->string('live_model_status', 60);
            $table->string('agent_definition_signature', 160)->nullable();
            $table->string('skill_definition_signature', 160)->nullable();
            $table->string('ai_route_version', 120)->nullable();
            $table->string('retrieval_policy_version', 80)->nullable();
            $table->string('output_schema_version', 80)->nullable();
            $table->string('baseline_key', 120)->nullable();
            $table->unsignedBigInteger('baseline_run_id')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->json('dimension_summary')->nullable();
            $table->json('runtime_pins')->nullable();
            $table->json('limits')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['suite_key', 'status']);
            $table->index(['evaluation_policy_version', 'created_at']);
        });

        Schema::create('intelligence_evaluation_case_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')
                ->constrained('intelligence_evaluation_runs')
                ->cascadeOnDelete();
            $table->string('case_key', 160);
            $table->string('case_version', 80);
            $table->string('dataset_version', 80);
            $table->string('status', 40);
            $table->string('safety_gate_status', 40);
            $table->string('ablation_variant', 40)->nullable();
            $table->unsignedBigInteger('eval_customer_id')->nullable();
            $table->unsignedBigInteger('eval_brand_id')->nullable();
            $table->string('retrieval_fingerprint', 128)->nullable();
            $table->string('context_pack_fingerprint', 128)->nullable();
            $table->unsignedBigInteger('agent_execution_run_id')->nullable();
            $table->json('retrieval_metrics')->nullable();
            $table->json('dimension_results')->nullable();
            $table->json('runtime_pins')->nullable();
            $table->json('mocked_output')->nullable();
            $table->unsignedInteger('retrieval_duration_ms')->nullable();
            $table->unsignedInteger('provider_latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->text('failure_summary')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_run_id', 'case_key', 'case_version'], 'ieval_case_run_unique');
            $table->index(['case_key', 'status']);
        });

        Schema::create('intelligence_evaluation_assertion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_case_run_id')
                ->constrained('intelligence_evaluation_case_runs')
                ->cascadeOnDelete();
            $table->string('assertion_type', 80);
            $table->string('dimension', 40);
            $table->string('status', 40);
            $table->boolean('is_hard_safety')->default(false);
            $table->string('source_phase', 40);
            $table->string('authority', 40);
            $table->json('expected')->nullable();
            $table->json('actual')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->string('diagnostic', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['evaluation_case_run_id', 'assertion_type', 'reason_code'],
                'ieval_assertion_unique'
            );
            $table->index(['assertion_type', 'status']);
        });

        Schema::create('intelligence_evaluation_human_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_case_run_id')
                ->constrained('intelligence_evaluation_case_runs')
                ->cascadeOnDelete();
            $table->string('rubric_version', 80);
            $table->unsignedBigInteger('reviewer_id');
            $table->json('dimension_outcomes');
            $table->text('notes')->nullable();
            $table->boolean('attempted_privacy_override')->default(false);
            $table->boolean('privacy_override_accepted')->default(false);
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['evaluation_case_run_id', 'reviewed_at']);
        });

        Schema::create('intelligence_evaluation_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('baseline_key', 120)->unique();
            $table->string('label', 160);
            $table->string('evaluation_policy_version', 80);
            $table->string('suite_key', 120);
            $table->string('suite_version', 80);
            $table->string('dataset_version', 80);
            $table->string('agent_definition_signature', 160)->nullable();
            $table->string('skill_definition_signature', 160)->nullable();
            $table->string('ai_route_version', 120)->nullable();
            $table->string('retrieval_policy_version', 80)->nullable();
            $table->foreignId('baseline_run_id')
                ->nullable()
                ->constrained('intelligence_evaluation_runs')
                ->nullOnDelete();
            $table->json('dimension_snapshot')->nullable();
            $table->boolean('is_explicit')->default(true);
            $table->timestamps();
        });

        Schema::create('intelligence_evaluation_judge_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_case_run_id')
                ->constrained('intelligence_evaluation_case_runs')
                ->cascadeOnDelete();
            $table->string('judge_contract_version', 80);
            $table->string('judge_route_version', 120)->nullable();
            $table->string('judge_model', 120)->nullable();
            $table->boolean('same_model_as_subject')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->boolean('attempted_safety_override')->default(false);
            $table->boolean('safety_override_accepted')->default(false);
            $table->json('structured_findings')->nullable();
            $table->timestamps();

            $table->unique(['evaluation_case_run_id'], 'ieval_judge_one_per_case');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_evaluation_judge_results');
        Schema::dropIfExists('intelligence_evaluation_baselines');
        Schema::dropIfExists('intelligence_evaluation_human_reviews');
        Schema::dropIfExists('intelligence_evaluation_assertion_results');
        Schema::dropIfExists('intelligence_evaluation_case_runs');
        Schema::dropIfExists('intelligence_evaluation_runs');
    }
};
