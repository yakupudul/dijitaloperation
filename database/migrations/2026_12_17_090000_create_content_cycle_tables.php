<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Haftalık içerik döngüsü ve konu haritası (yakup, 2026-10-16 "Sırayla tüm yol haritasını yap"):
 *
 *  - content_site_states: per website the one-off back-dated fill ("2 günde 1", only existing sites without an own post
 *    in the last 60 days; off for the rest, done once it reaches today), its writing rhythm (new + update per week by
 *    the stage of its topic map) and the services whose topics are written now (one at a time, two on big sites).
 *  - content_topics: the topic map of a site's service: one main page and 8–15 questions, each with its type (pillar,
 *    decision, process, fear, local, seasonal, abroad), the page that answers it today and the idea / article made of it.
 *
 * Then the new prompts are adopted and the back-dated fill is scoped once for the existing sites (jobs only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_site_states')) {
            Schema::create('content_site_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('digital_asset_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('backfill', 12)->nullable();
                $table->timestamp('backfill_done_at')->nullable();
                $table->string('stage', 20)->nullable();
                $table->unsignedSmallInteger('new_per_week')->nullable();
                $table->unsignedSmallInteger('updates_per_week')->nullable();
                $table->json('active_service_ids')->nullable();
                $table->date('week_planned')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('content_topics')) {
            Schema::create('content_topics', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('service_id')->nullable();
                $table->unsignedBigInteger('cluster_id')->nullable();
                $table->string('type', 16);
                $table->string('question', 300);
                $table->string('title', 160);
                $table->string('coverage', 12)->default('missing');
                $table->string('status', 12)->default('open');
                $table->unsignedSmallInteger('season_month')->nullable();
                $table->float('priority')->default(0);
                $table->unsignedSmallInteger('position')->default(0);
                $table->unsignedBigInteger('page_id')->nullable();
                $table->unsignedBigInteger('suggestion_id')->nullable();
                $table->json('evidence')->nullable();
                $table->timestamps();
                $table->index(['website_asset_id', 'service_id', 'status']);
                $table->index('suggestion_id');
            });
        }
        if (Schema::hasTable('prompt_versions') && Schema::hasTable('users')) {
            try {
                Artisan::call('moxdop:prompts:adopt-default', ['operations' => ['site.weekly_content', 'site.write_article', 'content.review', 'content.topic_map', 'content.brief']]);
            } catch (Throwable) {
                // No admin / roles yet (a fresh database): the code default is used from the first call anyway.
            }
        }
        if (app()->runningUnitTests() || ! Schema::hasTable('suggestions')) {
            return;
        }
        try {
            Artisan::call('moxdop:content:backfill-scope');
        } catch (Throwable) {
            // A fresh database: no site to scope.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_topics');
        Schema::dropIfExists('content_site_states');
    }
};
