<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Area SERP checks (Faz 2b): per brand opt-in with a monthly USD cap, DataForSEO city/district locations
 * (free directory, cached), the resolved location on each service area, and one row per SERP check
 * (top 10 organic results, our rank, cost; results reused across brands within the freshness window).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->boolean('demand_serp_enabled')->default(false);
            $table->decimal('demand_serp_monthly_usd', 8, 2)->nullable();
        });

        Schema::table('brand_service_areas', function (Blueprint $table): void {
            $table->unsignedInteger('dataforseo_location_code')->nullable();
        });

        Schema::create('dataforseo_serp_locations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('location_code')->unique();
            $table->string('location_name');
            $table->string('location_type', 48)->nullable();
            $table->string('country_iso', 4)->nullable();
            $table->unsignedInteger('parent_code')->nullable();
            $table->string('name_folded');
            $table->timestampsTz();
            $table->index(['country_iso', 'name_folded'], 'dataforseo_serp_locations_country_name_idx');
        });

        Schema::create('demand_serp_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->foreignId('brand_service_area_id')->nullable()->constrained('brand_service_areas')->nullOnDelete();
            $table->foreignId('brand_demand_query_id')->nullable()->constrained('brand_demand_queries')->nullOnDelete();
            $table->text('keyword');
            $table->unsignedInteger('location_code');
            $table->string('language_code', 8);
            $table->char('fingerprint', 64);
            $table->string('status', 16); // completed | reused | failed
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->unsignedSmallInteger('our_rank')->nullable();
            $table->text('our_url')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampsTz();

            $table->index(['fingerprint', 'checked_at'], 'demand_serp_checks_fingerprint_idx');
            $table->index(['brand_id', 'checked_at'], 'demand_serp_checks_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_serp_checks');
        Schema::dropIfExists('dataforseo_serp_locations');
        Schema::table('brand_service_areas', fn (Blueprint $table) => $table->dropColumn('dataforseo_location_code'));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn(['demand_serp_enabled', 'demand_serp_monthly_usd']));
    }
};
