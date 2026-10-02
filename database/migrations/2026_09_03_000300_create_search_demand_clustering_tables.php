<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_clusters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('cluster_key', 160);
            $table->string('name');
            $table->string('demand_family')->nullable();
            $table->string('serp_intent_group')->nullable();
            $table->string('content_target_cluster')->nullable();
            $table->foreignId('representative_portfolio_item_id')->nullable()->constrained('brand_query_portfolio_items')->nullOnDelete();
            $table->string('suggested_content_type', 80)->nullable();
            $table->text('rationale')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->string('validation_status', 32)->default('ai_prediction');
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('active');
            $table->foreignId('merged_into_cluster_id')->nullable()->constrained('search_demand_clusters')->nullOnDelete();
            $table->timestampTz('last_clustered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['brand_id', 'cluster_key'], 'search_demand_cluster_brand_key_uq');
            $table->index(['brand_id', 'status', 'is_locked'], 'search_demand_cluster_brand_status_idx');
            $table->index(['brand_id', 'validation_status'], 'search_demand_cluster_validation_idx');
        });

        Schema::create('search_demand_cluster_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_cluster_id')->constrained('search_demand_clusters')->cascadeOnDelete();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->string('source', 48);
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('rationale')->nullable();
            $table->unsignedInteger('assigned_version')->default(1);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique('brand_query_portfolio_item_id', 'search_demand_cluster_membership_item_uq');
            $table->index('search_demand_cluster_id', 'search_demand_cluster_membership_cluster_idx');
        });

        Schema::create('search_demand_cluster_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_cluster_id')->constrained('search_demand_clusters')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('change_type', 48);
            $table->json('snapshot');
            $table->json('change_metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->unique(
                ['search_demand_cluster_id', 'version'],
                'search_demand_cluster_version_uq',
            );
        });

        Schema::create('search_demand_clustering_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('mode', 32);
            $table->string('status', 24)->default('queued');
            $table->json('input_payload');
            $table->char('input_fingerprint', 64);
            $table->string('agent_signature', 160);
            $table->string('skill_signature', 160);
            $table->char('skill_fingerprint', 64);
            $table->string('route_key', 160);
            $table->string('route_signature', 500);
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('pending_candidates')->default(0);
            $table->unsignedInteger('approved_candidates')->default(0);
            $table->unsignedInteger('rejected_candidates')->default(0);
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['brand_id', 'mode', 'status'], 'search_demand_clustering_run_status_idx');
            $table->index(['input_fingerprint', 'status'], 'search_demand_clustering_input_status_idx');
        });

        Schema::create('search_demand_cluster_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_clustering_run_id')->constrained('search_demand_clustering_runs')->cascadeOnDelete();
            $table->string('action_type', 32);
            $table->foreignId('existing_cluster_id')->nullable()->constrained('search_demand_clusters')->nullOnDelete();
            $table->json('source_cluster_ids')->nullable();
            $table->json('member_portfolio_item_ids');
            $table->char('candidate_fingerprint', 64);
            $table->string('cluster_key', 160)->nullable();
            $table->string('cluster_name')->nullable();
            $table->string('demand_family')->nullable();
            $table->string('serp_intent_group')->nullable();
            $table->string('content_target_cluster')->nullable();
            $table->foreignId('representative_portfolio_item_id')->nullable()->constrained('brand_query_portfolio_items')->nullOnDelete();
            $table->string('suggested_content_type', 80)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('uncertain')->default(false);
            $table->text('uncertainty_reason')->nullable();
            $table->text('rationale')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('approved_cluster_id')->nullable()->constrained('search_demand_clusters')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->json('raw_output')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_clustering_run_id', 'candidate_fingerprint'],
                'search_demand_cluster_candidate_fingerprint_uq',
            );
            $table->index(
                ['search_demand_clustering_run_id', 'status'],
                'search_demand_cluster_candidate_run_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_cluster_candidates');
        Schema::dropIfExists('search_demand_clustering_runs');
        Schema::dropIfExists('search_demand_cluster_versions');
        Schema::dropIfExists('search_demand_cluster_memberships');
        Schema::dropIfExists('search_demand_clusters');
    }
};
