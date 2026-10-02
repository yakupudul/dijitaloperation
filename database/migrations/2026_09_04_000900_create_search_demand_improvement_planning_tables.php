<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_improvement_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('run_id')->unique()->constrained('runs')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('search_demand_cluster_id');
            $table->unsignedBigInteger('search_demand_page_ownership_id');
            $table->unsignedBigInteger('competitive_intelligence_run_id');
            $table->string('status', 24)->default('queued');
            $table->json('input_payload');
            $table->json('response_payload')->nullable();
            $table->char('input_fingerprint', 64);
            $table->string('agent_signature', 190);
            $table->string('skill_signature', 190);
            $table->char('skill_fingerprint', 64);
            $table->string('route_key', 120);
            $table->char('route_signature', 64);
            $table->string('provider', 48)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedSmallInteger('proposal_count')->default(0);
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('digital_asset_id', 'sd_improvement_run_asset_fk')
                ->references('id')->on('digital_assets')->cascadeOnDelete();
            $table->foreign('search_demand_cluster_id', 'sd_improvement_run_cluster_fk')
                ->references('id')->on('search_demand_clusters')->cascadeOnDelete();
            $table->foreign('search_demand_page_ownership_id', 'sd_improvement_run_owner_fk')
                ->references('id')->on('search_demand_page_ownerships')->cascadeOnDelete();
            $table->foreign('competitive_intelligence_run_id', 'sd_improvement_run_ci_fk')
                ->references('id')->on('search_demand_competitive_intelligence_runs')->cascadeOnDelete();
            $table->index(['digital_asset_id', 'search_demand_cluster_id', 'status'], 'sd_improvement_scope_status_idx');
            $table->index(['input_fingerprint', 'status'], 'sd_improvement_fingerprint_status_idx');
        });

        Schema::create('search_demand_improvement_proposals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('search_demand_improvement_run_id');
            $table->string('origin', 24);
            $table->string('stable_key', 160);
            $table->string('severity', 24)->default('medium');
            $table->string('title');
            $table->text('summary');
            $table->string('action_type', 48);
            $table->string('recommendation_title');
            $table->text('recommendation_action');
            $table->text('rationale');
            $table->json('content_brief')->nullable();
            $table->json('evidence_refs');
            $table->json('verification_steps');
            $table->unsignedTinyInteger('confidence');
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('recommendations')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('search_demand_improvement_run_id', 'sd_improvement_proposal_run_fk')
                ->references('id')->on('search_demand_improvement_runs')->cascadeOnDelete();
            $table->unique(['search_demand_improvement_run_id', 'stable_key'], 'sd_improvement_proposal_key_uq');
            $table->index(['review_status', 'origin'], 'sd_improvement_proposal_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_improvement_proposals');
        Schema::dropIfExists('search_demand_improvement_runs');
    }
};
