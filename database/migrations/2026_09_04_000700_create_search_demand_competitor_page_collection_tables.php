<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_competitor_page_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('runs')->cascadeOnDelete();
            $table->unsignedBigInteger('search_demand_cluster_id');
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('search_demand_competitor_url_id');
            $table->text('requested_url');
            $table->char('normalized_url_hash', 64);
            $table->unsignedSmallInteger('selection_order');
            $table->unsignedSmallInteger('best_observed_rank')->nullable();
            $table->string('status', 24)->default('queued');
            $table->text('error_summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->foreign('search_demand_cluster_id', 'sd_comp_page_item_cluster_fk')
                ->references('id')->on('search_demand_clusters')->cascadeOnDelete();
            $table->foreign('search_demand_competitor_id', 'sd_comp_page_item_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('search_demand_competitor_url_id', 'sd_comp_page_item_url_fk')
                ->references('id')->on('search_demand_competitor_urls')->cascadeOnDelete();
            $table->unique(['run_id', 'normalized_url_hash'], 'sd_comp_page_item_run_url_uq');
            $table->index(['search_demand_cluster_id', 'status'], 'sd_comp_page_item_cluster_status_idx');
        });

        Schema::create('search_demand_competitor_page_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('run_item_id');
            $table->unsignedBigInteger('search_demand_competitor_url_id');
            $table->unsignedBigInteger('previous_observation_id')->nullable();
            $table->unsignedBigInteger('content_source_observation_id')->nullable();
            $table->text('requested_url');
            $table->text('final_url')->nullable();
            $table->string('status', 24);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type', 120)->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->unsignedSmallInteger('redirect_count')->default(0);
            $table->text('fetch_error')->nullable();
            $table->char('raw_html_hash', 64)->nullable();
            $table->char('content_fingerprint', 64)->nullable();
            $table->boolean('content_changed')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('h1')->nullable();
            $table->json('headings')->nullable();
            $table->json('schema_summary')->nullable();
            $table->json('internal_links')->nullable();
            $table->json('external_links')->nullable();
            $table->json('service_expressions')->nullable();
            $table->json('location_expressions')->nullable();
            $table->string('normalization_version', 48)->default('competitor-page-v1');
            $table->timestampTz('observed_at');
            $table->timestampsTz();

            $table->foreign('run_item_id', 'sd_comp_page_obs_item_fk')
                ->references('id')->on('search_demand_competitor_page_run_items')->cascadeOnDelete();
            $table->foreign('search_demand_competitor_url_id', 'sd_comp_page_obs_url_fk')
                ->references('id')->on('search_demand_competitor_urls')->cascadeOnDelete();
            $table->foreign('previous_observation_id', 'sd_comp_page_obs_previous_fk')
                ->references('id')->on('search_demand_competitor_page_observations')->nullOnDelete();
            $table->foreign('content_source_observation_id', 'sd_comp_page_obs_content_source_fk')
                ->references('id')->on('search_demand_competitor_page_observations')->nullOnDelete();
            $table->unique('run_item_id', 'sd_comp_page_obs_item_uq');
            $table->index(['search_demand_competitor_url_id', 'observed_at'], 'sd_comp_page_obs_url_time_idx');
            $table->index(['content_fingerprint', 'observed_at'], 'sd_comp_page_obs_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_competitor_page_observations');
        Schema::dropIfExists('search_demand_competitor_page_run_items');
    }
};
