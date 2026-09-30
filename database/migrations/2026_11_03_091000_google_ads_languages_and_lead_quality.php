<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 Faz 5 fixes.
 * 1. google_ads_campaign_snapshot.language_codes — campaign language targeting ("all" or ISO codes), outside the
 *    dataset contract so snapshot upserts keep it (GoogleAdsCampaignLanguages).
 * 2. google_ads_lead_quality — the operator's monthly lead quality per campaign (form geldi / uygun / randevu / satış),
 *    shown next to Google Ads conversions on Ölçümleme, never added to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_ads_campaign_snapshot') && ! Schema::hasColumn('google_ads_campaign_snapshot', 'language_codes')) {
            Schema::table('google_ads_campaign_snapshot', function (Blueprint $table): void {
                $table->string('language_codes', 120)->nullable();
            });
        }

        Schema::create('google_ads_lead_quality', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('campaign_id', 40);
            $table->string('campaign_name', 300)->nullable();
            $table->date('month');
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('qualified')->default(0);
            $table->unsignedInteger('appointments')->default(0);
            $table->unsignedInteger('sales')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'campaign_id', 'month'], 'google_ads_lead_quality_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_lead_quality');
        if (Schema::hasColumn('google_ads_campaign_snapshot', 'language_codes')) {
            Schema::table('google_ads_campaign_snapshot', fn (Blueprint $table) => $table->dropColumn('language_codes'));
        }
    }
};
