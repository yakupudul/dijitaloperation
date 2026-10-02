<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta country + city (region) performance per ad and day, with results (leads, purchases, messages).
 * Meta returns country and region in separate breakdowns; region rows get the ad's country when the ad
 * delivered in a single country that day, otherwise country stays '' ("more than one country").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_geo_results_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('account_id', 40);
            $table->date('reporting_date');
            $table->string('level', 10); // country | region
            $table->string('ad_id', 40);
            $table->string('ad_name', 300)->nullable();
            $table->string('adset_id', 40)->nullable();
            $table->string('adset_name', 300)->nullable();
            $table->string('campaign_id', 40)->nullable();
            $table->string('campaign_name', 300)->nullable();
            $table->string('country', 8)->default(''); // '' = more than one country (region rows)
            $table->string('region', 120)->default('');
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('leads', 12, 2)->default(0);
            $table->decimal('purchases', 12, 2)->default(0);
            $table->decimal('purchase_value', 14, 2)->default(0);
            $table->decimal('messages', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->timestampsTz();
            $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'level', 'ad_id', 'region', 'country'], 'meta_geo_results_daily_unique');
            $table->index(['digital_asset_id', 'reporting_date'], 'meta_geo_results_daily_asset_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_geo_results_daily');
    }
};
