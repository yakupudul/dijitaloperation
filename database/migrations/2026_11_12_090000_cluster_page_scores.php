<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfa puanı (docs/product/CONTENT_IDEAS_BLUEPRINT.md §3): per brand cluster row, the Search Console performance of
 * its page on THAT cluster's queries over the last 90 days of data — impressions, clicks, weighted position, coverage
 * (cluster queries the page showed for), click rate, score 1–100 (null when not scored) and the state. GA4 sessions /
 * key events of the same window are information only. Computed nightly (moxdop:clusters:score-pages).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cluster_page_scores')) {
            return;
        }
        Schema::create('cluster_page_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_cluster_page_id')->unique('cluster_page_scores_row_uq')->constrained('brand_cluster_pages')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('state', 16); // scored | low_data | no_gsc | no_gsc_data | no_page | unreachable
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->float('position')->nullable();
            $table->unsignedInteger('covered_queries')->default(0);
            $table->unsignedInteger('cluster_queries')->default(0);
            $table->float('coverage')->default(0);
            $table->float('ctr')->default(0);
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('previous_score')->nullable();
            $table->unsignedInteger('ga4_sessions')->nullable();
            $table->float('ga4_key_events')->nullable();
            $table->timestampTz('computed_at');
            $table->timestampsTz();
            $table->index(['cluster_id', 'score'], 'cluster_page_scores_cluster_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_page_scores');
    }
};
