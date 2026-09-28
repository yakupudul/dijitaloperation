<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 URL karnesi: one precomputed verdict row per document URL of a website (crawl, sitemap, WordPress and
 * measured URLs joined), plus one audit row per website with site-level checks, counts and refresh state.
 * Rebuilt from stored data after projection rebuild / SEO plan / weekly / manual "Yenile"; never a provider call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_url_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->string('status', 16)->default('queued');
            $table->json('counts')->nullable();
            $table->json('site_checks')->nullable();
            $table->json('sources')->nullable();
            $table->json('period')->nullable();
            $table->json('groups')->nullable();
            $table->unsignedInteger('url_count')->default(0);
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->string('trigger', 32)->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('computed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('website_url_verdicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->char('url_hash', 64);
            $table->text('url_key');
            $table->text('url');
            $table->text('path');
            $table->string('verdict', 16);
            $table->string('severity', 8)->default('none');
            $table->integer('priority')->default(0);
            $table->text('reason');
            $table->text('solution')->nullable();
            $table->unsignedSmallInteger('finding_count')->default(0);
            $table->integer('clicks')->nullable();
            $table->integer('clicks_prev')->nullable();
            $table->integer('impressions')->nullable();
            $table->decimal('position', 6, 2)->nullable();
            $table->integer('sessions')->nullable();
            $table->integer('key_events')->nullable();
            $table->integer('ads_clicks')->nullable();
            $table->decimal('ads_cost', 14, 2)->nullable();
            $table->decimal('ads_conversions', 12, 2)->nullable();
            $table->boolean('indexed')->nullable();
            $table->integer('lcp_ms')->nullable();
            $table->smallInteger('status_code')->nullable();
            $table->integer('word_count')->nullable();
            $table->string('group_key', 64)->nullable();
            $table->json('findings')->nullable();
            $table->json('facts')->nullable();
            $table->timestampTz('computed_at');
            $table->timestampsTz();
            $table->unique(['digital_asset_id', 'url_hash'], 'website_url_verdicts_url_uq');
            $table->index(['digital_asset_id', 'verdict', 'priority'], 'website_url_verdicts_verdict_idx');
            $table->index(['digital_asset_id', 'priority'], 'website_url_verdicts_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_url_verdicts');
        Schema::dropIfExists('website_url_audits');
    }
};
