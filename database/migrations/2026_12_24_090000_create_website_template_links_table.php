<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pool #56 (2026-10-25): links a site repeats on most of its pages (a sidebar listing every question, a related-posts
 * block) are kept once per site here instead of once per page in website_link_edge. Avrupadent's ~1,400 question
 * pages each carried ~6,000 such links: 11.6M of the table's 13.7M rows (16 GB).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_template_links')) {
            return;
        }
        Schema::create('website_template_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id');
            $table->text('normalized_target_url');
            $table->text('target_url');
            $table->boolean('is_internal')->default(true);
            $table->text('anchor_text')->nullable();
            $table->text('sample_source_url');
            $table->unsignedInteger('source_pages')->default(0);
            $table->timestampTz('detected_at');
            $table->timestampTz('last_seen_at');
            $table->timestamps();
            $table->unique(['digital_asset_id', 'normalized_target_url'], 'website_template_links_asset_target_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_template_links');
    }
};
