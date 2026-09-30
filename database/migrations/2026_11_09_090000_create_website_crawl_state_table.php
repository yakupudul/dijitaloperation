<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-level crawl state (WebsiteCrawlState): how often the site's page cache answered our page reads, which cache
 * plugin it runs, whether the WordPress Connector can read its cache files, when the site was last read in full and
 * where the pages of the latest crawl came from (page cache / page read / unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_crawl_state')) {
            return;
        }

        Schema::create('website_crawl_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
            $table->string('cache_plugin', 40)->nullable();
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_checks')->default(0);
            $table->json('page_cache')->nullable();
            $table->timestamp('last_full_read_at')->nullable();
            $table->json('last_run')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_crawl_state');
    }
};
