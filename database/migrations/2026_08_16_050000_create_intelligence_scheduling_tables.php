<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 63 — Intelligence Scheduling orchestration tables.
 * No workflow graph / agent swarm / generic morph executor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intelligence_triggers')) {
            Schema::create('intelligence_triggers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
                $table->string('source_kind', 64);
                $table->string('source_identity', 191);
                $table->string('source_revision_fingerprint', 96);
                $table->string('trigger_key', 191);
                $table->string('reason', 191);
                $table->string('status', 32)->default('PENDING');
                $table->json('changed_evidence_refs')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('planned_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->unique('trigger_key');
                $table->index(['customer_id', 'brand_id', 'status']);
                $table->index(['digital_asset_id', 'status']);
                $table->index(['source_kind', 'source_identity']);
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('intelligence_execution_plans')) {
            Schema::create('intelligence_execution_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
                $table->foreignId('intelligence_trigger_id')->nullable()->constrained('intelligence_triggers')->nullOnDelete();
                $table->string('plan_fingerprint', 96);
                $table->string('status', 32);
                $table->string('current_phase', 64)->nullable();
                $table->json('trigger_ids')->nullable();
                $table->json('evidence_input_fingerprints')->nullable();
                $table->json('analyzers')->nullable();
                $table->json('phase_results')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('supersedes_plan_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                $table->unique('plan_fingerprint');
                $table->index(['digital_asset_id', 'status']);
                $table->index(['brand_id', 'status']);
                $table->index('status');
            });
        }

        if (! Schema::hasTable('automatic_intelligence_policies')) {
            Schema::create('automatic_intelligence_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
                $table->string('agent_slug', 128);
                $table->string('agent_version', 64);
                $table->string('skill_signature', 191);
                $table->string('skill_version', 64);
                $table->string('route_key', 128);
                $table->string('route_signature', 191);
                $table->json('allowed_trigger_kinds');
                $table->boolean('trigger_on_required_evidence_change')->default(true);
                $table->boolean('trigger_on_optional_evidence_change')->default(false);
                $table->unsignedInteger('max_automatic_runs_per_window')->default(5);
                $table->unsignedInteger('window_minutes')->default(1440);
                $table->unsignedInteger('min_interval_minutes')->default(60);
                $table->unsignedInteger('max_fanout_per_plan')->default(3);
                $table->string('status', 32);
                $table->string('policy_fingerprint', 96);
                $table->unsignedInteger('policy_version')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->timestamp('last_automatic_run_at')->nullable();
                $table->unsignedInteger('runs_in_window')->default(0);
                $table->timestamp('window_started_at')->nullable();

                $table->index(['brand_id', 'status']);
                $table->index(['digital_asset_id', 'status']);
                $table->unique(['brand_id', 'digital_asset_id', 'skill_signature', 'agent_slug', 'agent_version'], 'aip_scope_skill_agent_uq');
            });
        }

        if (! Schema::hasTable('intelligence_schedules')) {
            Schema::create('intelligence_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained('digital_assets')->nullOnDelete();
                $table->string('frequency', 32);
                $table->unsignedInteger('interval')->default(1);
                $table->string('timezone', 64);
                $table->time('local_time');
                $table->string('misfire_policy', 32)->default('run_latest_missed');
                $table->string('status', 32);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->timestamp('next_run_at')->nullable();

                $table->index(['status', 'next_run_at']);
                $table->index(['brand_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_schedules');
        Schema::dropIfExists('automatic_intelligence_policies');
        Schema::dropIfExists('intelligence_execution_plans');
        Schema::dropIfExists('intelligence_triggers');
    }
};
