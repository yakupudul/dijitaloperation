<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_enrichment_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->string('status', 32)->default('queued');
            $table->string('provider', 48);
            $table->unsignedSmallInteger('depth');
            $table->string('device', 16);
            $table->boolean('include_query_expansion')->default(false);
            $table->unsignedBigInteger('location_code');
            $table->string('location_name')->nullable();
            $table->string('language_code', 32);
            $table->string('language_name')->nullable();
            $table->unsignedInteger('query_count')->default(0);
            $table->unsignedInteger('serp_cache_hits')->default(0);
            $table->unsignedInteger('metric_cache_hits')->default(0);
            $table->unsignedInteger('provider_request_count')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->decimal('reported_cost_usd', 12, 6)->nullable();
            $table->json('cost_estimate_basis');
            $table->json('request_context');
            $table->char('input_fingerprint', 64);
            $table->char('serp_batch_fingerprint', 64)->nullable();
            $table->char('metric_batch_fingerprint', 64)->nullable();
            $table->char('expansion_batch_fingerprint', 64)->nullable();
            $table->timestampTz('serp_paid_attempt_started_at')->nullable();
            $table->timestampTz('serp_committed_at')->nullable();
            $table->timestampTz('metric_paid_attempt_started_at')->nullable();
            $table->timestampTz('metric_committed_at')->nullable();
            $table->timestampTz('expansion_paid_attempt_started_at')->nullable();
            $table->timestampTz('expansion_committed_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_consent_recorded_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'status'], 'search_demand_enrichment_asset_status_idx');
            $table->index(['brand_id', 'scope_type', 'scope_id'], 'search_demand_enrichment_scope_idx');
        });

        Schema::create('search_demand_enrichment_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('search_demand_cluster_id')->nullable()->constrained('search_demand_clusters')->nullOnDelete();
            $table->text('query_text');
            $table->char('serp_request_fingerprint', 64);
            $table->char('metric_request_fingerprint', 64);
            $table->string('serp_status', 24)->default('pending');
            $table->string('metric_status', 24)->default('pending');
            $table->timestampTz('serp_paid_attempt_started_at')->nullable();
            $table->timestampTz('serp_committed_at')->nullable();
            $table->decimal('serp_reported_cost_usd', 12, 6)->nullable();
            $table->foreignId('serp_snapshot_id')->nullable();
            $table->foreignId('keyword_metric_snapshot_id')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_enrichment_run_id', 'brand_query_portfolio_item_id'],
                'search_demand_enrichment_run_item_uq',
            );
        });

        Schema::create('search_demand_serp_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('search_demand_cluster_id')->nullable()->constrained('search_demand_clusters')->nullOnDelete();
            $table->text('query_text');
            $table->string('provider', 48);
            $table->string('endpoint');
            $table->char('request_fingerprint', 64);
            $table->string('provider_task_id')->nullable();
            $table->unsignedBigInteger('location_code');
            $table->string('location_name')->nullable();
            $table->string('language_code', 32);
            $table->string('language_name')->nullable();
            $table->string('device', 16);
            $table->unsignedSmallInteger('depth');
            $table->unsignedBigInteger('result_count')->nullable();
            $table->json('serp_features')->nullable();
            $table->unsignedSmallInteger('brand_rank')->nullable();
            $table->text('brand_url')->nullable();
            $table->timestampTz('retrieved_at');
            $table->timestampsTz();

            $table->index(
                ['digital_asset_id', 'brand_query_portfolio_item_id', 'retrieved_at'],
                'search_demand_serp_snapshot_latest_idx',
            );
            $table->index(['request_fingerprint', 'retrieved_at'], 'search_demand_serp_snapshot_cache_idx');
        });

        Schema::create('search_demand_serp_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_serp_snapshot_id')->constrained('search_demand_serp_snapshots')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank_group')->nullable();
            $table->unsignedSmallInteger('rank_absolute')->nullable();
            $table->text('url');
            $table->string('domain')->nullable();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_brand_domain')->default(false);
            $table->json('observed_payload')->nullable();
            $table->timestampsTz();

            $table->index(['search_demand_serp_snapshot_id', 'rank_group'], 'search_demand_serp_result_rank_idx');
        });

        Schema::create('search_demand_keyword_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->text('query_text');
            $table->string('provider', 48);
            $table->string('endpoint');
            $table->char('request_fingerprint', 64);
            $table->string('provider_task_id')->nullable();
            $table->unsignedBigInteger('location_code');
            $table->string('language_code', 32);
            $table->unsignedBigInteger('search_volume')->nullable();
            $table->decimal('cpc', 14, 6)->nullable();
            $table->string('competition', 32)->nullable();
            $table->unsignedSmallInteger('competition_index')->nullable();
            $table->json('monthly_searches')->nullable();
            $table->string('measurement_type', 32)->default('provider_estimate');
            $table->timestampTz('retrieved_at');
            $table->timestampsTz();

            $table->index(
                ['digital_asset_id', 'brand_query_portfolio_item_id', 'retrieved_at'],
                'search_demand_keyword_metric_latest_idx',
            );
            $table->index(['request_fingerprint', 'retrieved_at'], 'search_demand_keyword_metric_cache_idx');
        });

        Schema::create('search_demand_provider_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('endpoint');
            $table->char('request_fingerprint', 64);
            $table->json('request_payload');
            $table->json('response_payload');
            $table->decimal('reported_cost_usd', 12, 6)->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->index(['request_fingerprint', 'captured_at'], 'search_demand_provider_payload_idx');
        });

        Schema::create('search_demand_expansion_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->char('source_request_fingerprint', 64);
            $table->char('candidate_fingerprint', 64);
            $table->text('keyword');
            $table->unsignedBigInteger('search_volume')->nullable();
            $table->decimal('cpc', 14, 6)->nullable();
            $table->string('competition', 32)->nullable();
            $table->unsignedSmallInteger('competition_index')->nullable();
            $table->json('monthly_searches')->nullable();
            $table->string('measurement_type', 32)->default('provider_estimate');
            $table->string('status', 24)->default('pending');
            $table->foreignId('approved_portfolio_item_id')->nullable()->constrained('brand_query_portfolio_items')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_enrichment_run_id', 'candidate_fingerprint'],
                'search_demand_expansion_candidate_run_uq',
            );
            $table->index(['source_request_fingerprint', 'created_at'], 'search_demand_expansion_candidate_cache_idx');
        });

        Schema::create('search_demand_serp_cluster_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_demand_enrichment_run_id')->constrained('search_demand_enrichment_runs')->cascadeOnDelete();
            $table->foreignId('search_demand_cluster_id')->constrained('search_demand_clusters')->cascadeOnDelete();
            $table->unsignedInteger('evidence_query_count');
            $table->unsignedInteger('compared_pair_count');
            $table->decimal('mean_url_overlap', 8, 6)->nullable();
            $table->string('recommended_status', 32);
            $table->json('threshold_basis');
            $table->text('rationale');
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['search_demand_enrichment_run_id', 'search_demand_cluster_id'],
                'search_demand_serp_cluster_review_uq',
            );
        });

        Schema::table('search_demand_enrichment_run_items', function (Blueprint $table): void {
            $table->foreign('serp_snapshot_id', 'search_demand_enrichment_run_item_serp_fk')
                ->references('id')->on('search_demand_serp_snapshots')->nullOnDelete();
            $table->foreign('keyword_metric_snapshot_id', 'search_demand_enrichment_run_item_metric_fk')
                ->references('id')->on('search_demand_keyword_metric_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('search_demand_enrichment_run_items', function (Blueprint $table): void {
            $table->dropForeign('search_demand_enrichment_run_item_serp_fk');
            $table->dropForeign('search_demand_enrichment_run_item_metric_fk');
        });
        Schema::dropIfExists('search_demand_serp_cluster_reviews');
        Schema::dropIfExists('search_demand_expansion_candidates');
        Schema::dropIfExists('search_demand_provider_payloads');
        Schema::dropIfExists('search_demand_keyword_metric_snapshots');
        Schema::dropIfExists('search_demand_serp_results');
        Schema::dropIfExists('search_demand_serp_snapshots');
        Schema::dropIfExists('search_demand_enrichment_run_items');
        Schema::dropIfExists('search_demand_enrichment_runs');
    }
};
