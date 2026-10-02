<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand demand table (Faz 2b): every query the brand's own accounts saw (Search Console, Google Ads search
 * terms, Business Profile keywords) with its window metrics, the service and service area it belongs to and
 * whether it is branded. Gold data: rows are updated weekly and never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_demand_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->text('query');
            $table->char('query_key', 64);
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->foreignId('brand_service_area_id')->nullable()->constrained('brand_service_areas')->nullOnDelete();
            $table->string('location_status', 16)->default('none'); // none | in_area | out_of_area
            $table->json('locations')->nullable();
            $table->boolean('is_branded')->default(false);
            $table->string('assignment_source', 16)->default('auto'); // auto | operator
            $table->unsignedBigInteger('gsc_clicks')->default(0);
            $table->unsignedBigInteger('gsc_impressions')->default(0);
            $table->unsignedBigInteger('ads_impressions')->default(0);
            $table->unsignedBigInteger('ads_clicks')->default(0);
            $table->decimal('ads_cost', 14, 2)->default(0);
            $table->decimal('ads_conversions', 12, 2)->default(0);
            $table->unsignedBigInteger('gbp_impressions')->default(0);
            $table->json('sources')->nullable();
            $table->decimal('value_score', 14, 2)->default(0);
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('built_at')->nullable();
            $table->timestampsTz();

            $table->unique(['brand_id', 'query_key'], 'brand_demand_queries_brand_key_uq');
            $table->index(['brand_id', 'brand_offering_id', 'value_score'], 'brand_demand_queries_offering_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_demand_queries');
    }
};
