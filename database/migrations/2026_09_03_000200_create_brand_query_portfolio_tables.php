<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_query_portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('search_query_library_item_id')->nullable()->constrained('search_query_library_items')->nullOnDelete();
            $table->foreignId('intelligence_search_term_identity_id')->nullable()->constrained('intelligence_search_term_identities')->nullOnDelete();
            $table->char('identity_hash', 64)->unique();
            $table->text('custom_canonical_text')->nullable();
            $table->text('custom_folded_text')->nullable();
            $table->text('query_text_override')->nullable();
            $table->string('language_code', 32)->nullable();
            $table->string('market_code', 32)->nullable();
            $table->string('demand_family_override')->nullable();
            $table->string('location_scope_override', 32)->nullable();
            $table->string('location_value_override')->nullable();
            $table->boolean('is_branded_override')->nullable();
            $table->string('area_scope', 32)->default('all_brand_areas');
            $table->string('origin_type', 32);
            $table->string('status', 24)->default('active');
            $table->string('global_proposal_status', 32)->default('not_applicable');
            $table->timestampTz('global_proposed_at')->nullable();
            $table->foreignId('global_proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['brand_id', 'search_query_library_item_id'],
                'brand_query_portfolio_global_item_uq',
            );
            $table->index(['brand_id', 'status'], 'brand_query_portfolio_brand_status_idx');
            $table->index(['brand_id', 'origin_type'], 'brand_query_portfolio_brand_origin_idx');
        });

        Schema::create('brand_query_portfolio_item_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('service_catalog_item_id')->constrained('service_catalog_items')->cascadeOnDelete();
            $table->string('provenance', 48)->default('inherited');
            $table->timestampsTz();

            $table->unique(
                ['brand_query_portfolio_item_id', 'service_catalog_item_id'],
                'brand_query_portfolio_item_service_uq',
            );
        });

        Schema::create('brand_query_portfolio_item_area', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('brand_service_area_id')->constrained('brand_service_areas')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['brand_query_portfolio_item_id', 'brand_service_area_id'],
                'brand_query_portfolio_item_area_uq',
            );
        });

        Schema::create('brand_query_portfolio_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_query_portfolio_item_id')->constrained('brand_query_portfolio_items')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('status', 24)->default('active');
            $table->text('query_text_override')->nullable();
            $table->string('demand_family_override')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['brand_query_portfolio_item_id', 'digital_asset_id'],
                'brand_query_portfolio_item_asset_uq',
            );
            $table->index(['digital_asset_id', 'status'], 'brand_query_portfolio_asset_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_query_portfolio_assets');
        Schema::dropIfExists('brand_query_portfolio_item_area');
        Schema::dropIfExists('brand_query_portfolio_item_service');
        Schema::dropIfExists('brand_query_portfolio_items');
    }
};
