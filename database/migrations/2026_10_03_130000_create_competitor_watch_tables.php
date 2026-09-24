<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8e: weekly public snapshots of approved competitors' sites (home page head + sitemap URLs) to spot new pages
 * and message changes, and Facebook page ids for Meta Ad Library links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_site_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('search_demand_competitor_id')->nullable()->constrained('search_demand_competitors')->nullOnDelete();
            $table->string('domain', 255);
            $table->date('observed_on');
            $table->string('status', 16);
            $table->string('title', 300)->nullable();
            $table->string('h1', 300)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sitemap_urls_count')->nullable();
            $table->json('sitemap_urls')->nullable();
            $table->json('new_urls')->nullable();
            $table->unsignedInteger('removed_urls_count')->default(0);
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'domain', 'observed_on']);
        });

        Schema::table('search_demand_competitors', function (Blueprint $table): void {
            $table->string('facebook_page_id', 40)->nullable();
        });
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->string('facebook_page_id', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_intel_settings', function (Blueprint $table): void {
            $table->dropColumn('facebook_page_id');
        });
        Schema::table('search_demand_competitors', function (Blueprint $table): void {
            $table->dropColumn('facebook_page_id');
        });
        Schema::dropIfExists('competitor_site_snapshots');
    }
};
