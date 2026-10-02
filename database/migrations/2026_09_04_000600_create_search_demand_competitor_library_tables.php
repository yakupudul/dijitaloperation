<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_demand_competitors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('normalized_domain');
            $table->char('normalized_domain_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->string('entity_kind', 24)->default('unknown');
            $table->boolean('is_commercial_competitor')->default(false);
            $table->boolean('is_serp_competitor')->default(false);
            $table->boolean('is_content_competitor')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('first_observed_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['brand_id', 'normalized_domain_hash'], 'search_demand_competitor_brand_domain_uq');
            $table->index(['brand_id', 'status'], 'search_demand_competitor_brand_status_idx');
        });

        Schema::create('search_demand_competitor_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->string('source_type', 48);
            $table->string('provider', 48)->nullable();
            $table->string('source_record_type', 80)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->char('source_fingerprint', 64);
            $table->json('evidence_payload')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_source_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('digital_asset_id', 'sd_comp_source_asset_fk')
                ->references('id')->on('digital_assets')->nullOnDelete();
            $table->unique(
                ['search_demand_competitor_id', 'source_fingerprint'],
                'search_demand_competitor_source_uq',
            );
        });

        Schema::create('search_demand_competitor_urls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->text('url');
            $table->char('normalized_url_hash', 64);
            $table->string('domain');
            $table->string('source_type', 48);
            $table->timestampTz('first_observed_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_url_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->unique(
                ['search_demand_competitor_id', 'normalized_url_hash'],
                'search_demand_competitor_url_uq',
            );
        });

        Schema::create('search_demand_competitor_service', function (Blueprint $table): void {
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('service_catalog_item_id');
            $table->string('provenance', 48)->default('operator');
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_service_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('service_catalog_item_id', 'sd_comp_service_item_fk')
                ->references('id')->on('service_catalog_items')->cascadeOnDelete();
            $table->primary(
                ['search_demand_competitor_id', 'service_catalog_item_id'],
                'search_demand_competitor_service_pk',
            );
        });

        Schema::create('search_demand_competitor_area', function (Blueprint $table): void {
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('brand_service_area_id');
            $table->string('provenance', 48)->default('operator');
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_area_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('brand_service_area_id', 'sd_comp_area_area_fk')
                ->references('id')->on('brand_service_areas')->cascadeOnDelete();
            $table->primary(
                ['search_demand_competitor_id', 'brand_service_area_id'],
                'search_demand_competitor_area_pk',
            );
        });

        Schema::create('search_demand_competitor_cluster', function (Blueprint $table): void {
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('search_demand_cluster_id');
            $table->string('provenance', 48)->default('operator');
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_cluster_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('search_demand_cluster_id', 'sd_comp_cluster_cluster_fk')
                ->references('id')->on('search_demand_clusters')->cascadeOnDelete();
            $table->primary(
                ['search_demand_competitor_id', 'search_demand_cluster_id'],
                'search_demand_competitor_cluster_pk',
            );
        });

        Schema::create('search_demand_competitor_queries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('search_demand_competitor_id');
            $table->unsignedBigInteger('brand_query_portfolio_item_id');
            $table->string('source_type', 48);
            $table->unsignedSmallInteger('best_observed_rank')->nullable();
            $table->timestampTz('first_observed_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('search_demand_competitor_id', 'sd_comp_query_comp_fk')
                ->references('id')->on('search_demand_competitors')->cascadeOnDelete();
            $table->foreign('brand_query_portfolio_item_id', 'sd_comp_query_item_fk')
                ->references('id')->on('brand_query_portfolio_items')->cascadeOnDelete();
            $table->unique(
                ['search_demand_competitor_id', 'brand_query_portfolio_item_id'],
                'search_demand_competitor_query_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_demand_competitor_queries');
        Schema::dropIfExists('search_demand_competitor_cluster');
        Schema::dropIfExists('search_demand_competitor_area');
        Schema::dropIfExists('search_demand_competitor_service');
        Schema::dropIfExists('search_demand_competitor_urls');
        Schema::dropIfExists('search_demand_competitor_sources');
        Schema::dropIfExists('search_demand_competitors');
    }
};
