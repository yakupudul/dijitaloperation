<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO content pipeline Faz 3–4.
 *
 *  - topic_map_builds: one row per (queued / running / finished) rebuild of a website's topic map.
 *  - topic_clusters: the brand-level topic map of one website — hub queries grouped by service and clustered by topic.
 *    Each cluster carries its intent, page type, demand, the existing page that covers it (owner), coverage, possible
 *    cannibalization and one verdict. Operator edits (rename, merge, split, move query, skip) survive rebuilds.
 *  - topic_cluster_queries: cluster membership; `pinned` rows were placed by the operator and are never moved.
 *  - content_ideas: concrete article ideas (from clusters, service areas, AI gap ideation or the operator).
 *  - content_articles: written articles (source language + localized versions linked by translation key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_map_builds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->string('status', 16)->default('queued'); // queued | running | done | failed
            $table->string('trigger', 24)->default('manual'); // manual | weekly | hub
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'id'], 'topic_map_builds_site_idx');
        });

        Schema::create('topic_clusters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->string('label', 200);
            $table->string('label_source', 16)->default('auto'); // auto | operator
            $table->string('origin', 16)->default('auto'); // auto | operator (split)
            $table->string('head_query', 300)->nullable();
            $table->string('intent', 16)->default('informational'); // informational | commercial | local | branded
            $table->string('page_type', 16)->default('guide'); // service | guide | faq | comparison | location
            $table->decimal('demand_score', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('search_volume')->default(0);
            $table->unsignedBigInteger('ads_clicks')->default(0);
            $table->unsignedInteger('query_count')->default(0);
            $table->decimal('cohesion', 5, 3)->nullable();
            $table->string('owner_url', 1000)->nullable();
            $table->string('owner_title', 300)->nullable();
            $table->string('owner_source', 16)->nullable(); // search_console | inventory
            $table->decimal('owner_position', 6, 2)->nullable();
            $table->string('coverage', 16)->default('uncovered'); // covered | weak | uncovered
            $table->json('cannibal_urls')->nullable();
            $table->json('similar_existing')->nullable();
            $table->string('verdict', 16)->default('new'); // none | strengthen | new | merge
            $table->json('verdict_detail')->nullable();
            $table->string('status', 16)->default('active'); // active | skipped | merged | retired
            $table->foreignId('merged_into_id')->nullable()->constrained('topic_clusters')->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('edited_at')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'status', 'demand_score'], 'topic_clusters_site_idx');
            $table->index(['brand_id', 'brand_offering_id'], 'topic_clusters_offering_idx');
        });

        Schema::create('topic_cluster_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_cluster_id')->constrained('topic_clusters')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_demand_query_id')->nullable()->constrained('brand_demand_queries')->nullOnDelete();
            $table->string('query', 300);
            $table->char('query_key', 64);
            $table->string('intent', 16)->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('position', 6, 2)->nullable();
            $table->unsignedBigInteger('search_volume')->nullable();
            $table->unsignedBigInteger('ads_clicks')->default(0);
            $table->decimal('demand', 14, 2)->default(0);
            $table->boolean('is_head')->default(false);
            $table->boolean('pinned')->default(false);
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'query_key'], 'topic_cluster_queries_site_key_uq');
            $table->index(['topic_cluster_id', 'demand'], 'topic_cluster_queries_cluster_idx');
        });

        Schema::create('content_ideas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('topic_cluster_id')->nullable()->constrained('topic_clusters')->nullOnDelete();
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->char('idea_key', 64);
            $table->string('title', 250);
            $table->string('focus_keyword', 200)->nullable();
            $table->json('queries')->nullable();
            $table->string('page_type', 16)->default('guide');
            $table->string('target_url', 1000)->nullable();
            $table->json('outline')->nullable();
            $table->json('faq')->nullable();
            $table->json('internal_links')->nullable();
            $table->json('similar_existing')->nullable();
            $table->string('location', 160)->nullable();
            $table->string('source', 16)->default('cluster'); // cluster | location | ai | operator
            $table->string('status', 16)->default('open'); // open | writing | written | removed
            $table->decimal('demand_score', 14, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'idea_key'], 'content_ideas_site_key_uq');
            $table->index(['digital_asset_id', 'status', 'sort'], 'content_ideas_site_idx');
        });

        Schema::create('content_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('content_idea_id')->nullable()->constrained('content_ideas')->nullOnDelete();
            $table->foreignId('topic_cluster_id')->nullable()->constrained('topic_clusters')->nullOnDelete();
            $table->foreignId('source_article_id')->nullable()->constrained('content_articles')->cascadeOnDelete();
            $table->string('language', 12)->nullable();
            $table->string('translation_key', 120)->nullable();
            $table->string('status', 16)->default('writing'); // writing | ready | needs_fix | failed | sent | exported | published
            $table->string('title', 250);
            $table->json('payload')->nullable(); // ArticleDraft::toArray()
            $table->json('compliance')->nullable();
            $table->json('quality')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('ai_production_id')->nullable()->constrained('ai_productions')->nullOnDelete();
            $table->foreignId('external_write_action_id')->nullable()->constrained('external_write_actions')->nullOnDelete();
            $table->json('translate_to')->nullable();
            $table->string('batch', 40)->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'status'], 'content_articles_site_idx');
            $table->index(['source_article_id', 'language'], 'content_articles_source_idx');
            $table->index(['digital_asset_id', 'batch'], 'content_articles_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_articles');
        Schema::dropIfExists('content_ideas');
        Schema::dropIfExists('topic_cluster_queries');
        Schema::dropIfExists('topic_clusters');
        Schema::dropIfExists('topic_map_builds');
    }
};
