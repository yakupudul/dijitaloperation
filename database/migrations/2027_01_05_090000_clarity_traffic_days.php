<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft Clarity, traffic sources (yakup, 2026-11-05: "Clarity ile web sitesini geliştirmek, müşterimin müşteri
 * sayısını artırmak"): the daily behaviour of each channel × source × campaign of a site (sessions, bot sessions, quick
 * backs, rage / dead clicks, scroll depth). The rules turn paid traffic that leaves at once, does not read the page or
 * looks like bots into Teknik sağlık work; the MCP channel-data tool reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clarity_traffic_days')) {
            return;
        }
        Schema::create('clarity_traffic_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->date('day');
            $table->string('key_hash', 64);
            $table->string('channel', 100);
            $table->string('source', 255);
            $table->string('campaign', 255);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('bot_sessions')->default(0);
            $table->decimal('quickback_pct', 6, 2)->nullable();
            $table->decimal('rage_pct', 6, 2)->nullable();
            $table->decimal('dead_pct', 6, 2)->nullable();
            $table->decimal('scroll_depth', 6, 2)->nullable();
            $table->unsignedInteger('active_seconds')->nullable();
            $table->timestampsTz();
            $table->unique(['website_asset_id', 'day', 'key_hash'], 'clarity_traffic_days_unique');
            $table->index(['website_asset_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_traffic_days');
    }
};
