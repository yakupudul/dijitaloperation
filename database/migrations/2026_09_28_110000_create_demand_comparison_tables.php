<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 2b competitor comparison: fetched page metrics (reused for 28 days, any brand) and the latest
 * our-page-vs-competitors comparison per brand service (read by SEO Görevleri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_page_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('url_key', 64)->unique();
            $table->text('url');
            $table->string('domain');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('metrics')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();
        });

        Schema::create('demand_service_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->constrained('brand_offerings')->cascadeOnDelete();
            $table->text('our_url');
            $table->unsignedSmallInteger('our_rank')->nullable();
            $table->json('our_metrics')->nullable();
            $table->json('competitors')->nullable();
            $table->json('competitor_median')->nullable();
            $table->json('gaps')->nullable();
            $table->timestampTz('compared_at');
            $table->timestampsTz();
            $table->unique(['brand_id', 'brand_offering_id'], 'demand_service_comparisons_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_service_comparisons');
        Schema::dropIfExists('demand_page_snapshots');
    }
};
