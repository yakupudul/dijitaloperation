<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 50 — Agent / Skill execution provenance tables (no SkillV2 / AgentV2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_execution_runs')) {
            Schema::create('agent_execution_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->nullable()->constrained('runs')->nullOnDelete();
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->string('agent_slug');
                $table->string('agent_version', 40);
                $table->string('ai_route_key');
                $table->string('route_signature');
                $table->string('status', 40);
                $table->string('input_fingerprint', 64);
                $table->string('pre_inference_status', 40);
                $table->string('block_reason_code')->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['digital_asset_id', 'created_at'], 'agent_exec_runs_asset_created_index');
                $table->index(['agent_slug'], 'agent_exec_runs_agent_slug_index');
                $table->index(['status'], 'agent_exec_runs_status_index');
                $table->index(['input_fingerprint'], 'agent_exec_runs_input_fp_index');
            });
        }

        if (! Schema::hasTable('skill_execution_runs')) {
            Schema::create('skill_execution_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_execution_run_id')
                    ->constrained('agent_execution_runs')
                    ->cascadeOnDelete();
                $table->string('skill_module');
                $table->string('skill_slug');
                $table->string('skill_version', 40);
                $table->string('skill_signature');
                $table->string('status', 40);
                $table->string('abstention_reason_code')->nullable();
                $table->unsignedInteger('provider_attempt_count')->default(0);
                $table->json('validated_output')->nullable();
                $table->json('eligibility')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['agent_execution_run_id', 'skill_signature'],
                    'skill_exec_runs_agent_sig_unique'
                );
            });
        }

        if (! Schema::hasTable('ai_provider_attempts')) {
            Schema::create('ai_provider_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('skill_execution_run_id')
                    ->constrained('skill_execution_runs')
                    ->cascadeOnDelete();
                $table->unsignedInteger('attempt_number');
                $table->string('provider');
                $table->string('model');
                $table->string('status', 40);
                $table->string('provider_request_id')->nullable();
                $table->string('error_category')->nullable();
                $table->json('usage')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_attempts');
        Schema::dropIfExists('skill_execution_runs');
        Schema::dropIfExists('agent_execution_runs');
    }
};
