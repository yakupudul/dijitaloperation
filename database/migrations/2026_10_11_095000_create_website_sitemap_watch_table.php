<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.4.1: hourly sitemap watch for websites without the WordPress Connector. Holds the last seen <lastmod> of each
 * sitemap file and page URL; a newer lastmod starts a targeted crawl of just those pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_sitemap_watch', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
            $table->json('sitemaps')->nullable();
            $table->json('pages')->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('last_changed_count')->default(0);
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampTz('changed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_sitemap_watch');
    }
};
