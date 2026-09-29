<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — Faz 3 (Sorgular): the normalized `queries` row carries its totals across sources (search impressions /
 * clicks, Ads cost / conversions, Business Profile impressions), the source list and the operator's "gizle" flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('queries', 'hidden')) {
            return;
        }
        Schema::table('queries', function (Blueprint $table): void {
            $table->boolean('hidden')->default(false)->after('is_suggested');
            $table->decimal('ads_cost', 14, 4)->nullable()->after('clicks');
            $table->decimal('ads_conversions', 12, 2)->nullable()->after('ads_cost');
            $table->unsignedBigInteger('gbp_impressions')->default(0)->after('ads_conversions');
            $table->string('sources', 32)->nullable()->after('gbp_impressions'); // gsc,google_ads,gbp

            $table->index(['hidden', 'impressions'], 'queries_hidden_impressions_idx');
            $table->index(['sector_id', 'hidden', 'impressions'], 'queries_sector_hidden_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('queries', 'hidden')) {
            return;
        }
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropIndex('queries_hidden_impressions_idx');
            $table->dropIndex('queries_sector_hidden_idx');
        });
        Schema::table('queries', function (Blueprint $table): void {
            $table->dropColumn(['hidden', 'ads_cost', 'ads_conversions', 'gbp_impressions', 'sources']);
        });
    }
};
