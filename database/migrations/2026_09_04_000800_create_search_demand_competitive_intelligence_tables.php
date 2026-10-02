<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_competitive_intelligence_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('run_id')->unique()->constrained('runs')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->unsignedBigInteger('digital_asset_id');
            $table->unsignedBigInteger('search_demand_cluster_id');
            $table->unsignedBigInteger('search_demand_page_ownership_id');
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
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->text('summary')->nullable();
            $table->json('portfolio_gap_themes')->nullable();
            $table->json('differentiation_strategy')->nullable();
            $table->json('caveats')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('digital_asset_id', 'sd_ci_run_asset_fk')
                ->references('id')->on('digital_assets')->cascadeOnDelete();
            $table->foreign('search_demand_cluster_id', 'sd_ci_run_cluster_fk')
                ->references('id')->on('search_demand_clusters')->cascadeOnDelete();
            $table->foreign('search_demand_page_ownership_id', 'sd_ci_run_owner_fk')
                ->references('id')->on('search_demand_page_ownerships')->cascadeOnDelete();
            $table->index(['digital_asset_id', 'search_demand_cluster_id', 'status'], 'sd_ci_run_scope_status_idx');
            $table->index(['input_fingerprint', 'status'], 'sd_ci_run_fingerprint_status_idx');
        });

        Schema::create('search_demand_competitive_page_analyses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('competitive_intelligence_run_id');
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('competitor_page_observation_id');
            $table->string('proposed_entity_kind', 24)->default('unknown');
            $table->json('proposed_competitive_roles')->nullable();
            $table->string('page_intent', 32)->default('unclear');
            $table->json('topics')->nullable();
            $table->json('subtopics')->nullable();
            $table->json('user_questions')->nullable();
            $table->json('content_structure')->nullable();
            $table->json('local_trust_signals')->nullable();
            $table->json('missing_coverage')->nullable();
            $table->json('unnecessary_content')->nullable();
            $table->json('do_not_copy')->nullable();
            $table->json('differentiation_ideas')->nullable();
            $table->json('evidence_explanation')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('abstained')->default(false);
            $table->text('abstention_reason')->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('competitive_intelligence_run_id', 'sd_ci_page_run_fk')
                ->references('id')->on('search_demand_competitive_intelligence_runs')->cascadeOnDelete();
            $table->foreign('search_demand_competitor_id', 'sd_ci_page_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('competitor_page_observation_id', 'sd_ci_page_obs_fk')
                ->references('id')->on('search_demand_competitor_page_observations')->cascadeOnDelete();
            $table->unique(['competitive_intelligence_run_id', 'competitor_page_observation_id'], 'sd_ci_page_run_obs_uq');
            $table->index(['search_demand_competitor_id', 'review_status'], 'sd_ci_page_comp_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_competitive_page_analyses');
        Schema::dropIfExists('search_demand_competitive_intelligence_runs');
    }
};
