<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta results by audience and delivery breakdown (read-only Insights, collected with the geo rows): age × gender,
 * hour of day (advertiser time zone), placement (publisher platform × position) and device. One row per day, ad and
 * breakdown value, with spend and the canonical result actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_breakdown_results_daily')) {
            return;
        }
        Schema::create('meta_breakdown_results_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('account_id', 40);
            $table->date('reporting_date');
            $table->string('dimension', 16); // age_gender | hour | placement | device
            $table->string('ad_id', 40);
            $table->string('adset_id', 40)->nullable();
            $table->string('campaign_id', 40)->nullable();
            $table->string('key1', 64)->default(''); // age | hour (00–23) | publisher platform | device
            $table->string('key2', 64)->default(''); // gender | platform position
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('leads', 12, 2)->default(0);
            $table->decimal('purchases', 12, 2)->default(0);
            $table->decimal('purchase_value', 14, 2)->default(0);
            $table->decimal('messages', 12, 2)->default(0);
            $table->string('currency', 8)->nullable();
            $table->timestamps();
            $table->unique(['digital_asset_id', 'account_id', 'reporting_date', 'dimension', 'ad_id', 'key1', 'key2'], 'meta_breakdown_results_daily_unique');
            $table->index(['digital_asset_id', 'dimension', 'reporting_date'], 'meta_breakdown_results_daily_asset_dim_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_breakdown_results_daily');
    }
};
