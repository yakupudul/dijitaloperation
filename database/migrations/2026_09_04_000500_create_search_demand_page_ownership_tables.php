<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_page_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('search_demand_cluster_id')->constrained('search_demand_clusters')->cascadeOnDelete();
            $table->foreignId('website_page_profile_id')->nullable()->constrained('website_page_profiles')->nullOnDelete();
            $table->foreignId('page_identity_id')->nullable()->constrained('intelligence_page_identities')->nullOnDelete();
            $table->text('target_url')->nullable();
            $table->string('status', 40)->default('unassigned');
            $table->string('decision_source', 48)->default('operator');
            $table->boolean('is_locked')->default(false);
            $table->text('rationale')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['digital_asset_id', 'search_demand_cluster_id'],
                'search_demand_page_ownership_asset_cluster_uq',
            );
            $table->index(['brand_id', 'status'], 'search_demand_page_ownership_brand_status_idx');
        });

        Schema::create('search_demand_page_ownership_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_page_ownership_id')->constrained('search_demand_page_ownerships')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('change_type', 48);
            $table->json('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->unique(
                ['search_demand_page_ownership_id', 'version'],
                'search_demand_page_ownership_version_uq',
            );
        });

        Schema::create('search_demand_page_relevance_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('search_demand_cluster_id')->constrained('search_demand_clusters')->cascadeOnDelete();
            $table->string('status', 24)->default('queued');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('comparison_start');
            $table->date('comparison_end');
            $table->json('input_payload');
            $table->json('response_payload')->nullable();
            $table->char('input_fingerprint', 64);
            $table->string('agent_signature', 160);
            $table->string('skill_signature', 160);
            $table->char('skill_fingerprint', 64);
            $table->string('route_key', 160);
            $table->string('route_signature', 500);
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->string('deterministic_state', 40);
            $table->string('ai_decision_state', 40)->nullable();
            $table->boolean('wrong_url_candidate')->default(false);
            $table->boolean('cannibalization_candidate')->default(false);
            $table->string('recommended_content_type', 48)->nullable();
            $table->foreignId('recommended_candidate_id')->nullable();
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('eligible_candidate_count')->default(0);
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->text('rationale')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'search_demand_cluster_id', 'status'], 'search_demand_page_relevance_scope_idx');
            $table->index(['input_fingerprint', 'status'], 'search_demand_page_relevance_cache_idx');
        });

        Schema::create('search_demand_page_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_page_relevance_run_id')->constrained('search_demand_page_relevance_runs')->cascadeOnDelete();
            $table->foreignId('website_page_profile_id')->constrained('website_page_profiles')->cascadeOnDelete();
            $table->foreignId('page_identity_id')->constrained('intelligence_page_identities')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_key_hash', 64);
            $table->json('candidate_sources');
            $table->string('technical_eligibility', 24);
            $table->json('technical_gate');
            $table->json('matched_terms')->nullable();
            $table->unsignedBigInteger('gsc_clicks')->nullable();
            $table->unsignedBigInteger('gsc_impressions')->nullable();
            $table->decimal('gsc_impression_share', 8, 6)->nullable();
            $table->unsignedBigInteger('comparison_impressions')->nullable();
            $table->decimal('comparison_impression_share', 8, 6)->nullable();
            $table->unsignedInteger('serp_supporting_queries')->nullable();
            $table->unsignedInteger('serp_observed_queries')->nullable();
            $table->string('semantic_fit', 24)->nullable();
            $table->unsignedTinyInteger('semantic_confidence')->nullable();
            $table->text('semantic_rationale')->nullable();
            $table->json('supported_query_ids')->nullable();
            $table->boolean('ai_recommended')->default(false);
            $table->string('review_status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_page_relevance_run_id', 'website_page_profile_id'],
                'search_demand_page_candidate_run_profile_uq',
            );
            $table->index(
                ['search_demand_page_relevance_run_id', 'technical_eligibility'],
                'search_demand_page_candidate_eligibility_idx',
            );
        });

        Schema::table('search_demand_page_relevance_runs', function (Blueprint $table): void {
            $table->foreign('recommended_candidate_id', 'search_demand_page_relevance_recommended_fk')
                ->references('id')->on('search_demand_page_candidates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('search_demand_page_relevance_runs', function (Blueprint $table): void {
            $table->dropForeign('search_demand_page_relevance_recommended_fk');
        });
        Schema::dropIfExists('search_demand_page_candidates');
        Schema::dropIfExists('search_demand_page_relevance_runs');
        Schema::dropIfExists('search_demand_page_ownership_versions');
        Schema::dropIfExists('search_demand_page_ownerships');
    }
};
