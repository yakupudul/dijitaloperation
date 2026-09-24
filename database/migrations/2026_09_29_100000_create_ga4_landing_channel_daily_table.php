<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA4 landing page × default channel group per day (Sayfa Karnesi). Resource-first central fact table with
 * the same provenance columns as the other GA4 daily tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ga4_landing_channel_daily')) {
            return;
        }
        Schema::create('ga4_landing_channel_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedBigInteger('external_resource_id');
            $table->text('property_id');
            $table->date('reporting_date');
            $table->text('landingPage');
            $table->text('sessionDefaultChannelGroup');
            $table->bigInteger('sessions')->nullable();
            $table->bigInteger('engagedSessions')->nullable();
            $table->bigInteger('activeUsers')->nullable();
            $table->decimal('engagementRate', 20, 6)->nullable();
            $table->decimal('keyEvents', 20, 6)->nullable();
            $table->decimal('sessionKeyEventRate', 20, 6)->nullable();
            $table->integer('contract_version');
            $table->unsignedBigInteger('last_collection_run_id')->nullable();
            $table->unsignedBigInteger('last_dataset_run_id')->nullable();
            $table->timestampTz('first_collected_at');
            $table->timestampTz('last_collected_at');
            $table->text('source_timezone')->nullable();
            $table->char('record_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['external_resource_id', 'property_id', 'reporting_date', 'landingPage', 'sessionDefaultChannelGroup'], 'ga4_landing_channel_daily_resource_nk_unique');
            $table->index(['external_resource_id', 'reporting_date'], 'ga4_landing_channel_daily_resource_date_idx');
            $table->index(['digital_asset_id', 'reporting_date'], 'ga4_landing_channel_daily_asset_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ga4_landing_channel_daily');
    }
};
