<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_change_trackings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('search_demand_cluster_id');
            $table->unsignedBigInteger('search_demand_improvement_proposal_id');
            $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->constrained('recommendations')->cascadeOnDelete();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->text('change_summary');
            $table->json('affected_urls');
            $table->json('affected_cluster_ids');
            $table->json('baseline_html_fingerprints');
            $table->json('latest_html_fingerprints')->nullable();
            $table->json('verification_urls')->nullable();
            $table->timestampTz('applied_at');
            $table->timestampTz('review_after_at');
            $table->unsignedBigInteger('targeted_collection_run_id')->nullable();
            $table->string('status', 32)->default('recorded');
            $table->string('result_status', 48)->nullable();
            $table->json('component_results')->nullable();
            $table->json('metric_comparison')->nullable();
            $table->json('technical_result')->nullable();
            $table->json('semantic_result')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->foreign('digital_asset_id', 'sd_change_tracking_asset_fk')
                ->references('id')->on('digital_assets')->cascadeOnDelete();
            $table->foreign('search_demand_cluster_id', 'sd_change_tracking_cluster_fk')
                ->references('id')->on('search_demand_clusters')->cascadeOnDelete();
            $table->foreign('search_demand_improvement_proposal_id', 'sd_change_tracking_proposal_fk')
                ->references('id')->on('search_demand_improvement_proposals')->cascadeOnDelete();
            $table->foreign('targeted_collection_run_id', 'sd_change_tracking_collection_fk')
                ->references('id')->on('collection_runs')->nullOnDelete();
            $table->index(['digital_asset_id', 'status'], 'sd_change_tracking_asset_status_idx');
            $table->index(['search_demand_cluster_id', 'applied_at'], 'sd_change_tracking_cluster_applied_idx');
        });

        Schema::create('search_demand_change_verification_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('run_id')->unique()->constrained('runs')->cascadeOnDelete();
            $table->unsignedBigInteger('search_demand_change_tracking_id');
            $table->string('status', 32)->default('queued');
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
            $table->json('technical_result');
            $table->json('metric_comparison');
            $table->json('semantic_result')->nullable();
            $table->string('proposed_result_status', 48);
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('search_demand_change_tracking_id', 'sd_change_verification_tracking_fk')
                ->references('id')->on('search_demand_change_trackings')->cascadeOnDelete();
            $table->index(['search_demand_change_tracking_id', 'status'], 'sd_change_verification_tracking_status_idx');
            $table->index(['input_fingerprint', 'status'], 'sd_change_verification_fingerprint_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_change_verification_runs');
        Schema::dropIfExists('search_demand_change_trackings');
    }
};
