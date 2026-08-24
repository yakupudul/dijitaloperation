<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_ads_account_monthly_history')) {
            return;
        }

        Schema::create('google_ads_account_monthly_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedBigInteger('external_resource_id');
            $table->text('customer_id');
            $table->date('reporting_month');
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('cost_micros')->default(0);
            $table->decimal('cost_amount', 20, 6)->default(0);
            $table->decimal('conversions', 20, 6)->default(0);
            $table->decimal('conversions_value', 20, 6)->default(0);
            $table->char('currency', 3);
            $table->boolean('activity_detected')->default(false);
            $table->integer('contract_version');
            $table->unsignedBigInteger('last_collection_run_id')->nullable();
            $table->unsignedBigInteger('last_dataset_run_id')->nullable();
            $table->timestampTz('first_collected_at');
            $table->timestampTz('last_collected_at');
            $table->text('source_timezone')->nullable();
            $table->char('record_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['external_resource_id', 'customer_id', 'reporting_month'],
                'gads_account_monthly_history_nk',
            );
            $table->index(
                ['external_resource_id', 'reporting_month'],
                'gads_account_monthly_history_resource_month',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_account_monthly_history');
    }
};
