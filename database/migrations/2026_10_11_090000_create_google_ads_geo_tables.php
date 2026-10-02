<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Ads performance by province / district (geographic_view) and the names of Google's geo target ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('google_ads_geo_daily')) {
            Schema::create('google_ads_geo_daily', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('customer_id');
                $table->date('reporting_date');
                $table->text('location_type');
                $table->text('geo_target_region');
                $table->text('geo_target_city');
                $table->bigInteger('impressions')->default(0);
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('interactions')->default(0);
                $table->bigInteger('cost_micros')->default(0);
                $table->decimal('cost_amount', 20, 6)->default(0);
                $table->decimal('conversions', 20, 6)->default(0);
                $table->decimal('conversions_value', 20, 6)->default(0);
                $table->decimal('all_conversions', 20, 6)->default(0);
                $table->decimal('all_conversions_value', 20, 6)->default(0);
                $table->decimal('view_through_conversions', 20, 6)->default(0);
                $table->char('currency', 3);
                $table->integer('contract_version');
                $table->unsignedBigInteger('last_collection_run_id')->nullable();
                $table->unsignedBigInteger('last_dataset_run_id')->nullable();
                $table->timestampTz('first_collected_at');
                $table->timestampTz('last_collected_at');
                $table->text('source_timezone')->nullable();
                $table->char('record_fingerprint', 64);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['external_resource_id', 'customer_id', 'reporting_date', 'location_type', 'geo_target_region', 'geo_target_city'], 'google_ads_geo_daily_nk');
                $table->index(['external_resource_id', 'reporting_date'], 'google_ads_geo_daily_date');
            });
        }
        if (! Schema::hasTable('google_ads_geo_names')) {
            Schema::create('google_ads_geo_names', function (Blueprint $table): void {
                $table->string('resource_name', 64)->primary();
                $table->text('name');
                $table->text('canonical_name')->nullable();
                $table->string('target_type', 40)->nullable();
                $table->string('country_code', 4)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_geo_daily');
        Schema::dropIfExists('google_ads_geo_names');
    }
};
