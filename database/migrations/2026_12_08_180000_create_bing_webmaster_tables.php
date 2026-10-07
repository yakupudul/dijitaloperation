<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bing Webmaster (yakup, 2026-10-07): ChatGPT search reads Bing's index, so Bing's own searches and positions are read
 * too. One agency API key; each MoxDOP website is matched to its verified Bing site; weekly query rows per site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->text('bing_webmaster_api_key')->nullable();
        });
        Schema::create('bing_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete()->unique();
            $table->string('site_url', 500);
            $table->boolean('verified')->default(false);
            $table->text('error')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('bing_query_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('query', 500);
            $table->char('query_hash', 64);
            $table->date('week');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('position', 8, 2)->nullable();
            $table->timestampsTz();
            $table->unique(['digital_asset_id', 'query_hash', 'week'], 'bing_query_stats_uq');
            $table->index(['digital_asset_id', 'week'], 'bing_query_stats_asset_week_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_query_stats');
        Schema::dropIfExists('bing_sites');
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('bing_webmaster_api_key');
        });
    }
};
