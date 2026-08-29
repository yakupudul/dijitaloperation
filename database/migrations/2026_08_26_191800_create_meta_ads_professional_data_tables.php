<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $provenance = static function (Blueprint $table): void {
            $table->integer('contract_version');
            $table->unsignedBigInteger('last_collection_run_id')->nullable();
            $table->unsignedBigInteger('last_dataset_run_id')->nullable();
            $table->timestampTz('first_collected_at');
            $table->timestampTz('last_collected_at');
            $table->text('source_timezone')->nullable();
            $table->char('record_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
        };

        if (! Schema::hasTable('meta_account_daily')) {
            Schema::create('meta_account_daily', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->date('reporting_date');
                $table->decimal('spend', 20, 6)->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('reach')->nullable();
                $table->decimal('frequency', 20, 6)->nullable();
                $table->bigInteger('inline_link_clicks')->nullable();
                $table->bigInteger('outbound_clicks')->nullable();
                $table->char('currency', 3)->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'reporting_date'], 'meta_account_daily_nk');
                $table->index(['digital_asset_id', 'reporting_date'], 'meta_account_daily_asset_date');
            });
        }

        if (! Schema::hasTable('meta_ad_snapshot')) {
            Schema::create('meta_ad_snapshot', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->text('ad_id');
                $table->text('ad_name')->nullable();
                $table->text('campaign_id')->nullable();
                $table->text('adset_id')->nullable();
                $table->text('creative_id')->nullable();
                $table->text('status')->nullable();
                $table->text('effective_status')->nullable();
                $table->timestampTz('created_time')->nullable();
                $table->timestampTz('updated_time')->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'ad_id'], 'meta_ad_snapshot_nk');
                $table->index(['digital_asset_id', 'account_id'], 'meta_ad_snapshot_asset_account');
            });
        }

        if (! Schema::hasTable('meta_adset_targeting_snapshot')) {
            Schema::create('meta_adset_targeting_snapshot', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->text('adset_id');
                $table->text('campaign_id')->nullable();
                $table->text('adset_name')->nullable();
                $table->text('optimization_goal')->nullable();
                $table->text('billing_event')->nullable();
                $table->text('bid_strategy')->nullable();
                $table->json('targeting')->nullable();
                $table->json('promoted_object')->nullable();
                $table->json('attribution_spec')->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'adset_id'], 'meta_adset_targeting_nk');
                $table->index(['digital_asset_id', 'account_id'], 'meta_adset_targeting_asset_account');
            });
        }

        if (! Schema::hasTable('meta_conversion_source_snapshot')) {
            Schema::create('meta_conversion_source_snapshot', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->text('source_type');
                $table->text('source_id');
                $table->text('source_name')->nullable();
                $table->text('event_type')->nullable();
                $table->timestampTz('first_fired_time')->nullable();
                $table->timestampTz('last_fired_time')->nullable();
                $table->boolean('is_archived')->nullable();
                $table->boolean('is_unavailable')->nullable();
                $table->text('pixel_id')->nullable();
                $table->text('rule')->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'source_type', 'source_id'], 'meta_conversion_source_nk');
                $table->index(['digital_asset_id', 'account_id'], 'meta_conversion_source_asset_account');
            });
        }

        if (! Schema::hasTable('meta_change_event')) {
            Schema::create('meta_change_event', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->char('event_key', 64);
                $table->timestampTz('event_time');
                $table->text('event_type')->nullable();
                $table->text('translated_event_type')->nullable();
                $table->text('object_id')->nullable();
                $table->text('object_name')->nullable();
                $table->text('object_type')->nullable();
                $table->text('actor_id')->nullable();
                $table->text('actor_name')->nullable();
                $table->text('application_id')->nullable();
                $table->text('application_name')->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'event_key'], 'meta_change_event_nk');
                $table->index(['digital_asset_id', 'event_time'], 'meta_change_event_asset_time');
            });
        }

        if (! Schema::hasTable('meta_video_engagement_daily')) {
            Schema::create('meta_video_engagement_daily', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->date('reporting_date');
                $table->text('ad_id');
                $table->text('metric_type');
                $table->text('action_type');
                $table->decimal('metric_value', 20, 6)->default(0);
                $table->char('currency', 3)->nullable();
                $provenance($table);
                $table->unique(
                    ['external_resource_id', 'account_id', 'reporting_date', 'ad_id', 'metric_type', 'action_type'],
                    'meta_video_engagement_nk'
                );
                $table->index(['digital_asset_id', 'reporting_date'], 'meta_video_engagement_asset_date');
            });
        }

        if (! Schema::hasTable('meta_analysis_breakdown_daily')) {
            Schema::create('meta_analysis_breakdown_daily', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->date('reporting_date');
                $table->text('breakdown_type');
                $table->text('breakdown_key');
                $table->decimal('spend', 20, 6)->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('reach')->nullable();
                $table->char('currency', 3)->nullable();
                $provenance($table);
                $table->unique(
                    ['external_resource_id', 'account_id', 'reporting_date', 'breakdown_type', 'breakdown_key'],
                    'meta_analysis_breakdown_nk'
                );
                $table->index(['digital_asset_id', 'reporting_date'], 'meta_analysis_breakdown_asset_date');
            });
        }

        if (! Schema::hasTable('meta_hourly_daily')) {
            Schema::create('meta_hourly_daily', function (Blueprint $table) use ($provenance): void {
                $table->id();
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->unsignedBigInteger('external_resource_id');
                $table->text('account_id');
                $table->date('reporting_date');
                $table->text('hour_bucket');
                $table->decimal('spend', 20, 6)->default(0);
                $table->bigInteger('impressions')->default(0);
                $table->bigInteger('clicks')->default(0);
                $table->bigInteger('reach')->nullable();
                $table->char('currency', 3)->nullable();
                $provenance($table);
                $table->unique(['external_resource_id', 'account_id', 'reporting_date', 'hour_bucket'], 'meta_hourly_daily_nk');
                $table->index(['digital_asset_id', 'reporting_date'], 'meta_hourly_daily_asset_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_hourly_daily');
        Schema::dropIfExists('meta_analysis_breakdown_daily');
        Schema::dropIfExists('meta_video_engagement_daily');
        Schema::dropIfExists('meta_change_event');
        Schema::dropIfExists('meta_conversion_source_snapshot');
        Schema::dropIfExists('meta_adset_targeting_snapshot');
        Schema::dropIfExists('meta_ad_snapshot');
        Schema::dropIfExists('meta_account_daily');
    }
};
