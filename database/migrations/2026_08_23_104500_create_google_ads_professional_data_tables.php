<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createDaily('google_ads_ad_group_daily', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('ad_group_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id']);

        $this->createDaily('google_ads_ad_daily', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('ad_group_id');
            $table->text('ad_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id', 'ad_id']);

        $this->createDaily('google_ads_device_daily', function (Blueprint $table): void {
            $table->text('device');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'device']);

        $this->createDaily('google_ads_hour_daily', function (Blueprint $table): void {
            $table->text('day_of_week');
            $table->integer('hour');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'day_of_week', 'hour']);

        $this->createDaily('google_ads_network_daily', function (Blueprint $table): void {
            $table->text('ad_network_type');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'ad_network_type']);

        $this->createDaily('google_ads_user_location_daily', function (Blueprint $table): void {
            $table->text('country_criterion_id');
            $table->boolean('targeting_location');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'country_criterion_id', 'targeting_location']);

        $this->createDaily('google_ads_age_range_daily', function (Blueprint $table): void {
            $table->text('campaign_id')->nullable();
            $table->text('ad_group_id');
            $table->text('criterion_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id']);

        $this->createDaily('google_ads_gender_daily', function (Blueprint $table): void {
            $table->text('campaign_id')->nullable();
            $table->text('ad_group_id');
            $table->text('criterion_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'ad_group_id', 'criterion_id']);

        $this->createDaily('google_ads_campaign_audience_daily', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('criterion_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'criterion_id']);

        $this->createDaily('google_ads_ad_group_audience_daily', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('ad_group_id');
            $table->text('criterion_id');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'ad_group_id', 'criterion_id']);

        $this->createDaily('google_ads_pmax_asset_daily', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('asset_group_id');
            $table->text('asset_id');
            $table->text('field_type');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'campaign_id', 'asset_group_id', 'asset_id', 'field_type']);

        $this->createDaily('google_ads_shopping_product_daily', function (Blueprint $table): void {
            $table->char('product_key', 64);
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'product_key']);

        $this->createDaily('google_ads_video_daily', function (Blueprint $table): void {
            $table->text('video_id');
            $table->text('ad_format_type');
        }, ['external_resource_id', 'customer_id', 'reporting_date', 'video_id', 'ad_format_type'], function (Blueprint $table): void {
            $table->bigInteger('video_views')->default(0);
            $table->decimal('video_quartile_p25_rate', 20, 6)->nullable();
            $table->decimal('video_quartile_p50_rate', 20, 6)->nullable();
            $table->decimal('video_quartile_p75_rate', 20, 6)->nullable();
            $table->decimal('video_quartile_p100_rate', 20, 6)->nullable();
        });

        $this->createSnapshot('google_ads_campaign_negative_keyword_snapshot', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('criterion_id');
            $table->text('keyword_text');
            $table->text('match_type')->nullable();
            $table->text('status')->nullable();
        }, ['external_resource_id', 'customer_id', 'campaign_id', 'criterion_id']);

        $this->createSnapshot('google_ads_ad_group_negative_keyword_snapshot', function (Blueprint $table): void {
            $table->text('campaign_id')->nullable();
            $table->text('ad_group_id');
            $table->text('criterion_id');
            $table->text('keyword_text');
            $table->text('match_type')->nullable();
            $table->text('status')->nullable();
        }, ['external_resource_id', 'customer_id', 'ad_group_id', 'criterion_id']);

        $this->createSnapshot('google_ads_bidding_strategy_snapshot', function (Blueprint $table): void {
            $table->text('bidding_strategy_id');
            $table->text('name')->nullable();
            $table->text('strategy_type')->nullable();
            $table->text('status')->nullable();
            $table->bigInteger('campaign_count')->nullable();
        }, ['external_resource_id', 'customer_id', 'bidding_strategy_id']);

        $this->createSnapshot('google_ads_pmax_asset_group_snapshot', function (Blueprint $table): void {
            $table->text('campaign_id');
            $table->text('asset_group_id');
            $table->text('name')->nullable();
            $table->text('status')->nullable();
        }, ['external_resource_id', 'customer_id', 'campaign_id', 'asset_group_id']);

        $this->createSnapshot('google_ads_recommendation_snapshot', function (Blueprint $table): void {
            $table->date('observed_date');
            $table->text('recommendation_resource_name');
            $table->text('recommendation_type')->nullable();
            $table->text('campaign_resource_name')->nullable();
        }, ['external_resource_id', 'customer_id', 'observed_date', 'recommendation_resource_name']);

        $this->createSnapshot('google_ads_change_event', function (Blueprint $table): void {
            $table->char('event_key', 64);
            $table->timestampTz('changed_at');
            $table->text('change_resource_name')->nullable();
            $table->text('change_resource_type')->nullable();
            $table->text('operation')->nullable();
            $table->text('client_type')->nullable();
            $table->text('user_email')->nullable();
        }, ['external_resource_id', 'customer_id', 'event_key']);
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  callable(Blueprint): void  $dimensions
     * @param  list<string>  $naturalKey
     * @param  callable(Blueprint): void|null  $extraMetrics
     */
    private function createDaily(string $name, callable $dimensions, array $naturalKey, ?callable $extraMetrics = null): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($name, $dimensions, $naturalKey, $extraMetrics): void {
            $table->id();
            $this->addScope($table);
            $table->date('reporting_date');
            $dimensions($table);

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

            if ($extraMetrics !== null) {
                $extraMetrics($table);
            }

            $this->addProvenance($table);
            $table->unique($naturalKey, $this->indexName($name, 'nk'));
            $table->index(['external_resource_id', 'reporting_date'], $this->indexName($name, 'date'));
        });
    }

    /**
     * @param  callable(Blueprint): void  $dimensions
     * @param  list<string>  $naturalKey
     */
    private function createSnapshot(string $name, callable $dimensions, array $naturalKey): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($name, $dimensions, $naturalKey): void {
            $table->id();
            $this->addScope($table);
            $dimensions($table);
            $this->addProvenance($table);
            $table->unique($naturalKey, $this->indexName($name, 'nk'));
            $table->index(['external_resource_id'], $this->indexName($name, 'resource'));
        });
    }

    private function addScope(Blueprint $table): void
    {
        $table->unsignedBigInteger('digital_asset_id')->nullable();
        $table->unsignedBigInteger('external_resource_id');
        $table->text('customer_id');
    }

    private function addProvenance(Blueprint $table): void
    {
        $table->integer('contract_version');
        $table->unsignedBigInteger('last_collection_run_id')->nullable();
        $table->unsignedBigInteger('last_dataset_run_id')->nullable();
        $table->timestampTz('first_collected_at');
        $table->timestampTz('last_collected_at');
        $table->text('source_timezone')->nullable();
        $table->char('record_fingerprint', 64);
        $table->json('metadata')->nullable();
        $table->timestamps();
    }

    private function indexName(string $table, string $suffix): string
    {
        return substr($table, 0, 48).'_'.$suffix;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'google_ads_ad_group_daily',
            'google_ads_ad_daily',
            'google_ads_device_daily',
            'google_ads_hour_daily',
            'google_ads_network_daily',
            'google_ads_user_location_daily',
            'google_ads_age_range_daily',
            'google_ads_gender_daily',
            'google_ads_campaign_audience_daily',
            'google_ads_ad_group_audience_daily',
            'google_ads_pmax_asset_daily',
            'google_ads_shopping_product_daily',
            'google_ads_video_daily',
            'google_ads_campaign_negative_keyword_snapshot',
            'google_ads_ad_group_negative_keyword_snapshot',
            'google_ads_bidding_strategy_snapshot',
            'google_ads_pmax_asset_group_snapshot',
            'google_ads_recommendation_snapshot',
            'google_ads_change_event',
        ];
    }
};
